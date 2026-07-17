<?php

namespace App\Console\Commands;

use App\Enums\CalendarFeedStatus;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarFeed;
use Illuminate\Console\Command;

class SyncCalendars extends Command
{
    protected $signature = 'cpd:sync-calendars';

    protected $description = 'Queue a sync for every enabled calendar feed';

    public function handle(): int
    {
        $count = 0;

        CalendarFeed::query()
            ->where('status', '!=', CalendarFeedStatus::Disabled)
            ->eachById(function (CalendarFeed $feed) use (&$count) {
                SyncCalendarFeed::dispatch($feed);
                $count++;
            });

        $this->info("Queued {$count} calendar syncs.");

        return self::SUCCESS;
    }
}
