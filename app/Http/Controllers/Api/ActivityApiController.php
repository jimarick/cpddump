<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\FrameworkAttribute;
use App\Services\PidScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approved activities for the companion app's timeline: list, detail,
 * and (since the merge feature brought editing to the app) update.
 */
class ActivityApiController extends Controller
{
    /**
     * Post-approval remedy for the user who notices personal information
     * on their phone: purge stored files to stubs, scrub identifiers from
     * the text, keep the clean entry — API parity with the web remedy.
     */
    public function removePii(Request $request, Activity $activity, PidScanner $scanner): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->attachments()->whereNull('purged_at')->get()->each(function ($attachment) {
            $attachment->purgeToStub();
            $attachment->update(['extracted_text' => null]);
        });

        $scrub = fn (?string $text) => $text === null ? null : $scanner->scrubNhsNumbers($text)['text'];

        $scrubList = fn (?array $items) => collect($items ?? [])
            ->map(fn (array $item) => [...$item, 'text' => $scrub($item['text'] ?? '') ?? ''])
            ->all();

        $activity->update([
            'title' => $scrub($activity->title),
            'details' => $scrub($activity->details),
            'source_notes' => $scrub($activity->source_notes),
            'nuggets' => $scrubList($activity->nuggets),
            'actions' => $scrubList($activity->actions),
            'reflection' => collect($activity->reflection ?? [])
                ->map(fn (string $answer) => $scrub($answer))
                ->all(),
        ]);

        return $this->show($request, $activity->refresh());
    }

    /** Same validation and normalisation as the web activity edit. */
    public function update(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'activity_type_slug' => ['required', 'string'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'organisation' => ['nullable', 'string', 'max:255'],
            'cpd_points' => ['required', 'numeric', 'min:0', 'max:999'],
            'details' => ['nullable', 'string', 'max:20000'],
            'source_notes' => ['nullable', 'string', 'max:50000'],
            'reflection' => ['nullable', 'array'],
            'reflection.*' => ['nullable', 'string', 'max:20000'],
            'category_slugs' => ['nullable', 'array'],
            'domain_codes' => ['nullable', 'array'],
            'attribute_codes' => ['nullable', 'array'],
            'project_ids' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $profession = $user->profession;

        $type = ActivityType::availableTo($profession)->where('slug', $validated['activity_type_slug'])->firstOrFail();
        $reflectionKeys = collect($profession->reflectionPrompts())->pluck('key')->all();

        $activity->update([
            'activity_type_id' => $type->id,
            'title' => $validated['title'],
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'organisation' => $validated['organisation'] ?? null,
            'cpd_points' => $validated['cpd_points'],
            'details' => $validated['details'] ?? null,
            'reflection' => collect((array) ($validated['reflection'] ?? []))->only($reflectionKeys)->all(),
            // Nuggets/actions deliberately absent: per-item edits go through
            // the takeaways endpoints, so a stale edit form can't wipe them.
            ...(array_key_exists('source_notes', $validated) ? ['source_notes' => $validated['source_notes']] : []),
        ]);

        $activity->categories()->sync(
            $profession->categories()->whereIn('slug', $validated['category_slugs'] ?? [])->pluck('id')
        );
        $activity->frameworkDomains()->sync(
            $profession->frameworkDomains()->whereIn('code', $validated['domain_codes'] ?? [])->pluck('id')
        );
        $activity->frameworkAttributes()->sync(
            FrameworkAttribute::query()
                ->whereIn('code', $validated['attribute_codes'] ?? [])
                ->whereHas('domain', fn ($q) => $q->where('profession_id', $profession->id))
                ->pluck('framework_attributes.id')
        );
        $activity->projects()->sync(
            $user->projects()->whereIn('id', $validated['project_ids'] ?? [])->pluck('id')
        );

        return $this->show($request, $activity->refresh());
    }

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
            ->withCount('mergedChildren')
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
                'merged' => $a->merged_children_count > 0,
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

        $activity->load(['type:id,slug,name,color,icon', 'categories:id,slug,name', 'frameworkDomains:id,code,name', 'projects:id,title', 'attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at', 'mergedChildren:id,merged_into_activity_id,title,starts_on,cpd_points', 'mergedChildren.attachments:id,attachable_type,attachable_id,original_filename,mime_type,purged_at']);

        $serialiseAttachment = fn ($a, ?string $from = null) => [
            'id' => $a->id,
            'name' => $a->original_filename,
            'mime_type' => $a->mime_type,
            'purged' => $a->isPurged(),
        ] + ($from !== null ? ['from' => $from] : [])
            + ($a->isPurged() ? [] : ['url' => "/api/v1/attachments/{$a->id}"]);

        return response()->json(['activity' => [
            'id' => $activity->id,
            'title' => $activity->title,
            'starts_on' => $activity->starts_on?->toDateString(),
            'ends_on' => $activity->ends_on?->toDateString(),
            'cpd_points' => (float) $activity->cpd_points,
            'organisation' => $activity->organisation,
            'details' => $activity->details,
            'reflection' => $activity->reflection,
            'nuggets' => $activity->nuggets ?? [],
            'actions' => $activity->actions ?? [],
            'source_notes' => $activity->source_notes,
            'type' => $activity->type->only(['slug', 'name', 'color', 'icon']),
            'categories' => $activity->categories->map->only(['slug', 'name'])->all(),
            'domains' => $activity->frameworkDomains->map->only(['code', 'name'])->all(),
            'projects' => $activity->projects->map->only(['id', 'title'])->all(),
            // Union of the entry's own files and its absorbed children's —
            // files never move on merge, so the parent shows both.
            'attachments' => $activity->attachments->map(fn ($a) => $serialiseAttachment($a))
                ->concat($activity->mergedChildren->flatMap(
                    fn (Activity $child) => $child->attachments->map(fn ($a) => $serialiseAttachment($a, $child->title))
                ))->values()->all(),
            'merged_from' => $activity->mergedChildren->map(fn (Activity $child) => [
                'id' => $child->id,
                'title' => $child->title,
                'starts_on' => $child->starts_on?->toDateString(),
                'cpd_points' => (float) $child->cpd_points,
            ])->all(),
            'formerly_merged' => $activity->unmerged_at !== null,
            'merge_unreviewed' => (bool) $activity->merge_unreviewed,
        ]]);
    }
}
