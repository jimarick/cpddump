<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\FrameworkAttribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $profession = $user->profession;
        $period = $user->currentAppraisalPeriod();

        $activities = $user->activities()
            ->when($period, fn ($q) => $q->where('appraisal_period_id', $period->id))
            ->with(['type:id,slug,name,color,icon', 'categories:id,slug,name', 'frameworkDomains:id,code,name', 'projects:id,title', 'attachments:id,attachable_type,attachable_id,original_filename,mime_type'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => $this->serialise($a));

        return Inertia::render('activities/index', [
            'activities' => $activities,
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'reference' => [
                'activityTypes' => ActivityType::availableTo($profession)->get(['id', 'slug', 'name', 'color', 'icon']),
                'categories' => $profession?->categories()->get(['id', 'slug', 'name']) ?? [],
                'domains' => $profession?->frameworkDomains()->with('frameworkAttributes:id,framework_domain_id,code,name')->get(['id', 'code', 'name']) ?? [],
                'reflectionPrompts' => $profession?->reflectionPrompts() ?? [],
                'projects' => $user->projects()->get(['id', 'title', 'kind']),
            ],
        ]);
    }

    public function update(Request $request, Activity $activity): RedirectResponse
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

        return back()->with('success', 'Activity updated.');
    }

    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->delete();

        return back()->with('success', 'Activity deleted.');
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
            'attachments' => $a->attachments->map(fn ($att) => [
                'id' => $att->id,
                'name' => $att->original_filename,
                'mime_type' => $att->mime_type,
            ])->all(),
        ];
    }
}
