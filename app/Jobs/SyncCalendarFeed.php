<?php

namespace App\Jobs;

use App\Enums\CalendarFeedStatus;
use App\Models\CalendarFeed;
use App\Services\CalendarFeedSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCalendarFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60];

    public function __construct(public CalendarFeed $feed) {}

    public function handle(CalendarFeedSync $sync): void
    {
        $feed = $this->feed->fresh();

        if (! $feed || $feed->status === CalendarFeedStatus::Disabled) {
            return;
        }

        $sync->sync($feed);
    }

    public function failed(?Throwable $exception): void
    {
        // CalendarFeedSync already recorded the error; nothing further.
    }
}
