<?php

namespace App\Services;

use App\Enums\InboxItemStatus;
use App\Models\AppraisalPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
            'gaps' => $period ? $this->gaps($user, $period) : ['categories' => [], 'domains' => [], 'expectations' => []],
        ];
    }

    /**
     * Categories and framework domains with no evidence yet this period,
     * plus progress against declared yearly expectations.
     *
     * @return array{categories: array<int, array{slug: string, name: string, count: int}>, domains: array<int, array{code: string, name: string, count: int}>, expectations: array<int, array{id: int, title: string, expected: int, captured: int}>}
     */
    public function gaps(User $user, AppraisalPeriod $period): array
    {
        $profession = $user->profession;

        if (! $profession) {
            return ['categories' => [], 'domains' => [], 'expectations' => []];
        }

        // One grouped query per pivot instead of a count query per row —
        // serverless Postgres charges real latency for every round-trip.
        // Raw joins bypass Eloquent's `unmerged` scope, so absorbed merge
        // children are excluded here by hand (their parent carries the
        // combined entry's categories/domains).
        $activityScope = fn ($join) => $join
            ->where('activities.user_id', $user->id)
            ->where('activities.appraisal_period_id', $period->id)
            ->whereNull('activities.merged_into_activity_id');

        $countsByCategory = DB::table('activity_category')
            ->join('activities', fn ($join) => $activityScope(
                $join->on('activities.id', '=', 'activity_category.activity_id')
            ))
            ->groupBy('activity_category.category_id')
            ->selectRaw('activity_category.category_id, count(*) as total')
            ->pluck('total', 'category_id');

        $countsByDomain = DB::table('activity_framework_domain')
            ->join('activities', fn ($join) => $activityScope(
                $join->on('activities.id', '=', 'activity_framework_domain.activity_id')
            ))
            ->groupBy('activity_framework_domain.framework_domain_id')
            ->selectRaw('activity_framework_domain.framework_domain_id, count(*) as total')
            ->pluck('total', 'framework_domain_id');

        $categoryCounts = $profession->categories()
            ->get()
            ->map(fn ($category) => [
                'slug' => $category->slug,
                'name' => $category->name,
                'count' => (int) ($countsByCategory[$category->id] ?? 0),
            ]);

        $domainCounts = $profession->frameworkDomains()
            ->get()
            ->map(fn ($domain) => [
                'code' => $domain->code,
                'name' => $domain->name,
                'count' => (int) ($countsByDomain[$domain->id] ?? 0),
            ]);

        $expectations = $user->recurrences()
            ->where('is_active', true)
            ->where('kind', 'expectation')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'expected' => (int) $r->expected_per_year,
                // Absorbed merge children still count: three audit meetings
                // combined into one entry remain three captures.
                'captured' => $r->activities()
                    ->withoutGlobalScope('unmerged')
                    ->where('appraisal_period_id', $period->id)
                    ->count(),
            ]);

        return [
            'categories' => $categoryCounts->all(),
            'domains' => $domainCounts->all(),
            'expectations' => $expectations->all(),
        ];
    }
}
