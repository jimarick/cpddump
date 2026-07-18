<?php

namespace App\Console\Commands;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Mail\WeeklyReview;
use App\Models\User;
use App\Services\StatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyReviews extends Command
{
    protected $signature = 'cpd:send-weekly-reviews';

    protected $description = 'Send the weekly review email to every opted-in user';

    public function handle(StatsService $stats): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('onboarded_at')
            ->where('weekly_email_enabled', true)
            ->eachById(function (User $user) use ($stats, &$sent) {
                $period = $user->currentAppraisalPeriod();
                $periodStats = $stats->forPeriod($user, $period);

                $capturedThisWeek = $user->inboxItems()->where('created_at', '>=', now()->subWeek())->count();
                $pointsThisWeek = (float) $user->inboxItems()
                    ->where('created_at', '>=', now()->subWeek())
                    ->whereIn('status', [InboxItemStatus::Ready, InboxItemStatus::Approved])
                    ->get()
                    ->sum(fn ($item) => (float) ($item->ai_analysis['cpd_points'] ?? 0));

                // Nothing new and nothing waiting: stay out of their inbox.
                if ($capturedThisWeek === 0 && $periodStats['awaiting'] === 0 && $periodStats['activities'] === 0) {
                    return;
                }

                $thinAreas = collect((array) ($periodStats['gaps']['domains'] ?? []))
                    ->filter(fn ($d) => $d['count'] === 0)
                    ->map(fn ($d) => str_replace('D', 'Domain ', $d['code']))
                    ->values()
                    ->all();

                $regularsWaiting = $user->inboxItems()
                    ->where('source', EvidenceSource::Recurring)
                    ->where('status', InboxItemStatus::Ready)
                    ->whereIn('recurrence_id', $user->recurrences()->whereIn('reminder', ['weekly', 'same_day'])->pluck('id'))
                    ->count();

                $behindExpectations = collect((array) ($periodStats['gaps']['expectations'] ?? []))
                    ->filter(fn ($e) => $e['captured'] < $e['expected'])
                    ->map(fn ($e) => "{$e['title']} ({$e['captured']} of {$e['expected']})")
                    ->values()
                    ->all();

                Mail::to($user)->queue(new WeeklyReview($user, [
                    'captured_this_week' => $capturedThisWeek,
                    'points_this_week' => $pointsThisWeek,
                    'awaiting' => $periodStats['awaiting'],
                    'total_activities' => $periodStats['activities'],
                    'total_points' => $periodStats['points'],
                    'thin_areas' => $thinAreas,
                    'regulars_waiting' => $regularsWaiting,
                    'behind_expectations' => $behindExpectations,
                    'dump_address' => $user->inboundEmailAddress(),
                    'inbox_url' => route('inbox'),
                ]));

                $sent++;
            });

        $this->info("Queued {$sent} weekly review emails.");

        return self::SUCCESS;
    }
}
