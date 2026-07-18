<?php

namespace App\Mail;

use App\Models\InboxItem;
use App\Models\Recurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

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
            replyTo: [new Address(config('cpd.contact_email'))],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.recurrence-reminder', with: [
            'title' => $this->recurrence->title,
            'scheduled' => $this->recurrence->isScheduled(),
            'inboxUrl' => route('inbox'),
            'unsubscribe_url' => $this->unsubscribeUrl(),
        ]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe', [
            'user' => $this->recurrence->user_id,
            'type' => 'reminders',
        ]);
    }
}
