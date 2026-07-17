<?php

namespace App\Services;

use App\Enums\InboxItemStatus;
use App\Models\AppraisalPeriod;
use App\Models\User;

/**
 * Headline numbers and gap analysis for a user's appraisal period —
 * the "Domain 2 looking thin" intelligence.
 */
class StatsService
{
    /** @return array<string, mixed> */
    public function forPeriod(User $user, ?AppraisalPeriod $period): array
    {
        $activities = $period
            ? $user->activities()->where('appraisal_period_id', $period->id)
            : null;

        return [
            'activities' => $activities?->clone()->count() ?? 0,
            'points' => (float) ($activities?->clone()->sum('cpd_points') ?? 0),
            'awaiting' => $user->inboxItems()->whereIn('status', [InboxItemStatus::Ready, InboxItemStatus::Failed])->count(),
            'gaps' => $period ? $this->gaps($user, $period) : ['categories' => [], 'domains' => []],
        ];
    }

    /**
     * Categories and framework domains with no evidence yet this period.
     *
     * @return array{categories: array<int, array{slug: string, name: string, count: int}>, domains: array<int, array{code: string, name: string, count: int}>}
     */
    public function gaps(User $user, AppraisalPeriod $period): array
    {
        $profession = $user->profession;

        if (! $profession) {
            return ['categories' => [], 'domains' => []];
        }

        $categoryCounts = $profession->categories()
            ->get()
            ->map(function ($category) use ($user, $period) {
                $count = $user->activities()
                    ->where('appraisal_period_id', $period->id)
                    ->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
                    ->count();

                return ['slug' => $category->slug, 'name' => $category->name, 'count' => $count];
            });

        $domainCounts = $profession->frameworkDomains()
            ->get()
            ->map(function ($domain) use ($user, $period) {
                $count = $user->activities()
                    ->where('appraisal_period_id', $period->id)
                    ->whereHas('frameworkDomains', fn ($q) => $q->where('framework_domains.id', $domain->id))
                    ->count();

                return ['code' => $domain->code, 'name' => $domain->name, 'count' => $count];
            });

        return [
            'categories' => $categoryCounts->all(),
            'domains' => $domainCounts->all(),
        ];
    }
}
