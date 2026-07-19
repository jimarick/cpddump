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
 * Analysis finished: the drafted entry is waiting in the tray. Tapping
 * the push deep-links straight into the review sheet via inbox_item_id.
 */
class InboxItemReady extends Notification implements ShouldQueue
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
                    'title' => 'Filed and ready to review',
                    'body' => $this->body(),
                ],
                'badge' => $notifiable->awaitingReviewCount(),
                'sound' => 'default',
            ],
            'inbox_item_id' => $this->item->id,
        ];
    }

    /** The AI's draft title, plus its points estimate when it made one. */
    private function body(): string
    {
        $analysis = $this->item->ai_analysis ?? [];
        $payload = $this->item->raw_payload ?? [];

        $title = $analysis['title'] ?? $payload['title'] ?? $payload['subject'] ?? 'New entry';
        $points = (float) ($analysis['cpd_points'] ?? 0);

        if ($points <= 0) {
            return $title;
        }

        $formatted = rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');

        return "{$title} · {$formatted} CPD ".($points === 1.0 ? 'pt' : 'pts');
    }
}
