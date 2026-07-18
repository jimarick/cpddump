<?php

namespace App\Mail;

use App\Models\InboxItem;
use App\Models\Recurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecurrenceReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Recurrence $recurrence,
        public InboxItem $item,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->recurrence->isScheduled()
                ? "{$this->recurrence->title} — reflect while it's fresh"
                : "Did a {$this->recurrence->title} happen recently?",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.recurrence-reminder', with: [
            'title' => $this->recurrence->title,
            'scheduled' => $this->recurrence->isScheduled(),
            'inboxUrl' => route('inbox'),
        ]);
    }
}
