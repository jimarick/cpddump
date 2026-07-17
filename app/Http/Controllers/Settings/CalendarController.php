<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CalendarFeedStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarFeed;
use App\Services\CalendarFeedSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CalendarController extends Controller
{
    public function edit(Request $request): Response
    {
        $feeds = $request->user()->calendarFeeds()->latest()->get()->map(fn (CalendarFeed $feed) => [
            'id' => $feed->id,
            'label' => $feed->label,
            'provider_hint' => $feed->provider_hint,
            'status' => $feed->status->value,
            'last_sync_error' => $feed->last_sync_error,
            'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
        ]);

        return Inertia::render('settings/calendars', ['feeds' => $feeds]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048'],
            'provider_hint' => ['nullable', 'in:google,outlook,nhsmail,other'],
        ]);

        // Outlook shows webcal:// links; they are plain https underneath.
        $url = (string) Str::of(trim($validated['url']))->replaceStart('webcal://', 'https://');

        if (! Str::startsWith($url, 'https://')) {
            return back()->withErrors(['url' => 'The calendar link must be an https:// (or webcal://) URL.']);
        }

        $feed = $request->user()->calendarFeeds()->create([
            'label' => $validated['label'],
            'url' => $url,
            'provider_hint' => $validated['provider_hint'] ?? null,
            'status' => CalendarFeedStatus::Active,
        ]);

        SyncCalendarFeed::dispatch($feed);

        return back()->with('success', 'Calendar connected — first sync is running.');
    }

    public function sync(Request $request, CalendarFeed $feed): RedirectResponse
    {
        abort_unless($feed->user_id === $request->user()->id, 403);

        SyncCalendarFeed::dispatch($feed);

        return back()->with('success', 'Syncing…');
    }

    public function destroy(Request $request, CalendarFeed $feed): RedirectResponse
    {
        abort_unless($feed->user_id === $request->user()->id, 403);

        $feed->delete();

        return back()->with('success', 'Calendar disconnected. Already-imported items stay.');
    }

    /** One-off import of an exported .ics file (the NHSmail fallback). */
    public function import(Request $request, CalendarFeedSync $sync): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $user = $request->user();
        $file = $request->file('file');
        $contents = (string) file_get_contents($file->getRealPath());

        if (! str_contains($contents, 'BEGIN:VCALENDAR')) {
            return back()->withErrors(['file' => 'That does not look like a calendar (.ics) file.']);
        }

        $windowStart = $user->currentAppraisalPeriod()?->starts_on->toImmutable()
            ?? CarbonImmutable::now()->subDays(90);

        try {
            $created = $sync->ingestIcs(
                user: $user,
                label: $file->getClientOriginalName(),
                externalPrefix: 'icsupload:'.md5($file->getClientOriginalName()),
                ics: $contents,
                windowStart: $windowStart,
            );
        } catch (Throwable) {
            return back()->withErrors(['file' => 'Could not parse that calendar file.']);
        }

        return back()->with('success', $created > 0
            ? "Imported {$created} past event".($created === 1 ? '' : 's').' into your inbox.'
            : 'No new past events found in that file.');
    }
}
