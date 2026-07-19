<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklyNudge;
use Illuminate\Console\Command;

class SendPushNudges extends Command
{
    protected $signature = 'cpd:send-push-nudges';

    protected $description = 'Push the weekly backlog nudge to opted-in users with items awaiting review';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('onboarded_at')
            ->where('push_weekly_nudge_enabled', true)
            ->whereHas('pushTokens')
            ->eachById(function (User $user) use (&$sent) {
                $awaiting = $user->awaitingReviewCount();

                // An empty tray earns silence, not congratulations.
                if ($awaiting === 0) {
                    return;
                }

                $user->notify(new WeeklyNudge($awaiting));
                $sent++;
            });

        $this->info("Queued {$sent} nudge(s).");

        return self::SUCCESS;
    }
}
