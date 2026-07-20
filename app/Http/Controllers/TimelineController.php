<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Attachment;
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

        $profession = $user->profession;

        $activities = $period
            ? $user->activities()
                ->where('appraisal_period_id', $period->id)
                ->with(['type:id,slug,name,color,icon', 'categories:id,slug,name', 'frameworkDomains:id,code,name', 'projects:id,title', 'attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at', 'mergedChildren:id,merged_into_activity_id,title,starts_on,cpd_points', 'mergedChildren.attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at'])
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Activity $a) => $this->serialise($a))
            : collect();

        return Inertia::render('timeline', [
            'activities' => $activities,
            'periods' => $periods,
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on', 'is_current']),
            'stats' => $stats->forPeriod($user, $period),
            'legend' => $activities
                ->filter(fn ($a) => $a['starts_on'] !== null)
                ->pluck('type')
                ->unique('slug')
                ->values(),
            'reference' => [
                'activityTypes' => ActivityType::availableTo($profession)->get(['id', 'slug', 'name', 'color', 'icon']),
                'categories' => $profession?->categories()->get(['id', 'slug', 'name']) ?? [],
                'domains' => $profession?->frameworkDomains()->with('frameworkAttributes:id,framework_domain_id,code,name')->get(['id', 'code', 'name']) ?? [],
                'reflectionPrompts' => $profession?->reflectionPrompts() ?? [],
                'projects' => $user->projects()->get(['id', 'title', 'kind']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialise(Activity $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'starts_on' => $a->starts_on?->toDateString(),
            'ends_on' => $a->ends_on?->toDateString(),
            'cpd_points' => (float) $a->cpd_points,
            'organisation' => $a->organisation,
            'details' => $a->details,
            'reflection' => $a->reflection,
            'type' => $a->type->only(['slug', 'name', 'color', 'icon']),
            'categories' => $a->categories->map->only(['slug', 'name'])->all(),
            'domains' => $a->frameworkDomains->map->only(['code', 'name'])->all(),
            'attribute_codes' => $a->frameworkAttributes()->pluck('code')->all(),
            'projects' => $a->projects->map->only(['id', 'title'])->all(),
            // A merged parent shows the union of its own files and its
            // absorbed children's — files never move on merge.
            'attachments' => $a->attachments->map(fn ($att) => $this->attachment($att))
                ->concat($a->mergedChildren->flatMap(
                    fn (Activity $child) => $child->attachments->map(fn ($att) => $this->attachment($att, $child->title))
                ))->values()->all(),
            'merged_from' => $a->mergedChildren->map(fn (Activity $child) => [
                'id' => $child->id,
                'title' => $child->title,
                'starts_on' => $child->starts_on?->toDateString(),
                'cpd_points' => (float) $child->cpd_points,
            ])->all(),
            'formerly_merged' => $a->unmerged_at !== null,
            'merge_unreviewed' => (bool) $a->merge_unreviewed,
        ];
    }

    /** @return array<string, mixed> */
    private function attachment(Attachment $att, ?string $from = null): array
    {
        return [
            'id' => $att->id,
            'name' => $att->original_filename,
            'mime_type' => $att->mime_type,
            'purged' => $att->isPurged(),
        ] + ($from !== null ? ['from' => $from] : []);
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
