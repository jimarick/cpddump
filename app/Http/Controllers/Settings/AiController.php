<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/ai', [
            'provider' => $user->ai_provider?->value,
            'hasKey' => $user->ai_api_key !== null,
            'keyHint' => $user->ai_api_key ? '…'.substr($user->ai_api_key, -4) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:anthropic,openai'],
            'key' => ['required', 'string', 'min:20', 'max:400'],
        ]);

        $request->user()->update([
            'ai_provider' => $validated['provider'],
            'ai_api_key' => trim($validated['key']),
        ]);

        return back()->with('success', 'Your own key is now used for all AI features.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->update(['ai_provider' => null, 'ai_api_key' => null]);

        return back()->with('success', 'Back on the built-in AI (daily allowance applies).');
    }
}
