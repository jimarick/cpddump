<?php

namespace App\Http\Controllers;

use App\Models\Profession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasOnboarded()) {
            return redirect()->route('inbox');
        }

        $defaultStart = now()->month >= 4 ? now()->startOfYear()->addMonths(3) : now()->subYear()->startOfYear()->addMonths(3);

        return Inertia::render('onboarding', [
            'professions' => Profession::where('is_active', true)->get(['id', 'slug', 'name']),
            'defaults' => [
                'starts_on' => $defaultStart->toDateString(),
                'ends_on' => $defaultStart->copy()->addYear()->subDay()->toDateString(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'profession_id' => ['required', 'exists:professions,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $user = $request->user();

        $user->update([
            'profession_id' => $validated['profession_id'],
            'onboarded_at' => now(),
        ]);

        $user->appraisalPeriods()->create([
            'label' => date('Y', strtotime($validated['starts_on'])).'/'.date('y', strtotime($validated['ends_on'])),
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'is_current' => true,
        ]);

        $user->ensureInboundEmailToken();

        return redirect()->route('inbox');
    }
}
