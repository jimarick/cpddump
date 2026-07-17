<?php

namespace App\Jobs;

use App\Enums\EvidenceSource;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\EvidenceIngestor;
use App\Services\ResendInbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    private const MAX_ATTACHMENT_BYTES = 26_214_400; // 25 MB

    /** @param array<string, string> $meta */
    public function __construct(
        public int $userId,
        public string $emailId,
        public array $meta = [],
    ) {}

    public function handle(ResendInbound $resend, EvidenceIngestor $ingestor): void
    {
        $user = User::find($this->userId);

        if (! $user || $this->emailId === '') {
            return;
        }

        $email = $resend->email($this->emailId);

        $body = filled($email['text'] ?? null)
            ? (string) $email['text']
            : $this->htmlToText((string) ($email['html'] ?? ''));

        $item = $ingestor->ingest(
            user: $user,
            source: EvidenceSource::Email,
            rawPayload: [
                'subject' => $email['subject'] ?? $this->meta['subject'] ?? '',
                'from' => $email['from'] ?? $this->meta['from'] ?? '',
                'body' => Str::limit($body, 30_000, ''),
                'message_id' => $this->meta['message_id'] ?? null,
                'received_at' => $email['created_at'] ?? now()->toIso8601String(),
            ],
            externalId: $this->emailId,
            dispatch: false,
        );

        // Matched an ignore rule, or this email was already processed.
        if (! $item) {
            return;
        }

        if ($item->attachments()->doesntExist()) {
            foreach ($resend->attachments($this->emailId) as $attachment) {
                $this->storeAttachment($item, $resend, $attachment);
            }
        }

        $ingestor->dispatchPipeline($item);
    }

    /** @param array<string, mixed> $attachment */
    private function storeAttachment(InboxItem $item, ResendInbound $resend, array $attachment): void
    {
        $downloadUrl = $attachment['download_url'] ?? null;
        $size = (int) ($attachment['size'] ?? 0);

        if (! $downloadUrl || $size > self::MAX_ATTACHMENT_BYTES) {
            return;
        }

        $contents = $resend->download((string) $downloadUrl);
        $filename = (string) ($attachment['filename'] ?? 'attachment');
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
        $disk = config('filesystems.default');
        $path = "evidence/{$item->user_id}/".Str::uuid().'.'.$extension;

        Storage::disk($disk)->put($path, $contents);

        $item->attachments()->create([
            'user_id' => $item->user_id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $filename,
            'mime_type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
            'size' => $size ?: strlen($contents),
        ]);
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html));
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }
}
