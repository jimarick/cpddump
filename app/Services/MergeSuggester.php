<?php

namespace App\Services;

use App\Enums\InboxItemStatus;
use App\Models\Activity;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns the analyst's raw id lists (duplicates, related items, recurrence
 * matches) into ready-to-render merge suggestions for Ready inbox items:
 * titled, current-period-only, batched into two queries per page load.
 * Nothing is persisted and nothing is remembered — a suggestion simply
 * appears whenever a match exists, and ignoring it costs nothing.
 */
class MergeSuggester
{
    private const MAX_PER_ITEM = 3;

    /**
     * @param  Collection<int, InboxItem>  $items
     * @return array<int, array<int, array<string, mixed>>> suggestions keyed by item id
     */
    public function forItems(User $user, Collection $items): array
    {
        $period = $user->currentAppraisalPeriod();

        if ($period === null) {
            return [];
        }

        $ready = $items->filter(fn (InboxItem $i) => $i->status === InboxItemStatus::Ready);

        if ($ready->isEmpty()) {
            return [];
        }

        $activityIds = $ready->flatMap(fn (InboxItem $i) => [
            ...(array) ($i->ai_warnings['possible_duplicate_activity_ids'] ?? []),
            ...(array) ($i->ai_warnings['possible_related_activity_ids'] ?? []),
        ])->map(fn ($id) => (int) $id)->unique();

        $recurrenceIds = $ready
            ->map(fn (InboxItem $i) => $i->recurrence_id ?? $i->ai_analysis['matched_recurrence_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $relatedItemIds = $ready->flatMap(fn (InboxItem $i) => [
            ...(array) ($i->ai_warnings['possible_related_inbox_item_ids'] ?? []),
            ...(array) ($i->ai_warnings['possible_duplicate_inbox_item_ids'] ?? []),
        ])->map(fn ($id) => (int) $id)->unique();

        // Visible activities this period that anything referenced — either
        // directly by id, or as the home of a matched recurrence (a merged
        // parent qualifies through its absorbed children).
        $activities = $activityIds->isEmpty() && $recurrenceIds->isEmpty()
            ? collect()
            : $user->activities()
                ->where('appraisal_period_id', $period->id)
                ->where(function ($query) use ($activityIds, $recurrenceIds) {
                    $query->whereIn('id', $activityIds->all())
                        ->orWhereIn('recurrence_id', $recurrenceIds->all())
                        ->orWhereHas('mergedChildren', fn ($children) => $children->whereIn('recurrence_id', $recurrenceIds->all()));
                })
                ->withCount('mergedChildren')
                ->orderByDesc('starts_on')
                ->get(['id', 'title', 'recurrence_id', 'starts_on', 'appraisal_period_id', 'user_id']);

        $recurrenceTargets = $recurrenceIds->mapWithKeys(function (int $recurrenceId) use ($activities) {
            $target = $activities->first(function (Activity $a) use ($recurrenceId) {
                return (int) $a->recurrence_id === $recurrenceId
                    || $a->mergedChildren()->where('recurrence_id', $recurrenceId)->exists();
            });

            return [$recurrenceId => $target];
        });

        $openItems = $relatedItemIds->isEmpty()
            ? collect()
            : $user->inboxItems()
                ->whereIn('id', $relatedItemIds->all())
                ->where('status', InboxItemStatus::Ready)
                ->get()
                ->keyBy('id');

        return $ready->mapWithKeys(function (InboxItem $item) use ($activities, $recurrenceTargets, $openItems) {
            $suggestions = collect();

            $recurrenceId = $item->recurrence_id ?? $item->ai_analysis['matched_recurrence_id'] ?? null;
            $recurrenceTarget = $recurrenceId !== null ? $recurrenceTargets->get((int) $recurrenceId) : null;

            if ($recurrenceTarget !== null) {
                $suggestions->push($this->activitySuggestion($recurrenceTarget, 'recurrence'));
            }

            foreach ((array) ($item->ai_warnings['possible_duplicate_activity_ids'] ?? []) as $id) {
                $activity = $activities->firstWhere('id', (int) $id);

                if ($activity !== null && $activity->id !== $recurrenceTarget?->id) {
                    $suggestions->push($this->activitySuggestion($activity, 'duplicate'));
                }
            }

            foreach ((array) ($item->ai_warnings['possible_related_activity_ids'] ?? []) as $id) {
                $activity = $activities->firstWhere('id', (int) $id);

                if ($activity !== null && $activity->id !== $recurrenceTarget?->id) {
                    $suggestions->push($this->activitySuggestion($activity, 'related'));
                }
            }

            $relatedIds = [
                ...(array) ($item->ai_warnings['possible_related_inbox_item_ids'] ?? []),
                ...(array) ($item->ai_warnings['possible_duplicate_inbox_item_ids'] ?? []),
            ];

            foreach ($relatedIds as $id) {
                $other = $openItems->get((int) $id);

                if ($other !== null && $other->id !== $item->id) {
                    $suggestions->push([
                        'kind' => 'inbox',
                        'id' => $other->id,
                        'title' => $other->ai_analysis['title']
                            ?? $other->raw_payload['title']
                            ?? $other->raw_payload['subject']
                            ?? 'Untitled evidence',
                        'merged' => false,
                        'reason' => 'related',
                    ]);
                }
            }

            return [$item->id => $suggestions
                ->unique(fn (array $s) => $s['kind'].'-'.$s['id'])
                ->take(self::MAX_PER_ITEM)
                ->values()
                ->all()];
        })->all();
    }

    /** @return array<string, mixed> */
    private function activitySuggestion(Activity $activity, string $reason): array
    {
        return [
            'kind' => 'activity',
            'id' => $activity->id,
            'title' => $activity->title,
            'merged' => $activity->merged_children_count > 0,
            'reason' => $reason,
        ];
    }
}
