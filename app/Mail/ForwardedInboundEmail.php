<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Relays mail sent to a human alias (hello@) on the receiving domain. */
class ForwardedInboundEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<int, array{name: string, mime: string, contents: string}> $forwardedAttachments */
    public function __construct(
        public string $originalSubject,
        public string $originalFrom,
        public string $body,
        public array $forwardedAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Fwd: '.($this->originalSubject ?: '(no subject)'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.forwarded-inbound', with: [
            'originalFrom' => $this->originalFrom,
            'body' => $this->body,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return collect($this->forwardedAttachments)
            ->map(fn (array $a) => Attachment::fromData(fn () => $a['contents'], $a['name'])
                ->withMime($a['mime']))
            ->all();
    }
}
