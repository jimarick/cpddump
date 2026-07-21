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

/**
 * The month's learning in one email: what you learned (nuggets) and what
 * you said you'd chase (actions), grouped by activity. Never sent empty.
 */
class MonthlyLearningDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{title: string, nuggets: array<int, array{id: string, text: string}>, actions: array<int, array{id: string, text: string}>}>  $groups
     */
    public function __construct(
        public User $user,
        public string $monthLabel,
        public array $groups,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->monthLabel} in learning",
            replyTo: [new Address(config('cpd.contact_email'))],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monthly-learning-digest', with: [
            'month_label' => $this->monthLabel,
            'groups' => $this->groups,
            'nugget_count' => collect($this->groups)->sum(fn ($g) => count($g['nuggets'])),
            'action_count' => collect($this->groups)->sum(fn ($g) => count($g['actions'])),
            'takeaways_url' => route('takeaways'),
            'mark_done_url' => $this->markDoneUrl(),
            'unsubscribe_url' => $this->unsubscribeUrl(),
        ]);
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

    /** Marks exactly the takeaways this email showed as done, in one click. */
    private function markDoneUrl(): string
    {
        $ids = collect($this->groups)
            ->flatMap(fn ($g) => [...collect($g['nuggets'])->pluck('id'), ...collect($g['actions'])->pluck('id')])
            ->implode(',');

        return URL::signedRoute('email.takeaways.done', ['user' => $this->user->id, 'ids' => $ids]);
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $this->user->id, 'type' => 'monthly']);
    }
}
