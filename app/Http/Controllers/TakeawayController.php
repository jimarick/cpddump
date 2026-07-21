<?php

namespace App\Http\Controllers;

use App\Ai\TakeawayExtractorAgent;
use App\Enums\AiPurpose;
use App\Models\Activity;
use App\Services\AiGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The revision wall: every nugget and action across the current appraisal
 * period, tickable ("done" = got it, stop resurfacing) and re-classifiable.
 * Mutations are per-item and id-addressed so a tick from the phone can't
 * clobber an edit from the web.
 */
class TakeawayController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = $user->currentAppraisalPeriod();

        return Inertia::render('takeaways', [
            'period' => $period?->only(['id', 'label', 'starts_on', 'ends_on']),
            'activities' => self::activitiesFor($request),
        ]);
    }

    public function update(Request $request, Activity $activity, string $item): RedirectResponse
    {
        $this->mutate($request, $activity, $item);

        return back();
    }

    public function destroy(Request $request, Activity $activity, string $item): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);
        abort_unless($activity->removeTakeaway($item), 404);

        return back();
    }

    public function generate(Request $request, Activity $activity, AiGateway $ai): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        self::extractTakeaways($request, $activity, $ai);

        return back()->with('success', 'Takeaways extracted — they\'re live on your Takeaways wall.');
    }

    /**
     * Shared with the API mirror: run the extractor over everything the
     * activity records and store the wrapped takeaways. Only for entries
     * whose takeaways were skipped (or since emptied) — the review wizard
     * covers the first pass.
     */
    public static function extractTakeaways(Request $request, Activity $activity, AiGateway $ai): void
    {
        abort_unless(($activity->nuggets ?? []) === [] && ($activity->actions ?? []) === [], 422, 'This activity already has takeaways.');

        $user = $request->user();

        abort_if($ai->overDailyBudget($user), 429, 'Daily AI allowance reached — try again tomorrow, or add your own API key in Settings.');

        $reflection = collect($user->profession?->reflectionPrompts() ?? [])
            ->map(fn ($p) => filled($activity->reflection[$p['key']] ?? null)
                ? "{$p['question']}\n{$activity->reflection[$p['key']]}"
                : null)
            ->filter()
            ->implode("\n\n");

        $prompt = collect([
            "Title: {$activity->title}",
            "Type: {$activity->type->name}",
            $activity->starts_on ? 'Date: '.$activity->starts_on->toDateString() : null,
            filled($activity->organisation) ? "Organisation: {$activity->organisation}" : null,
            filled($activity->details) ? "Details:\n{$activity->details}" : null,
            filled($reflection) ? "The user's reflections:\n{$reflection}" : null,
            filled($activity->source_notes) ? "The user's own notes:\n{$activity->source_notes}" : null,
        ])->filter()->implode("\n\n");

        try {
            $response = $ai->structuredPrompt(
                agent: new TakeawayExtractorAgent($user->profession->name ?? 'healthcare professional'),
                user: $user,
                purpose: AiPurpose::ReviewCompose,
                prompt: $prompt,
            );
        } catch (Throwable $e) {
            report($e);

            abort(422, 'The AI could not extract takeaways just now. Try again.');
        }

        $extracted = $response->toArray();

        $wrap = fn (array $items) => collect($items)
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')
            ->map(fn ($text) => ['id' => (string) Str::ulid(), 'text' => trim($text), 'done' => false])
            ->values()
            ->all();

        $activity->update([
            'nuggets' => $wrap((array) ($extracted['nuggets'] ?? [])),
            'actions' => $wrap((array) ($extracted['actions'] ?? [])),
        ]);
    }

    /**
     * Shared with the API mirror: validate and apply a per-item mutation
     * (tick/un-tick and/or reclassify).
     */
    public static function applyMutation(Request $request, Activity $activity, string $item): void
    {
        $validated = $request->validate([
            'done' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'in:nugget,action'],
            'text' => ['nullable', 'string', 'max:500'],
        ]);

        $changed = false;

        if (array_key_exists('kind', $validated) && $validated['kind'] !== null) {
            $changed = $activity->moveTakeaway($item, $validated['kind']);
        }

        if (array_key_exists('done', $validated) && $validated['done'] !== null) {
            $changed = $activity->setTakeawayDone($item, (bool) $validated['done']) || $changed;
        }

        if (array_key_exists('text', $validated) && filled($validated['text'])) {
            $changed = $activity->setTakeawayText($item, $validated['text']) || $changed;
        }

        abort_unless($changed, 404);
    }

    /**
     * The period's activities that carry takeaways (done ones included —
     * the Completed table needs them), serialised for either client.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activitiesFor(Request $request): array
    {
        $user = $request->user();
        $period = $user->currentAppraisalPeriod();

        if (! $period) {
            return [];
        }

        return $user->activities()
            ->where('appraisal_period_id', $period->id)
            ->where(fn ($q) => $q->whereNotNull('nuggets')->orWhereNotNull('actions'))
            ->with('type:id,slug,name,color,icon')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Activity $a) => ($a->nuggets ?? []) !== [] || ($a->actions ?? []) !== [])
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'starts_on' => $a->starts_on?->toDateString(),
                'type' => $a->type->only(['slug', 'name', 'color', 'icon']),
                'nuggets' => $a->nuggets ?? [],
                'actions' => $a->actions ?? [],
                'has_source_notes' => filled($a->source_notes),
                'source_notes' => $a->source_notes,
            ])
            ->values()
            ->all();
    }

    private function mutate(Request $request, Activity $activity, string $item): void
    {
        abort_unless($activity->user_id === $request->user()->id, 403);

        self::applyMutation($request, $activity, $item);
    }
}
