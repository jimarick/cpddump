<?php

namespace App\Http\Controllers;

use App\Ai\ReflectionDraftAgent;
use App\Ai\TextAssistAgent;
use App\Enums\AiPurpose;
use App\Services\AiGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Transcription;
use Throwable;

class AiAssistController extends Controller
{
    public function textAssist(Request $request, AiGateway $ai): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:600'],
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

    /**
     * The talk-first capture box: one ramble in, an answer per reflection
     * prompt out — null for prompts the ramble doesn't support.
     */
    public function reflectionDraft(Request $request, AiGateway $ai): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'context' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $request->user();
        $prompts = $user->profession?->reflectionPrompts() ?? [];

        if ($prompts === []) {
            return response()->json(['message' => 'Your profession has no reflection prompts.'], 422);
        }

        if ($ai->overDailyBudget($user)) {
            return response()->json([
                'message' => 'Daily AI allowance reached — try again tomorrow, or add your own API key in Settings.',
            ], 429);
        }

        $prompt = collect([
            filled($validated['context'] ?? null) ? "Activity context:\n{$validated['context']}" : null,
            "The user's ramble:\n{$validated['text']}",
        ])->filter()->implode("\n\n");

        try {
            $response = $ai->structuredPrompt(
                agent: new ReflectionDraftAgent($user->profession->name ?? 'healthcare professional', $prompts),
                user: $user,
                purpose: AiPurpose::ReflectionDraft,
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

        $draft = (array) ($response->toArray()['reflection'] ?? []);

        return response()->json([
            'reflection' => collect($prompts)
                ->mapWithKeys(fn (array $p) => [$p['key'] => $draft[$p['key']] ?? null])
                ->all(),
        ]);
    }

    /** Mic-button dictation: audio blob in, transcript out. */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:15360', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-m4a,video/webm'],
        ]);

        try {
            $response = Transcription::of($request->file('audio'))->generate();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not transcribe that — try again.'], 422);
        }

        return response()->json(['text' => trim($response->text)]);
    }
}
