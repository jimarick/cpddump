<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\FetchLinkContent;
use App\Jobs\TranscribeVoiceNote;
use App\Models\DismissedCalendarEvent;
use App\Models\InboxItem;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;

/**
 * The single funnel every evidence source flows through. Adapters (manual
 * entry, uploads, links, inbound email, calendar sync, voice notes) build
 * a raw payload and call ingest(); everything downstream is uniform.
 */
class EvidenceIngestor
{
    public function __construct(private AttachmentStore $attachments) {}

    /**
     * @param  array<string, mixed>  $rawPayload  Source-specific truth, stored immutably.
     * @param  array<int, UploadedFile>  $files
     */
    public function ingest(
        User $user,
        EvidenceSource $source,
        array $rawPayload,
        array $files = [],
        ?string $externalId = null,
        bool $dispatch = true,
    ): ?InboxItem {
        if ($this->shouldIgnore($user, $source, $rawPayload)) {
            return null;
        }

        if ($externalId !== null) {
            $existing = $user->inboxItems()
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->first();

            if ($existing) {
                return $existing;
            }

            // A binned calendar event stays binned — its UID is remembered
            // even though the item row itself is long gone.
            if ($source === EvidenceSource::Calendar && DismissedCalendarEvent::query()
                ->where('user_id', $user->id)
                ->where('uid', $externalId)
                ->exists()) {
                return null;
            }
        }

        $overDailyCap = $user->inboxItems()
            ->where('created_at', '>=', now()->startOfDay())
            ->count() >= (int) config('cpd.ingest.daily_item_cap');

        $item = $user->inboxItems()->create([
            'source' => $source,
            'status' => InboxItemStatus::Pending,
            'raw_payload' => $rawPayload,
            'content_hash' => $this->contentHash($rawPayload),
            'external_id' => $externalId,
            'failure_reason' => $overDailyCap
                ? 'Daily dump limit reached — this is safely stored, and analysis resumes tomorrow.'
                : null,
        ]);

        foreach ($files as $file) {
            $this->storeAttachment($item, $file);
        }

        if ($files !== []) {
            $this->refreshContentHash($item);
        }

        if ($dispatch) {
            $this->dispatchPipeline($item, $overDailyCap ? now()->startOfDay()->addDay()->addMinutes(10) : null);
        }

        return $item;
    }

    /** Queue the right preparation job, ending in AI analysis. */
    public function dispatchPipeline(InboxItem $item, ?DateTimeInterface $delay = null): void
    {
        $attachments = $item->attachments()->get();

        if ($item->source === EvidenceSource::Link || $item->source === EvidenceSource::Article) {
            FetchLinkContent::dispatch($item)->delay($delay);

            return;
        }

        // Any audio — a voice note or an mp3/wav that arrived another way —
        // is transcribed first; the transcript then re-enters this pipeline.
        if (blank($item->raw_payload['transcript'] ?? null) && $attachments->contains(fn ($a) => $a->isAudio())) {
            TranscribeVoiceNote::dispatch($item)->delay($delay);

            return;
        }

        $needsText = $attachments->contains(
            fn ($a) => $a->isExtractable() && blank($a->extracted_text) && ! $a->isPurged()
        );

        if ($needsText) {
            ExtractAttachmentText::dispatch($item)->delay($delay);

            return;
        }

        AnalyzeInboxItem::dispatch($item)->delay($delay);
    }

    /** @param array<string, mixed> $rawPayload */
    private function shouldIgnore(User $user, EvidenceSource $source, array $rawPayload): bool
    {
        $fields = [
            'title' => $rawPayload['title'] ?? $rawPayload['subject'] ?? null,
            'organiser' => $rawPayload['organiser'] ?? null,
            'sender' => $rawPayload['from'] ?? null,
            'sender_domain' => isset($rawPayload['from']) && str_contains((string) $rawPayload['from'], '@')
                ? str((string) $rawPayload['from'])->afterLast('@')->toString()
                : null,
        ];

        return $user->ignoreRules()
            ->where('is_active', true)
            ->get()
            ->contains(function ($rule) use ($source, $fields) {
                if ($rule->matches($source, $fields)) {
                    $rule->increment('hit_count');

                    return true;
                }

                return false;
            });
    }

    private function storeAttachment(InboxItem $item, UploadedFile $file): void
    {
        $contents = $file->get();

        if ($contents === false) {
            return;
        }

        $this->attachments->store(
            item: $item,
            contents: $contents,
            originalFilename: $file->getClientOriginalName(),
            extension: strtolower($file->getClientOriginalExtension()) ?: (string) $file->guessExtension(),
            fallbackMime: $file->getMimeType() ?? 'application/octet-stream',
        );
    }

    /**
     * Recompute an item's content hash from its payload AND attachment
     * fingerprints. Must be called whenever content arrives after
     * creation (stored files, a transcript, fetched link text) —
     * otherwise items with empty initial payloads all share one hash and
     * the analysis cache cross-contaminates them.
     */
    public function refreshContentHash(InboxItem $item): void
    {
        $fingerprints = $item->attachments()
            ->get()
            ->map(fn ($a) => $a->source_fingerprint ?? "{$a->original_filename}:{$a->size}")
            ->sort()
            ->values()
            ->all();

        $item->update(['content_hash' => $this->contentHash($item->raw_payload, $fingerprints)]);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<int, string>  $fingerprints
     */
    private function contentHash(array $rawPayload, array $fingerprints = []): string
    {
        $normalised = collect($rawPayload)
            ->except(['received_at', 'message_id'])
            ->map(fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : $v)
            ->sortKeys()
            ->toJson();

        return hash('sha256', $normalised.'|'.implode('|', $fingerprints));
    }
}
