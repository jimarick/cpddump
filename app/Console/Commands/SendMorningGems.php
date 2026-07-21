<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\MorningGem;
use Illuminate\Console\Command;

class SendMorningGems extends Command
{
    protected $signature = 'cpd:send-morning-gems';

    protected $description = 'Push one nugget from the current appraisal period to each opted-in user';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('onboarded_at')
            ->where('push_morning_gem_enabled', true)
            ->whereHas('pushTokens')
            ->eachById(function (User $user) use (&$sent) {
                $gem = $this->pickGem($user);

                // Nothing recorded yet earns silence, not filler.
                if ($gem === null) {
                    return;
                }

                $user->notify(new MorningGem(...$gem));
                $sent++;
            });

        $this->info("Queued {$sent} gem(s).");

        return self::SUCCESS;
    }

    /**
     * A recency-weighted draw over the period's open nuggets, seeded per
     * user per day — re-running the command the same morning picks the
     * same gem, so a double-fired schedule can't double-notify differently.
     *
     * @return array{activityId: int, nuggetId: string, text: string, activityTitle: string}|null
     */
    private function pickGem(User $user): ?array
    {
        $period = $user->currentAppraisalPeriod();

        if (! $period) {
            return null;
        }

        $candidates = $user->activities()
            ->where('appraisal_period_id', $period->id)
            ->whereNotNull('nuggets')
            ->get()
            ->flatMap(fn ($activity) => collect($activity->openNuggets())->map(fn ($nugget) => [
                'activityId' => $activity->id,
                'nuggetId' => $nugget['id'],
                'text' => $nugget['text'],
                'activityTitle' => $activity->title,
                'weight' => 1 / (1 + ($activity->starts_on ?? $activity->created_at)->diffInDays(now(), true) / 14),
            ]))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        mt_srand(crc32($user->id.'|'.now('Europe/London')->toDateString()));

        $total = $candidates->sum('weight');
        $draw = mt_rand() / mt_getrandmax() * $total;

        foreach ($candidates as $candidate) {
            $draw -= $candidate['weight'];

            if ($draw <= 0) {
                unset($candidate['weight']);

                return $candidate;
            }
        }

        $last = $candidates->last();
        unset($last['weight']);

        return $last;
    }
}
