<?php

namespace App\Services;

use App\Ai\MergeDraftAgent;
use App\Enums\AiPurpose;
use App\Enums\InboxItemStatus;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\FrameworkAttribute;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Combining evidence into one portfolio entry, reversibly. Sources become
 * hidden children of a new (or existing) parent activity; un-merge nulls
 * the pointer and the originals come back exactly as they were. Ready
 * inbox items join a merge through the ordinary approve() promotion, so
 * retention, redaction and recurrence tallies all behave as usual.
 */
class ActivityMerger
{
    public function __construct(private AiGateway $ai) {}

    /**
     * The AI-drafted combined entry for the merge modal: title, type,
     * organisation, one details paragraph spanning every source, and one
     * woven answer per reflection prompt. Called on demand — the modal
     * opens on deterministic defaults and upgrades when this lands.
     *
     * @param  array<int, int>  $activityIds
     * @param  array<int, int>  $inboxItemIds
     * @return array<string, mixed>
     */
    public function combineDraft(User $user, array $activityIds, array $inboxItemIds, ?int $intoActivityId = null): array
    {
        [$activities, $items, $target] = $this->resolveSources($user, $activityIds, $inboxItemIds, $intoActivityId);

        $prompts = collect($user->profession?->reflectionPrompts() ?? []);

        $describe = function (string $title, ?string $date, ?string $type, ?string $organisation, ?string $summary, array $answers, bool $userWritten) use ($prompts): string {
            $head = collect([
                $date,
                $type,
                $organisation,
                $userWritten ? 'reviewed and written by the user' : 'AI draft',
            ])->filter()->implode(' · ');

            $lines = collect(["Source: {$title} ({$head})"]);

            if (filled($summary)) {
                $lines->push("  summary: {$summary}");
            }

            foreach ($prompts as $prompt) {
                if (filled($answers[$prompt['key']] ?? null)) {
                    $lines->push("  {$prompt['key']}: ".$answers[$prompt['key']]);
                }
            }

            return $lines->implode("\n");
        };

        $sources = $activities->collect()
            ->when($target !== null, fn ($all) => $all->prepend($target))
            ->map(fn (Activity $a) => $describe(
                $a->title,
                $a->starts_on?->toDateString(),
                $a->type->name,
                $a->organisation,
                $a->details,
                $a->reflection ?? [],
                true,
            ))
            ->concat($items->map(fn (InboxItem $i) => $describe(
                $i->ai_analysis['title'] ?? 'Untitled evidence',
                $i->ai_analysis['starts_on'] ?? null,
                $i->ai_analysis['activity_type_slug'] ?? null,
                $i->ai_analysis['organisation'] ?? null,
                $i->ai_analysis['summary'] ?? null,
                (array) ($i->ai_analysis['reflection_draft'] ?? []),
                false,
            )));

        $response = $this->ai->structuredPrompt(
            agent: new MergeDraftAgent(
                $user->profession->name ?? 'healthcare professional',
                $prompts->all(),
                ActivityType::availableTo($user->profession)->pluck('slug')->all(),
            ),
            user: $user,
            purpose: AiPurpose::MergeReflection,
            prompt: $sources->implode("\n\n"),
        );

        $draft = $response->toArray();

        return [
            'title' => is_string($draft['title'] ?? null) && $draft['title'] !== '' ? $draft['title'] : null,
            'activity_type_slug' => is_string($draft['activity_type_slug'] ?? null) && $draft['activity_type_slug'] !== ''
                ? $draft['activity_type_slug']
                : null,
            'organisation' => is_string($draft['organisation'] ?? null) && $draft['organisation'] !== ''
                ? $draft['organisation']
                : null,
            'details' => is_string($draft['details'] ?? null) && $draft['details'] !== '' ? $draft['details'] : null,
            'reflection' => collect((array) ($draft['reflection'] ?? []))
                ->only($prompts->pluck('key'))
                ->filter(fn ($answer) => is_string($answer) && $answer !== '')
                ->all(),
        ];
    }

    /**
     * Deterministic preview for the merge modal: source summaries, combined
     * defaults, and which items' PII gates still block. No AI here — the
     * modal must open instantly.
     *
     * @param  array<int, int>  $activityIds
     * @param  array<int, int>  $inboxItemIds
     * @return array<string, mixed>
     */
    public function preview(User $user, array $activityIds, array $inboxItemIds, ?int $intoActivityId = null): array
    {
        [$activities, $items, $target] = $this->resolveSources($user, $activityIds, $inboxItemIds, $intoActivityId);

        $sources = $this->serialiseSources($activities, $items, $target);

        return [
            'defaults' => $this->defaults($user, $activities, $items, $target),
            'sources' => $sources,
            'blocking' => [
                'pii_item_ids' => $items->filter->piiGateActive()->pluck('id')->values()->all(),
            ],
            // The modal only asks "keep this file?" for users on "ask".
            'retention' => $user->attachment_retention ?? 'ask',
        ];
    }

    /**
     * What could this user merge right now? Activities from the given (or
     * current) period, plus Ready inbox items when the period is current —
     * the picker's menu on both web and app.
     *
     * @return array<string, mixed>
     */
    public function candidates(User $user, ?int $periodId = null): array
    {
        $period = $periodId !== null
            ? $user->appraisalPeriods()->find($periodId)
            : $user->currentAppraisalPeriod();

        $activities = $period === null ? collect() : $user->activities()
            ->where('appraisal_period_id', $period->id)
            ->with('type:id,slug,name,color')
            ->withCount('mergedChildren')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'starts_on' => $a->starts_on?->toDateString(),
                'cpd_points' => (float) $a->cpd_points,
                'type' => ['name' => $a->type->name, 'color' => $a->type->color],
                'merged' => $a->merged_children_count > 0,
            ]);

        $items = ($period === null || ! $period->is_current) ? collect() : $user->inboxItems()
            ->where('status', InboxItemStatus::Ready)
            ->latest()
            ->get()
            ->map(fn (InboxItem $i) => [
                'id' => $i->id,
                'title' => $i->ai_analysis['title']
                    ?? $i->raw_payload['title']
                    ?? $i->raw_payload['subject']
                    ?? 'Untitled evidence',
                'starts_on' => $i->ai_analysis['starts_on'] ?? null,
                'cpd_points' => (float) ($i->ai_analysis['cpd_points'] ?? 0),
                'source_label' => $i->source->label(),
            ]);

        return [
            'activities' => $activities->values()->all(),
            'inbox_items' => $items->values()->all(),
        ];
    }

    /**
     * Perform the merge. The payload is the user-edited combined entry from
     * the modal (MergeActivitiesRequest shape).
     *
     * @param  array<string, mixed>  $payload
     */
    public function merge(User $user, array $payload): Activity
    {
        [$activities, $items, $target] = $this->resolveSources(
            $user,
            array_map('intval', $payload['activity_ids'] ?? []),
            array_map('intval', $payload['inbox_item_ids'] ?? []),
            isset($payload['into_activity_id']) ? (int) $payload['into_activity_id'] : null,
        );

        $this->assertPiiResolved($items, array_map('intval', $payload['pii_acks'] ?? []));

        return DB::transaction(function () use ($user, $payload, $activities, $items, $target) {
            $parent = $target !== null
                ? $this->applyPayload($user, $target, $payload)
                : $this->createParent($user, $payload, $activities);

            $keepIds = array_map('intval', $payload['keep_attachment_ids'] ?? []);
            $acks = array_map('intval', $payload['pii_acks'] ?? []);

            foreach ($items as $item) {
                if ($item->piiGateActive() && in_array($item->id, $acks, true)) {
                    $item->recordPiiResolution('affirmed');
                }

                $child = $item->approve($this->itemPayload($item, $parent, $keepIds));

                $child->update([
                    'merged_into_activity_id' => $parent->id,
                    'merged_at' => now(),
                    'merge_unreviewed' => true,
                ]);
            }

            foreach ($activities as $activity) {
                $activity->update([
                    'merged_into_activity_id' => $parent->id,
                    'merged_at' => now(),
                ]);
            }

            return $parent;
        });
    }

    /**
     * Release every absorbed child and delete the parent shell. Children
     * come back untouched — they kept their fields, pivots, attachments and
     * inbox back-links the whole time. All-or-nothing by design.
     *
     * @return Collection<int, Activity> the released activities
     */
    public function unmerge(Activity $parent): Collection
    {
        $children = $parent->mergedChildren()->get();

        throw_if($children->isEmpty(), ValidationException::withMessages([
            'activity' => ['This entry is not a merged entry.'],
        ]));

        return DB::transaction(function () use ($parent, $children) {
            foreach ($children as $child) {
                $child->update([
                    'merged_into_activity_id' => null,
                    'merged_at' => null,
                    'unmerged_at' => now(),
                ]);
            }

            // The hook's child-cascade finds nothing to delete now; the
            // shell owns no files or inbox rows of its own.
            $parent->refresh()->delete();

            return $children;
        });
    }

    /**
     * Load and validate the merge sources. Throws ValidationException on
     * anything unmergeable so web and API surface identical errors.
     *
     * @param  array<int, int>  $activityIds
     * @param  array<int, int>  $inboxItemIds
     * @return array{0: Collection<int, Activity>, 1: Collection<int, InboxItem>, 2: Activity|null}
     */
    private function resolveSources(User $user, array $activityIds, array $inboxItemIds, ?int $intoActivityId): array
    {
        $activityIds = array_values(array_unique($activityIds));
        $inboxItemIds = array_values(array_unique($inboxItemIds));

        if ($intoActivityId !== null) {
            $activityIds = array_values(array_diff($activityIds, [$intoActivityId]));
        }

        $activities = $user->activities()
            ->whereIn('id', $activityIds)
            ->with('attachments')
            ->get();

        if ($activities->count() !== count($activityIds)) {
            throw ValidationException::withMessages([
                'activity_ids' => ['One or more selected activities are unavailable.'],
            ]);
        }

        if ($activities->contains(fn (Activity $a) => $a->isMergedParent())) {
            throw ValidationException::withMessages([
                'activity_ids' => ['A merged entry cannot be merged again — split it apart first.'],
            ]);
        }

        $items = $user->inboxItems()
            ->whereIn('id', $inboxItemIds)
            ->with('attachments')
            ->get();

        if ($items->count() !== count($inboxItemIds)) {
            throw ValidationException::withMessages([
                'inbox_item_ids' => ['One or more selected inbox items are unavailable.'],
            ]);
        }

        if ($items->contains(fn (InboxItem $i) => $i->status !== InboxItemStatus::Ready)) {
            throw ValidationException::withMessages([
                'inbox_item_ids' => ['Only items the AI has finished reading can be merged.'],
            ]);
        }

        $target = null;

        if ($intoActivityId !== null) {
            $target = $user->activities()->find($intoActivityId);

            throw_if($target === null, ValidationException::withMessages([
                'into_activity_id' => ['That activity is unavailable.'],
            ]));
        }

        if ($activities->count() + $items->count() < ($target !== null ? 1 : 2)) {
            throw ValidationException::withMessages([
                'activity_ids' => ['Pick at least two things to merge.'],
            ]);
        }

        $this->assertOnePeriod($user, $activities, $items, $target);

        return [$activities, $items, $target];
    }

    /**
     * Merges stay inside one appraisal year: mixed-period entries would
     * corrupt per-period points, and inbox items always promote into the
     * current period.
     *
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int, InboxItem>  $items
     */
    private function assertOnePeriod(User $user, Collection $activities, Collection $items, ?Activity $target): void
    {
        $periodIds = $activities->pluck('appraisal_period_id')
            ->when($target !== null, fn ($ids) => $ids->push($target->appraisal_period_id))
            ->unique();

        if ($periodIds->count() > 1) {
            throw ValidationException::withMessages([
                'activity_ids' => ['Everything merged must belong to the same appraisal year.'],
            ]);
        }

        if ($items->isNotEmpty()) {
            $current = $user->currentAppraisalPeriod();

            if ($current === null || ($periodIds->isNotEmpty() && (int) $periodIds->first() !== $current->id)) {
                throw ValidationException::withMessages([
                    'inbox_item_ids' => ['Inbox items can only merge into the current appraisal year.'],
                ]);
            }
        }
    }

    /**
     * Every PII-gated item needs an explicit decision inside the merge
     * flow: an ack in the payload, or a prior "remove patient info".
     *
     * @param  Collection<int, InboxItem>  $items
     * @param  array<int, int>  $acks
     */
    private function assertPiiResolved(Collection $items, array $acks): void
    {
        $blocking = $items
            ->filter(fn (InboxItem $item) => $item->piiGateActive() && ! in_array($item->id, $acks, true))
            ->pluck('id');

        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pii' => ['Possible sensitive information was found — remove it, or confirm you have checked it, before merging.'],
                'pii_item_ids' => $blocking->map(fn (int $id) => (string) $id)->all(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, Activity>  $activities
     */
    private function createParent(User $user, array $payload, Collection $activities): Activity
    {
        $period = $activities->isNotEmpty()
            ? $activities->first()->appraisal_period_id
            : $user->currentAppraisalPeriod()?->id;

        throw_if($period === null, ValidationException::withMessages([
            'activity_ids' => ['No current appraisal year to merge into.'],
        ]));

        $parent = new Activity(['appraisal_period_id' => $period]);
        $parent->user_id = $user->id;

        return $this->applyPayload($user, $parent, $payload);
    }

    /**
     * Write the combined-entry fields and pivots onto the parent — the
     * same normalisation as an ordinary activity edit.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyPayload(User $user, Activity $parent, array $payload): Activity
    {
        $profession = $user->profession;

        $type = ActivityType::availableTo($profession)
            ->where('slug', $payload['activity_type_slug'])
            ->firstOrFail();

        $reflectionKeys = collect($profession->reflectionPrompts())->pluck('key')->all();

        $parent->fill([
            'activity_type_id' => $type->id,
            'title' => $payload['title'],
            'starts_on' => $payload['starts_on'] ?? null,
            'ends_on' => $payload['ends_on'] ?? null,
            'organisation' => $payload['organisation'] ?? null,
            'cpd_points' => $payload['cpd_points'] ?? 0,
            'details' => $payload['details'] ?? null,
            'reflection' => collect((array) ($payload['reflection'] ?? []))
                ->only($reflectionKeys)
                ->filter(fn ($answer) => filled($answer))
                ->all(),
        ])->save();

        $parent->categories()->sync(
            $profession->categories()->whereIn('slug', $payload['category_slugs'] ?? [])->pluck('id')
        );
        $parent->frameworkDomains()->sync(
            $profession->frameworkDomains()->whereIn('code', $payload['domain_codes'] ?? [])->pluck('id')
        );
        $parent->frameworkAttributes()->sync(
            FrameworkAttribute::query()
                ->whereIn('code', $payload['attribute_codes'] ?? [])
                ->whereHas('domain', fn ($q) => $q->where('profession_id', $profession->id))
                ->pluck('framework_attributes.id')
        );
        $parent->projects()->sync(
            $user->projects()->whereIn('id', $payload['project_ids'] ?? [])->pluck('id')
        );

        return $parent;
    }

    /**
     * The promotion payload for a Ready item joining a merge: its own AI
     * draft, untouched — the user's editing happens once, on the parent.
     * The flat keep list works because applyRetention treats it as a
     * membership check against each item's own attachments.
     *
     * @param  array<int, int>  $keepIds
     * @return array<string, mixed>
     */
    private function itemPayload(InboxItem $item, Activity $parent, array $keepIds): array
    {
        $analysis = $item->ai_analysis ?? [];

        return [
            'title' => $analysis['title']
                ?? $item->raw_payload['title']
                ?? $item->raw_payload['subject']
                ?? 'Untitled evidence',
            'activity_type_slug' => $analysis['activity_type_slug'] ?? $parent->type->slug,
            'starts_on' => $analysis['starts_on'] ?? null,
            'ends_on' => $analysis['ends_on'] ?? null,
            'organisation' => $analysis['organisation'] ?? null,
            'cpd_points' => $analysis['cpd_points'] ?? 0,
            'summary' => $analysis['summary'] ?? null,
            'reflection_draft' => $analysis['reflection_draft'] ?? [],
            'category_slugs' => $analysis['category_slugs'] ?? [],
            'domain_codes' => $analysis['domain_codes'] ?? [],
            'attribute_codes' => $analysis['attribute_codes'] ?? [],
            'project_ids' => $analysis['suggested_project_ids'] ?? [],
            'keep_attachment_ids' => $keepIds,
        ];
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int, InboxItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function serialiseSources(Collection $activities, Collection $items, ?Activity $target): array
    {
        $activitySources = $activities->collect()
            ->when($target !== null, fn ($all) => $all->prepend($target->load('attachments')))
            ->map(fn (Activity $a) => [
                'kind' => 'activity',
                'id' => $a->id,
                'title' => $a->title,
                'starts_on' => $a->starts_on?->toDateString(),
                'cpd_points' => (float) $a->cpd_points,
                'source' => null,
                'pii_gate' => false,
                'is_target' => $target !== null && $a->id === $target->id,
                // Approved entries' files already had their retention moment —
                // shown for context only, never re-questioned.
                'attachments' => $a->attachments->map(fn ($att) => [
                    'id' => $att->id,
                    'name' => $att->original_filename,
                    'purged' => $att->isPurged(),
                    'keepable' => false,
                ])->all(),
            ]);

        $itemSources = $items->map(fn (InboxItem $item) => [
            'kind' => 'inbox_item',
            'id' => $item->id,
            'title' => $item->ai_analysis['title']
                ?? $item->raw_payload['title']
                ?? $item->raw_payload['subject']
                ?? 'Untitled evidence',
            'starts_on' => $item->ai_analysis['starts_on'] ?? null,
            'cpd_points' => (float) ($item->ai_analysis['cpd_points'] ?? 0),
            'source' => $item->source->value,
            'pii_gate' => $item->piiGateActive(),
            'is_target' => false,
            'pii_flags' => collect((array) ($item->ai_warnings['pii_flags'] ?? []))
                ->map(fn ($flag) => is_array($flag) ? collect($flag)->only(['type', 'severity'])->all() : $flag)
                ->all(),
            'attachments' => $item->attachments->map(fn ($att) => [
                'id' => $att->id,
                'name' => $att->original_filename,
                'purged' => $att->isPurged(),
                'keepable' => ! $att->isPurged(),
            ])->all(),
        ]);

        return $activitySources->concat($itemSources)->values()->all();
    }

    /**
     * Combined-entry starting values: sums, spans and unions. Reflections
     * are a naive per-key concatenation (target's saved answers first) —
     * the AI combine endpoint refines them asynchronously.
     *
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int, InboxItem>  $items
     * @return array<string, mixed>
     */
    private function defaults(User $user, Collection $activities, Collection $items, ?Activity $target): array
    {
        $all = $activities->collect()->when($target !== null, fn ($a) => $a->prepend($target));

        $titles = $all->pluck('title')
            ->concat($items->map(fn ($i) => $i->ai_analysis['title'] ?? null))
            ->filter();

        $starts = $all->map(fn ($a) => $a->starts_on?->toDateString())
            ->concat($items->map(fn ($i) => $i->ai_analysis['starts_on'] ?? null))
            ->filter()->sort();

        $ends = $all->map(fn ($a) => $a->ends_on?->toDateString())
            ->concat($items->map(fn ($i) => $i->ai_analysis['ends_on'] ?? null))
            ->concat($starts)
            ->filter()->sort();

        $points = (float) $all->sum(fn ($a) => (float) $a->cpd_points)
            + (float) $items->sum(fn ($i) => (float) ($i->ai_analysis['cpd_points'] ?? 0));

        $typeSlugs = $all->map(fn ($a) => $a->type->slug)
            ->concat($items->map(fn ($i) => $i->ai_analysis['activity_type_slug'] ?? null))
            ->filter();

        $reflectionKeys = collect($user->profession?->reflectionPrompts() ?? [])->pluck('key');

        $reflection = $reflectionKeys->mapWithKeys(function (string $key) use ($all, $items) {
            $answers = $all->map(fn ($a) => $a->reflection[$key] ?? null)
                ->concat($items->map(fn ($i) => $i->ai_analysis['reflection_draft'][$key] ?? null))
                ->filter()
                ->unique();

            return [$key => $answers->implode("\n\n")];
        })->filter(fn ($answer) => $answer !== '')->all();

        return [
            'title' => $target->title ?? $titles->first() ?? 'Untitled evidence',
            'activity_type_slug' => $target?->type->slug ?? $typeSlugs->countBy()->sortDesc()->keys()->first(),
            'starts_on' => $starts->first(),
            'ends_on' => $ends->last(),
            'cpd_points' => round($points, 2),
            'points_breakdown' => $all->map(fn ($a) => (float) $a->cpd_points)
                ->concat($items->map(fn ($i) => (float) ($i->ai_analysis['cpd_points'] ?? 0)))
                ->values()->all(),
            'organisation' => $all->pluck('organisation')
                ->concat($items->map(fn ($i) => $i->ai_analysis['organisation'] ?? null))
                ->filter()->first(),
            'details' => $all->pluck('details')
                ->concat($items->map(fn ($i) => $i->ai_analysis['summary'] ?? null))
                ->filter()->unique()->implode("\n\n"),
            'reflection' => $reflection,
            'category_slugs' => $all->flatMap(fn ($a) => $a->categories->pluck('slug'))
                ->concat($items->flatMap(fn ($i) => (array) ($i->ai_analysis['category_slugs'] ?? [])))
                ->unique()->values()->all(),
            'domain_codes' => $all->flatMap(fn ($a) => $a->frameworkDomains->pluck('code'))
                ->concat($items->flatMap(fn ($i) => (array) ($i->ai_analysis['domain_codes'] ?? [])))
                ->unique()->values()->all(),
            'attribute_codes' => $all->flatMap(fn ($a) => $a->frameworkAttributes->pluck('code'))
                ->concat($items->flatMap(fn ($i) => (array) ($i->ai_analysis['attribute_codes'] ?? [])))
                ->unique()->values()->all(),
            'project_ids' => $all->flatMap(fn ($a) => $a->projects->pluck('id'))
                ->concat($items->flatMap(fn ($i) => (array) ($i->ai_analysis['suggested_project_ids'] ?? [])))
                ->map(fn ($id) => (int) $id)
                ->unique()->values()->all(),
        ];
    }
}
