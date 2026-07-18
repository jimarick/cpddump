<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Models\ActivityType;
use App\Models\Recurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecurrenceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:scheduled,expectation'],
            'title' => ['required', 'string', 'max:160'],
            'activity_type_slug' => ['required', 'string', 'exists:activity_types,slug'],
            'cpd_points' => ['required', 'numeric', 'min:0', 'max:100'],
            'organisation' => ['nullable', 'string', 'max:160'],
            'frequency' => ['required_if:kind,scheduled', 'nullable', 'in:weekly,fortnightly,monthly'],
            'expected_per_year' => ['required_if:kind,expectation', 'nullable', 'integer', 'min:1', 'max:52'],
            'reminder' => ['required', 'in:same_day,weekly,none'],
        ]);

        $user = $request->user();

        $type = ActivityType::availableTo($user->profession)
            ->where('slug', $validated['activity_type_slug'])
            ->firstOrFail();

        $scheduled = $validated['kind'] === 'scheduled';

        $user->recurrences()->create([
            'kind' => $validated['kind'],
            'title' => $validated['title'],
            'activity_type_id' => $type->id,
            'cpd_points' => $validated['cpd_points'],
            'organisation' => $validated['organisation'] ?? null,
            'frequency' => $scheduled ? $validated['frequency'] : null,
            'next_due_on' => $scheduled ? now()->toDateString() : null,
            'expected_per_year' => $scheduled ? null : (int) $validated['expected_per_year'],
            'reminder' => $validated['reminder'],
        ]);

        return back()->with('success', $scheduled
            ? 'Regular saved — a draft will appear at each occurrence.'
            : "Expectation saved — we'll nudge you if none get captured.");
    }

    /** Drop a ready-to-approve occurrence draft into the inbox right now. */
    public function occurrence(Request $request, Recurrence $recurrence): RedirectResponse
    {
        abort_unless($recurrence->user_id === $request->user()->id, 403);

        $item = $request->user()->inboxItems()->create([
            'source' => EvidenceSource::Recurring,
            'status' => InboxItemStatus::Ready,
            'recurrence_id' => $recurrence->id,
            'raw_payload' => ['title' => $recurrence->title, 'occurred_on' => now()->toDateString()],
            'content_hash' => hash('sha256', "rec:{$recurrence->id}:manual:".now()->timestamp),
            'ai_analysis' => $recurrence->templateAnalysis(now()->toDateString()),
            'ai_warnings' => ['pii_flags' => [], 'missing_evidence' => [], 'possible_duplicate_activity_ids' => []],
            'analysed_at' => now(),
        ]);

        return back()->with('success', "Draft added for today's {$recurrence->title} — review when ready.");
    }

    public function update(Request $request, Recurrence $recurrence): RedirectResponse
    {
        abort_unless($recurrence->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'reminder' => ['sometimes', 'in:same_day,weekly,none'],
        ]);

        $recurrence->update($validated);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, Recurrence $recurrence): RedirectResponse
    {
        abort_unless($recurrence->user_id === $request->user()->id, 403);

        // Unresolved auto-drafts go with it; captured evidence stays.
        $recurrence->inboxItems()
            ->where('source', EvidenceSource::Recurring)
            ->whereIn('status', ['pending', 'ready'])
            ->get()
            ->each
            ->dismiss();

        $recurrence->delete();

        return back()->with('success', 'Regular activity removed.');
    }
}
