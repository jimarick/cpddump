<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\FrameworkAttribute;
use App\Services\PidScanner;
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

        return back()->with('success', 'Activity updated.');
    }

    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $activity->delete();

        return back()->with('success', 'Activity deleted.');
    }

    /**
     * Post-approval remedy for the user who notices personal information
     * weeks later: purge stored files, scrub NHS numbers from the text,
     * keep the clean entry.
     */
    public function removePii(Request $request, Activity $activity, PidScanner $scanner): RedirectResponse
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

        return back()->with('success', 'Files removed and identifiers scrubbed — your entry is kept.');
    }
}
