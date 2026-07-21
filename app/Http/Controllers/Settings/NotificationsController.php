<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'weeklyEmailEnabled' => $user->weekly_email_enabled,
            'weeklyLearningRecapEnabled' => $user->weekly_learning_recap_enabled,
            'monthlyDigestEmailEnabled' => $user->monthly_digest_email_enabled,
            'pushMorningGemEnabled' => $user->push_morning_gem_enabled,
            'pushWeeklyNudgeEnabled' => $user->push_weekly_nudge_enabled,
            'hasPushTokens' => $user->pushTokens()->exists(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_email_enabled' => ['sometimes', 'required', 'boolean'],
            'weekly_learning_recap_enabled' => ['sometimes', 'required', 'boolean'],
            'monthly_digest_email_enabled' => ['sometimes', 'required', 'boolean'],
            'push_morning_gem_enabled' => ['sometimes', 'required', 'boolean'],
            'push_weekly_nudge_enabled' => ['sometimes', 'required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'Saved.');
    }
}
