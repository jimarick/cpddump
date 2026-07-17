<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-review', with: $this->summary);
    }
}
