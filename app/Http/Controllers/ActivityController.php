<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\FrameworkAttribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
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
}
