<?php

namespace App\Http\Controllers;

use App\Ai\TextAssistAgent;
use App\Enums\AiPurpose;
use App\Services\AiGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiAssistController extends Controller
{
    public function textAssist(Request $request, AiGateway $ai): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:20000'],
            'context' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $request->user();

        if ($ai->overDailyBudget($user)) {
            return response()->json([
                'message' => 'Daily AI allowance reached — try again tomorrow, or add your own API key in Settings.',
            ], 429);
        }

        $prompt = collect([
            "Field: {$validated['field']}",
            filled($validated['context'] ?? null) ? "Activity context:\n{$validated['context']}" : null,
            filled($validated['text'] ?? null) ? "Current text:\n{$validated['text']}" : 'Current text: (empty — draft from the context)',
        ])->filter()->implode("\n\n");

        try {
            $response = $ai->structuredPrompt(
                agent: new TextAssistAgent($user->profession->name ?? 'healthcare professional'),
                user: $user,
                purpose: AiPurpose::TextAssist,
                prompt: $prompt,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => str_contains(strtolower($e->getMessage()), 'key')
                    ? 'The AI provider rejected the request — check your API key in Settings.'
                    : 'The AI could not help with that just now. Try again.',
            ], 422);
        }

        return response()->json(['text' => $response->toArray()['text'] ?? '']);
    }
}
