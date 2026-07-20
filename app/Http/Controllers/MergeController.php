<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeActivitiesRequest;
use App\Models\Activity;
use App\Services\ActivityMerger;
use App\Services\AiGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class MergeController extends Controller
{
    /** The AI-drafted combined entry for the modal — costs tokens, so on demand. */
    public function draft(Request $request, ActivityMerger $merger, AiGateway $ai): JsonResponse
    {
        $validated = $request->validate([
            'activity_ids' => ['nullable', 'array'],
            'activity_ids.*' => ['integer'],
            'inbox_item_ids' => ['nullable', 'array'],
            'inbox_item_ids.*' => ['integer'],
            'into_activity_id' => ['nullable', 'integer'],
        ]);

        if ($ai->overDailyBudget($request->user())) {
            return response()->json([
                'message' => 'Daily AI allowance reached — try again tomorrow, or add your own API key in Settings.',
            ], 429);
        }

        try {
            $draft = $merger->combineDraft(
                $request->user(),
                array_map('intval', $validated['activity_ids'] ?? []),
                array_map('intval', $validated['inbox_item_ids'] ?? []),
                isset($validated['into_activity_id']) ? (int) $validated['into_activity_id'] : null,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The AI could not combine those just now. Try again.',
            ], 422);
        }

        return response()->json(['draft' => $draft]);
    }

    /** What the "Merge with…" picker can offer. */
    public function candidates(Request $request, ActivityMerger $merger): JsonResponse
    {
        $validated = $request->validate(['period_id' => ['nullable', 'integer']]);

        return response()->json($merger->candidates(
            $request->user(),
            isset($validated['period_id']) ? (int) $validated['period_id'] : null,
        ));
    }

    /** Deterministic merge-modal seed — JSON side-channel, not an Inertia visit. */
    public function preview(Request $request, ActivityMerger $merger): JsonResponse
    {
        $validated = $request->validate([
            'activity_ids' => ['nullable', 'array'],
            'activity_ids.*' => ['integer'],
            'inbox_item_ids' => ['nullable', 'array'],
            'inbox_item_ids.*' => ['integer'],
            'into_activity_id' => ['nullable', 'integer'],
        ]);

        return response()->json($merger->preview(
            $request->user(),
            array_map('intval', $validated['activity_ids'] ?? []),
            array_map('intval', $validated['inbox_item_ids'] ?? []),
            isset($validated['into_activity_id']) ? (int) $validated['into_activity_id'] : null,
        ));
    }

    public function store(MergeActivitiesRequest $request, ActivityMerger $merger): RedirectResponse
    {
        $activity = $merger->merge($request->user(), $request->validated());

        return back()
            ->with('success', "Merged into “{$activity->title}”.")
            ->with('merged_activity_id', $activity->id);
    }

    public function unmerge(Request $request, Activity $activity, ActivityMerger $merger): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        $released = $merger->unmerge($activity);

        return back()->with('success', "Split back into {$released->count()} activities.");
    }
}
