<?php

namespace App\Jobs;

use App\Ai\InboxAnalystAgent;
use App\Enums\AiPurpose;
use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Models\InboxItem;
use App\Services\AiGateway;
use App\Services\PidScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $scannerFlags = app(PidScanner::class)->scan($this->allSourceText($item));

        if ($cached) {
            $item->update([
                'status' => InboxItemStatus::Ready,
                'ai_analysis' => $cached->ai_analysis,
                'ai_warnings' => array_merge($cached->ai_warnings ?? [], [
                    'possible_duplicate_inbox_item_ids' => [$cached->id],
                    'pii_flags' => $this->mergedFlags($cached->ai_warnings['pii_flags'] ?? [], $scannerFlags),
                ]),
                'analysed_at' => now(),
                'failure_reason' => null,
            ]);

            $item->scrubSourceText();

            return;
        }

        $item->update(['status' => InboxItemStatus::Analysing, 'failure_reason' => null]);

        $response = $ai->structuredPrompt(
            agent: InboxAnalystAgent::for($user, $item->id),
            user: $user,
            purpose: AiPurpose::InboxAnalysis,
            prompt: $this->buildEvidencePrompt($item),
            attachments: $this->buildAttachments($item),
            generatable: $item,
        );

        $analysis = $response->toArray();

        // Belt-and-braces: the model is told never to copy identifiers into
        // its draft, but an NHS number has no business in a reflection ever
        // — scrub any that slipped through, deterministically.
        $analysis = $this->scrubDraft($analysis);

        // The model returns nuggets/actions as plain strings; every client
        // and the Takeaways endpoints address them by id, so wrap here —
        // the one place all analyses flow through.
        $analysis = $this->wrapTakeaways($analysis);

        $item->update([
            'status' => InboxItemStatus::Ready,
            'ai_analysis' => $analysis,
            'ai_warnings' => [
                'pii_flags' => $this->mergedFlags($analysis['pii_flags'] ?? [], $scannerFlags),
                'missing_evidence' => $analysis['missing_evidence'] ?? [],
                'possible_duplicate_activity_ids' => $analysis['possible_duplicate_activity_ids'] ?? [],
                'possible_related_inbox_item_ids' => $analysis['possible_related_inbox_item_ids'] ?? [],
                'possible_related_activity_ids' => $analysis['possible_related_activity_ids'] ?? [],
                'related_reason' => $analysis['related_reason'] ?? null,
            ],
            'analysed_at' => now(),
            'failure_reason' => null,
        ]);

        // The drafted entry now carries the evidence — the raw source text
        // (email body, transcript, spreadsheet rows) has done its job.
        $item->scrubSourceText();

        $this->reconcileRecurrence($item, $analysis);
        $this->reciprocateRelations($item, $analysis);
    }

    /**
     * If this item names waiting inbox items as related, write this item's
     * id onto each of them too — both cards badge without a second AI call.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function reciprocateRelations(InboxItem $item, array $analysis): void
    {
        $relatedIds = array_map('intval', (array) ($analysis['possible_related_inbox_item_ids'] ?? []));

        if ($relatedIds === []) {
            return;
        }

        $item->user->inboxItems()
            ->whereIn('id', $relatedIds)
            ->where('status', InboxItemStatus::Ready)
            ->get()
            ->each(function (InboxItem $other) use ($item) {
                $warnings = $other->ai_warnings ?? [];
                $existing = array_map('intval', (array) ($warnings['possible_related_inbox_item_ids'] ?? []));

                if (in_array($item->id, $existing, true)) {
                    return;
                }

                $warnings['possible_related_inbox_item_ids'] = [...$existing, $item->id];
                $other->update(['ai_warnings' => $warnings]);
            });
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

    /** Everything textual we hold for this item, for the deterministic PID scan. */
    private function allSourceText(InboxItem $item): string
    {
        $payloadText = collect($item->raw_payload ?? [])
            ->filter(fn ($v) => is_string($v))
            ->implode("\n");

        $extracted = $item->attachments->pluck('extracted_text')->filter()->implode("\n");

        return $payloadText."\n".$extracted;
    }

    /**
     * Scanner findings join the analyst's, deduplicated on excerpt so the
     * same NHS number found twice reads as one flag.
     *
     * @param  array<int, array<string, mixed>>  $analystFlags
     * @param  array<int, array<string, mixed>>  $scannerFlags
     * @return array<int, array<string, mixed>>
     */
    private function mergedFlags(array $analystFlags, array $scannerFlags): array
    {
        return collect($analystFlags)
            ->concat($scannerFlags)
            ->unique(fn ($flag) => mb_strtolower(trim((string) ($flag['excerpt'] ?? ''))).'|'.($flag['type'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function scrubDraft(array $analysis): array
    {
        $scanner = app(PidScanner::class);

        foreach (['title', 'summary', 'organisation', 'user_notes'] as $field) {
            if (is_string($analysis[$field] ?? null)) {
                $analysis[$field] = $scanner->scrubNhsNumbers($analysis[$field])['text'];
            }
        }

        foreach ((array) ($analysis['reflection_draft'] ?? []) as $key => $value) {
            if (is_string($value)) {
                $analysis['reflection_draft'][$key] = $scanner->scrubNhsNumbers($value)['text'];
            }
        }

        foreach (['nuggets', 'actions'] as $list) {
            $analysis[$list] = array_map(
                fn ($text) => is_string($text) ? $scanner->scrubNhsNumbers($text)['text'] : $text,
                (array) ($analysis[$list] ?? []),
            );
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function wrapTakeaways(array $analysis): array
    {
        foreach (['nuggets', 'actions'] as $list) {
            $analysis[$list] = collect((array) ($analysis[$list] ?? []))
                ->filter(fn ($text) => is_string($text) && trim($text) !== '')
                ->map(fn ($text) => [
                    'id' => (string) Str::ulid(),
                    'text' => trim($text),
                    'done' => false,
                ])
                ->values()
                ->all();
        }

        return $analysis;
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
