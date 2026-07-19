<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only approved activities for the companion app's timeline.
 * Editing stays on the web.
 */
class ActivityApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'period' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $period = filled($validated['period'] ?? null)
            ? $user->appraisalPeriods()->find((int) $validated['period'])
            : $user->currentAppraisalPeriod();

        $activities = $user->activities()
            ->when($period, fn ($q) => $q->where('appraisal_period_id', $period->id))
            ->when(filled($validated['type'] ?? null), fn ($q) => $q->whereHas('type', fn ($t) => $t->where('slug', $validated['type'])))
            ->with(['type:id,slug,name,color,icon', 'frameworkDomains:id,code', 'projects:id,title'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'activities' => collect($activities->items())->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'starts_on' => $a->starts_on?->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'cpd_points' => (float) $a->cpd_points,
                'organisation' => $a->organisation,
                'type' => $a->type->only(['slug', 'name', 'color', 'icon']),
                'domains' => $a->frameworkDomains->pluck('code')->all(),
                'projects' => $a->projects->pluck('title')->all(),
            ]),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->load(['type:id,slug,name,color,icon', 'categories:id,slug,name', 'frameworkDomains:id,code,name', 'projects:id,title', 'attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at']);

        return response()->json(['activity' => [
            'id' => $activity->id,
            'title' => $activity->title,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'cpd_points' => (float) $activity->cpd_points,
            'organisation' => $activity->organisation,
            'details' => $activity->details,
            'reflection' => $activity->reflection,
            'type' => $activity->type->only(['slug', 'name', 'color', 'icon']),
            'categories' => $activity->categories->map->only(['slug', 'name'])->all(),
            'domains' => $activity->frameworkDomains->map->only(['code', 'name'])->all(),
            'projects' => $activity->projects->map->only(['id', 'title'])->all(),
            'attachments' => $activity->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_filename,
                'mime_type' => $a->mime_type,
                'purged' => $a->isPurged(),
            ] + ($a->isPurged() ? [] : ['url' => "/api/v1/attachments/{$a->id}"]))->all(),
        ]]);
    }
}
