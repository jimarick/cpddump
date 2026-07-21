<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference data (cached client-side for the Categorise step) and the
 * headline stats banner for the companion app.
 */
class ReferenceApiController extends Controller
{
    public function reference(Request $request): JsonResponse
    {
        $user = $request->user();
        $profession = $user->profession;

        return response()->json([
            'activity_types' => ActivityType::availableTo($profession)->get(['id', 'slug', 'name', 'color', 'icon']),
            'categories' => $profession?->categories()->get(['id', 'slug', 'name']) ?? [],
            'domains' => $profession?->frameworkDomains()->with('frameworkAttributes:id,framework_domain_id,code,name')->get(['id', 'code', 'name']) ?? [],
            'reflection_prompts' => $profession?->reflectionPrompts() ?? [],
            'projects' => $user->projects()->where('status', 'open')->get(['id', 'title', 'kind']),
            'periods' => $user->appraisalPeriods()->get(['id', 'label', 'starts_on', 'ends_on', 'is_current']),
        ]);
    }

    public function stats(Request $request, StatsService $stats): JsonResponse
    {
        $validated = $request->validate(['period' => ['nullable', 'integer']]);

        $user = $request->user();
        $period = filled($validated['period'] ?? null)
            ? $user->appraisalPeriods()->find((int) $validated['period'])
            : $user->currentAppraisalPeriod();

        return response()->json([
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'stats' => $stats->forPeriod($user, $period),
        ]);
    }
}
