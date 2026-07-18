<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\FetchLinkContent;
use App\Jobs\TranscribeVoiceNote;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The single funnel every evidence source flows through. Adapters (manual
 * entry, uploads, links, inbound email, calendar sync, voice notes) build
 * a raw payload and call ingest(); everything downstream is uniform.
 */
class EvidenceIngestor
{
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
        }

        $item = $user->inboxItems()->create([
            'source' => $source,
            'status' => InboxItemStatus::Pending,
            'raw_payload' => $rawPayload,
            'content_hash' => $this->hash($rawPayload),
            'external_id' => $externalId,
        ]);

        foreach ($files as $file) {
            $this->storeAttachment($item, $file);
        }

        if ($dispatch) {
            $this->dispatchPipeline($item);
        }

        return $item;
    }

    /** Queue the right preparation job, ending in AI analysis. */
    public function dispatchPipeline(InboxItem $item): void
    {
        $needsText = $item->attachments()->where('mime_type', 'application/pdf')->whereNull('extracted_text')->exists();

        if ($item->source === EvidenceSource::Link || $item->source === EvidenceSource::Article) {
            FetchLinkContent::dispatch($item);

            return;
        }

        if ($item->source === EvidenceSource::VoiceNote && blank($item->raw_payload['transcript'] ?? null)) {
            TranscribeVoiceNote::dispatch($item);

            return;
        }

        if ($needsText) {
            ExtractAttachmentText::dispatch($item);

            return;
        }

        AnalyzeInboxItem::dispatch($item);
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
        $disk = config('filesystems.default');
        $path = $file->store("evidence/{$item->user_id}", $disk);

        $item->attachments()->create([
            'user_id' => $item->user_id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
        ]);
    }

    /** @param array<string, mixed> $rawPayload */
    private function hash(array $rawPayload): string
    {
        $normalised = collect($rawPayload)
            ->except(['received_at', 'message_id'])
            ->map(fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : $v)
            ->sortKeys()
            ->toJson();

        return hash('sha256', $normalised);
    }
}
