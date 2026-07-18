<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class WeeklyReview extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $summary */
    public function __construct(
        public User $user,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        $captured = $this->summary['captured_this_week'];

        return new Envelope(
            subject: $captured > 0
                ? "Your week in CPD: {$captured} new thing".($captured === 1 ? '' : 's').' captured'
                : 'Your CPD week — nothing dumped, nothing gained',
            replyTo: [new Address(config('cpd.contact_email'))],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-review', with: array_merge($this->summary, [
            'unsubscribe_url' => $this->unsubscribeUrl(),
        ]));
    }

    public function headers(): Headers
    {
        // RFC 8058 one-click unsubscribe — Microsoft-stack filters
        // (nhs.net included) expect this on recurring mail.
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $this->user->id, 'type' => 'weekly']);
    }
}
