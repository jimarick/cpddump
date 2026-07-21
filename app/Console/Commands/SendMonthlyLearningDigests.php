<?php

namespace App\Console\Commands;

use App\Mail\MonthlyLearningDigest;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendMonthlyLearningDigests extends Command
{
    protected $signature = 'cpd:send-monthly-learning-digests';

    protected $description = 'Email each opted-in user the nuggets and actions they recorded last month';

    public function handle(): int
    {
        $sent = 0;

        $start = now('Europe/London')->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $monthLabel = $start->format('F');

        User::query()
            ->whereNotNull('onboarded_at')
            ->where('monthly_digest_email_enabled', true)
            ->whereNull('email_suppressed_at')
            ->eachById(function (User $user) use (&$sent, $start, $end, $monthLabel) {
                $groups = $user->activities()
                    ->whereBetween('created_at', [$start, $end])
                    ->where(fn ($q) => $q->whereNotNull('nuggets')->orWhereNotNull('actions'))
                    ->orderByDesc('starts_on')
                    ->get()
                    ->map(fn (Activity $a) => [
                        'title' => $a->title,
                        'nuggets' => $a->openNuggets(),
                        'actions' => $a->openActions(),
                    ])
                    ->filter(fn ($g) => $g['nuggets'] !== [] || $g['actions'] !== [])
                    ->values()
                    ->all();

                // A month with nothing recorded earns silence.
                if ($groups === []) {
                    return;
                }

                Mail::to($user)->queue(new MonthlyLearningDigest($user, $monthLabel, $groups));
                $sent++;
            });

        $this->info("Queued {$sent} monthly digest(s).");

        return self::SUCCESS;
    }
}
