<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TakeawayController;
use App\Models\Activity;
use App\Services\AiGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The companion app's Takeaways tab — same period-wide list and the same
 * id-addressed per-item mutations as the web page.
 */
class TakeawayApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->user()->currentAppraisalPeriod();

        return response()->json([
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'activities' => TakeawayController::activitiesFor($request),
        ]);
    }

    public function update(Request $request, Activity $activity, string $item): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        TakeawayController::applyMutation($request, $activity, $item);

        return $this->fresh($activity);
    }

    public function destroy(Request $request, Activity $activity, string $item): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);
        abort_unless($activity->removeTakeaway($item), 404);

        return $this->fresh($activity);
    }

    public function generate(Request $request, Activity $activity, AiGateway $ai): JsonResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        TakeawayController::extractTakeaways($request, $activity, $ai);

        return $this->fresh($activity);
    }

    private function fresh(Activity $activity): JsonResponse
    {
        $activity->refresh();

        return response()->json([
            'nuggets' => $activity->nuggets ?? [],
            'actions' => $activity->actions ?? [],
        ]);
    }
}
