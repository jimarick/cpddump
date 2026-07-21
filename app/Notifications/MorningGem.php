<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * One nugget a day, with your coffee. Tapping opens the source activity;
 * the MORNING_GEM category carries a "Got it" action that marks the
 * nugget done so it stops being resurfaced.
 */
class MorningGem extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $activityId,
        public string $nuggetId,
        public string $text,
        public string $activityTitle,
    ) {}

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
                    'title' => 'Morning gem',
                    'body' => Str::limit($this->text, 170).' — from '.Str::limit($this->activityTitle, 60),
                ],
                'sound' => 'default',
                'category' => 'MORNING_GEM',
            ],
            'activity_id' => $this->activityId,
            'nugget_id' => $this->nuggetId,
        ];
    }
}
