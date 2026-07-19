<?php

namespace App\Notifications;

use App\Models\InboxItem;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis gave up on a capture. The push names what failed so the user
 * knows whether it matters, and deep-links to the item to retry or fill
 * in by hand.
 */
class InboxItemFailed extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public InboxItem $item) {}

    /** @return array<int, class-string> */
    public function via(User $notifiable): array
    {
        return [ApnsChannel::class];
    }

    /** @return array<string, mixed> */
    public function toApns(User $notifiable): array
    {
        return [
            'aps' => [
                'alert' => [
                    'title' => "Couldn't read that",
                    'body' => $this->body(),
                ],
                'badge' => $notifiable->awaitingReviewCount(),
                'sound' => 'default',
            ],
            'inbox_item_id' => $this->item->id,
        ];
    }

    private function body(): string
    {
        $payload = $this->item->raw_payload ?? [];
        $name = $payload['title'] ?? $payload['subject'] ?? null;
        $label = $this->item->source->label();

        return $name
            ? "\"{$name}\" ({$label}) — retry from the app, or fill it in yourself."
            : "Your {$label} capture — retry from the app, or fill it in yourself.";
    }
}
