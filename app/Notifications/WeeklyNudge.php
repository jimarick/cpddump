<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The Monday-evening backlog poke, strictly opt-in. No inbox_item_id —
 * it points at the pile, not one item.
 */
class WeeklyNudge extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $awaiting) {}

    /** @return array<int, class-string> */
    public function via(User $notifiable): array
    {
        return [ApnsChannel::class];
    }

    /** @return array<string, mixed> */
    public function toApns(User $notifiable): array
    {
        $things = Str::plural('thing', $this->awaiting);

        return [
            'aps' => [
                'alert' => [
                    'title' => "Don't let it pile up",
                    'body' => "{$this->awaiting} {$things} waiting for review — approve or bin, that's the job.",
                ],
                'badge' => $this->awaiting,
                'sound' => 'default',
            ],
        ];
    }
}
