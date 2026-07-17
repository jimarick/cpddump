<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\StatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function index(Request $request, StatsService $stats): Response
    {
        $user = $request->user();

        $periods = $user->appraisalPeriods()->get(['id', 'label', 'starts_on', 'ends_on', 'is_current']);

        $period = $request->filled('period')
            ? $periods->firstWhere('id', (int) $request->query('period'))
            : $periods->firstWhere('is_current', true);

        $activities = $period
            ? $user->activities()
                ->where('appraisal_period_id', $period->id)
                ->whereNotNull('starts_on')
                ->with(['type:id,slug,name,color,icon', 'frameworkDomains:id,code', 'projects:id,title'])
                ->orderBy('starts_on')
                ->get()
                ->map(fn (Activity $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'starts_on' => $a->starts_on->toDateString(),
                    'cpd_points' => (float) $a->cpd_points,
                    'organisation' => $a->organisation,
                    'type' => $a->type->only(['slug', 'name', 'color', 'icon']),
                    'domains' => $a->frameworkDomains->pluck('code')->all(),
                    'projects' => $a->projects->pluck('title')->all(),
                ])
            : collect();

        return Inertia::render('timeline', [
            'activities' => $activities,
            'periods' => $periods,
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on', 'is_current']),
            'stats' => $stats->forPeriod($user, $period),
            'legend' => $activities
                ->pluck('type')
                ->unique('slug')
                ->values(),
        ]);
    }

    /** Close the current window and open the next appraisal year. */
    public function reset(Request $request): RedirectResponse
    {
        $user = $request->user();
        $current = $user->currentAppraisalPeriod();

        abort_unless($current !== null, 422);

        $start = $current->ends_on->copy()->addDay();
        $end = $start->copy()->addYear()->subDay();

        $new = $user->appraisalPeriods()->create([
            'label' => $start->format('Y').'/'.$end->format('y'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'is_current' => false,
        ]);

        $new->makeCurrent();

        return back()->with('success', "New appraisal window started: {$new->label}. Old years are safe under the period switcher.");
    }
}
