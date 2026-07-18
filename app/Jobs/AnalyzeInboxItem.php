<?php

namespace App\Jobs;

use App\Ai\InboxAnalystAgent;
use App\Enums\AiPurpose;
use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Models\InboxItem;
use App\Services\AiGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Throwable;

class AnalyzeInboxItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public InboxItem $item) {}

    public function handle(AiGateway $ai): void
    {
        $item = $this->item->fresh(['user.profession', 'attachments']);

        if (! $item || $item->isResolved()) {
            return;
        }

        $user = $item->user;

        // Platform-key budget exhausted: hold until tomorrow rather than fail.
        if ($ai->overDailyBudget($user)) {
            $item->update([
                'status' => InboxItemStatus::Pending,
                'failure_reason' => 'Daily AI allowance reached — analysis resumes tomorrow, or add your own API key in Settings.',
            ]);

            $this->release(now()->startOfDay()->addDay()->addMinutes(5));

            return;
        }

        // Scanned PDFs are read page-by-page by the model, so a big one
        // can burn a day's input budget in one call. Gate on page count
        // for platform-key users.
        if ($user->ai_api_key === null && ($oversized = $this->oversizedScannedPdf($item))) {
            $item->update([
                'status' => InboxItemStatus::Failed,
                'failure_reason' => "\"{$oversized}\" is a scanned document too long to analyse automatically. Fill the details in manually, or attach a shorter version.",
            ]);

            return;
        }

        // Identical content already analysed for this user: reuse the analysis.
        $cached = $user->inboxItems()
            ->where('content_hash', $item->content_hash)
            ->where('id', '!=', $item->id)
            ->whereNotNull('ai_analysis')
            ->latest('analysed_at')
            ->first();

        if ($cached) {
            $item->update([
                'status' => InboxItemStatus::Ready,
                'ai_analysis' => $cached->ai_analysis,
                'ai_warnings' => array_merge($cached->ai_warnings ?? [], [
                    'possible_duplicate_inbox_item_ids' => [$cached->id],
                ]),
                'analysed_at' => now(),
                'failure_reason' => null,
            ]);

            return;
        }

        $item->update(['status' => InboxItemStatus::Analysing, 'failure_reason' => null]);

        $response = $ai->structuredPrompt(
            agent: InboxAnalystAgent::for($user),
            user: $user,
            purpose: AiPurpose::InboxAnalysis,
            prompt: $this->buildEvidencePrompt($item),
            attachments: $this->buildAttachments($item),
            generatable: $item,
        );

        $analysis = $response->toArray();

        $item->update([
            'status' => InboxItemStatus::Ready,
            'ai_analysis' => $analysis,
            'ai_warnings' => [
                'pii_flags' => $analysis['pii_flags'] ?? [],
                'missing_evidence' => $analysis['missing_evidence'] ?? [],
                'possible_duplicate_activity_ids' => $analysis['possible_duplicate_activity_ids'] ?? [],
            ],
            'analysed_at' => now(),
            'failure_reason' => null,
        ]);

        $this->reconcileRecurrence($item, $analysis);
    }

    /**
     * Real evidence tagged as an occurrence of a declared regular activity
     * counts toward its tally and supersedes any waiting template draft.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function reconcileRecurrence(InboxItem $item, array $analysis): void
    {
        $matchedId = $analysis['matched_recurrence_id'] ?? null;

        if (! $matchedId) {
            return;
        }

        $recurrence = $item->user->recurrences()->whereKey($matchedId)->first();

        if (! $recurrence) {
            return;
        }

        $item->update(['recurrence_id' => $recurrence->id]);

        // The real capture replaces any unresolved auto-draft.
        $recurrence->inboxItems()
            ->where('id', '!=', $item->id)
            ->where('source', EvidenceSource::Recurring)
            ->whereIn('status', [InboxItemStatus::Pending, InboxItemStatus::Ready])
            ->get()
            ->each
            ->dismiss();
    }

    public function failed(?Throwable $exception): void
    {
        $reason = $exception && str_contains(strtolower($exception->getMessage()), 'key')
            ? 'AI provider rejected the request — check your API key in Settings.'
            : 'Analysis failed. You can retry, or fill the details in manually.';

        $this->item->fresh()?->update([
            'status' => InboxItemStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    private function buildEvidencePrompt(InboxItem $item): string
    {
        $payload = collect($item->raw_payload)
            ->map(fn ($value, $key) => is_scalar($value) ? "{$key}: {$value}" : null)
            ->filter()
            ->implode("\n");

        $extracted = $item->attachments
            ->filter(fn ($a) => filled($a->extracted_text))
            ->map(fn ($a) => "--- Attachment: {$a->original_filename} ---\n{$a->extracted_text}")
            ->implode("\n\n");

        $text = trim("Source: {$item->source->value}\n\n{$payload}\n\n{$extracted}");
        $text = mb_substr($text, 0, config('cpd.ai.evidence_char_limit'));

        // Be honest about files the model gets no view of — never let a
        // draft read as if the slides were understood from their filename.
        $unread = $item->attachments
            ->filter(fn ($a) => $a->isUnreadable())
            ->map(fn ($a) => $a->original_filename);

        if ($unread->isNotEmpty()) {
            $text .= "\n\nAttached files whose contents could NOT be read (you only know their names): "
                .$unread->implode(', ')
                .'. Do not infer their contents. List each in missing_evidence as an unread attachment.';
        }

        return $text;
    }

    /**
     * The filename of the first image-only PDF whose page count exceeds
     * the configured gate, or null when everything is within bounds.
     */
    private function oversizedScannedPdf(InboxItem $item): ?string
    {
        $limit = (int) config('cpd.ai.max_scanned_pdf_pages');

        foreach ($item->attachments as $attachment) {
            if (! $attachment->isPdf() || filled($attachment->extracted_text)) {
                continue;
            }

            $contents = Storage::disk($attachment->disk)->get($attachment->path);

            if ($contents === null) {
                continue;
            }

            // Counting page objects beats fully parsing a document that
            // may be tens of MB; "/Type /Pages" nodes don't match.
            $pages = preg_match_all('#/Type\s*/Page\b#', $contents);

            if ($pages !== false && $pages > $limit) {
                return $attachment->original_filename;
            }
        }

        return null;
    }

    /**
     * Images (and PDFs without extracted text) go to the model directly.
     *
     * @return array<int, StoredImage|StoredDocument>
     */
    private function buildAttachments(InboxItem $item): array
    {
        return $item->attachments
            ->map(function ($a) {
                if ($a->isImage()) {
                    return Image::fromStorage($a->path, $a->disk);
                }

                if ($a->isPdf() && blank($a->extracted_text)) {
                    return Document::fromStorage($a->path, $a->disk);
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
