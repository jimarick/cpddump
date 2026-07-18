<?php

namespace App\Jobs;

use App\Enums\EvidenceSource;
use App\Mail\ForwardedInboundEmail;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\EvidenceIngestor;
use App\Services\SesObjectStore;
use App\Support\HtmlToText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

class ProcessSesInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    private const MAX_ATTACHMENT_BYTES = 26_214_400; // 25 MB

    /** @param array<int, string> $recipients */
    public function __construct(
        public string $bucket,
        public string $key,
        public array $recipients,
        public string $messageId,
    ) {}

    public function handle(SesObjectStore $store, EvidenceIngestor $ingestor): void
    {
        $raw = $store->get($this->bucket, $this->key);

        if ($raw === null) {
            return; // Already processed (or lifecycle-expired).
        }

        $parsed = (new MailMimeParser)->parse($raw, true);

        $body = trim((string) $parsed->getTextContent())
            ?: HtmlToText::convert((string) $parsed->getHtmlContent());

        $payload = [
            'subject' => trim((string) $parsed->getHeaderValue(HeaderConsts::SUBJECT)),
            'from' => trim((string) $parsed->getHeaderValue(HeaderConsts::FROM)),
            'body' => Str::limit($body, 30_000, ''),
            'message_id' => $this->messageId,
            'received_at' => now()->toIso8601String(),
        ];

        foreach ($this->recipients as $recipient) {
            $local = strtolower(Str::before($recipient, '@'));

            if (in_array($local, config('cpd.inbound_aliases'), true)) {
                $this->forwardAlias($parsed, $payload);

                continue;
            }

            $user = User::where('inbound_email_token', $local)->first();

            if (! $user) {
                continue; // Unknown recipient: drop silently, no bounce-leak.
            }

            $item = $ingestor->ingest(
                user: $user,
                source: EvidenceSource::Email,
                rawPayload: $payload,
                externalId: 'ses:'.$this->messageId,
                dispatch: false,
            );

            // Ignore rule matched, or this message was already ingested.
            if (! $item) {
                continue;
            }

            if ($item->wasRecentlyCreated) {
                foreach ($parsed->getAllAttachmentParts() as $part) {
                    $this->storeAttachment($item, $part);
                }

                $ingestor->refreshContentHash($item);
            }

            $ingestor->dispatchPipeline($item);
        }

        // Processed (or dropped) everywhere it needed to go: the raw email
        // does not outlive its ingestion.
        $store->delete($this->bucket, $this->key);
    }

    private function storeAttachment(InboxItem $item, IMessagePart $part): void
    {
        $filename = (string) ($part->getFilename() ?: 'attachment');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, config('cpd.ingest.allowed_extensions'), true)) {
            return;
        }

        $contents = (string) $part->getContent();

        if ($contents === '' || strlen($contents) > self::MAX_ATTACHMENT_BYTES) {
            return;
        }

        $disk = config('filesystems.default');
        $path = "evidence/{$item->user_id}/".Str::uuid().'.'.$extension;

        Storage::disk($disk)->put($path, $contents);

        $item->attachments()->create([
            'user_id' => $item->user_id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $filename,
            'mime_type' => (string) ($part->getContentType() ?: 'application/octet-stream'),
            'size' => strlen($contents),
        ]);
    }

    /**
     * Mail to a human alias (hello@) is relayed to the contact address —
     * it is not evidence and never enters the inbox pipeline.
     *
     * @param  IMessage  $parsed
     * @param  array<string, mixed>  $payload
     */
    private function forwardAlias($parsed, array $payload): void
    {
        $attachments = [];

        foreach ($parsed->getAllAttachmentParts() as $part) {
            $contents = (string) $part->getContent();

            if ($contents !== '' && strlen($contents) <= self::MAX_ATTACHMENT_BYTES) {
                $attachments[] = [
                    'name' => (string) ($part->getFilename() ?: 'attachment'),
                    'mime' => (string) ($part->getContentType() ?: 'application/octet-stream'),
                    'contents' => $contents,
                ];
            }
        }

        Mail::to(config('cpd.contact_email'))->queue(new ForwardedInboundEmail(
            originalSubject: (string) $payload['subject'],
            originalFrom: (string) $payload['from'],
            body: (string) $payload['body'],
            forwardedAttachments: $attachments,
        ));
    }
}
