<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\IgnoreRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvidenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/evidence', [
            'dumpAddress' => $user->inboundEmailAddress(),
            'weeklyEmailEnabled' => $user->weekly_email_enabled,
            'ignoreRules' => $user->ignoreRules()->latest()->get()->map(fn (IgnoreRule $rule) => [
                'id' => $rule->id,
                'source' => $rule->source?->label(),
                'field' => $rule->field->value,
                'operator' => $rule->operator->value,
                'value' => $rule->value,
                'is_active' => $rule->is_active,
                'hit_count' => $rule->hit_count,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_email_enabled' => ['required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'Saved.');
    }

    public function toggleRule(Request $request, IgnoreRule $rule): RedirectResponse
    {
        abort_unless($rule->user_id === $request->user()->id, 403);

        $rule->update(['is_active' => ! $rule->is_active]);

        return back();
    }

    public function destroyRule(Request $request, IgnoreRule $rule): RedirectResponse
    {
        abort_unless($rule->user_id === $request->user()->id, 403);

        $rule->delete();

        return back()->with('success', 'Rule deleted — matching items will appear again.');
    }
}
