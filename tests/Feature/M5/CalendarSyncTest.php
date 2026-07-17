<?php

use App\Enums\CalendarFeedStatus;
use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarFeed;
use App\Models\IgnoreRule;
use App\Services\CalendarFeedSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function icsFixture(): string
{
    // A weekly MDT that has been running for months, so the 30-day sync
    // window contains several finished occurrences.
    $mdtStart = now()->subWeeks(12)->setTime(13, 0);
    $future = now()->addDays(3)->setTime(9, 0);

    $fmt = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');

    return implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//EN',
        'BEGIN:VEVENT',
        'UID:mdt-weekly@example.com',
        'SUMMARY:Lung MDT',
        'ORGANIZER;CN=Dr Organiser:mailto:organiser@nhs.example',
        'DTSTART:'.$fmt($mdtStart),
        'DTEND:'.$fmt($mdtStart->copy()->addHour()),
        'RRULE:FREQ=WEEKLY',
        'END:VEVENT',
        'BEGIN:VEVENT',
        'UID:journal-club@example.com',
        'SUMMARY:Journal club',
        'DTSTART:'.$fmt(now()->subDays(5)->setTime(12, 0)),
        'DTEND:'.$fmt(now()->subDays(5)->setTime(13, 0)),
        'END:VEVENT',
        'BEGIN:VEVENT',
        'UID:future@example.com',
        'SUMMARY:Future conference',
        'DTSTART:'.$fmt($future),
        'DTEND:'.$fmt($future->copy()->addHours(8)),
        'END:VEVENT',
        'BEGIN:VEVENT',
        'UID:leave@example.com',
        'SUMMARY:Annual leave',
        'DTSTART;VALUE=DATE:'.now()->subDays(6)->format('Ymd'),
        'DTEND;VALUE=DATE:'.now()->subDays(4)->format('Ymd'),
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
}

beforeEach(function () {
    Queue::fake([AnalyzeInboxItem::class]);
});

test('syncing a feed imports finished timed events only, and re-syncing does not duplicate', function () {
    $user = ukDoctor();
    $feed = CalendarFeed::factory()->for($user)->create();

    Http::fake(['*' => Http::response(icsFixture())]);

    $created = app(CalendarFeedSync::class)->sync($feed->fresh());

    $titles = $user->inboxItems()->pluck('raw_payload')->map(fn ($p) => $p['title']);

    expect($titles)->toContain('Lung MDT')
        ->toContain('Journal club')
        ->not->toContain('Future conference')
        ->not->toContain('Annual leave')
        ->and($created)->toBeGreaterThanOrEqual(2)
        ->and($user->inboxItems()->where('source', EvidenceSource::Calendar)->count())->toBe($created);

    // Second sync from scratch finds nothing new.
    $feed->fresh()->update(['last_synced_at' => null]);
    $again = app(CalendarFeedSync::class)->sync($feed->fresh());

    expect($again)->toBe(0);
});

test('recurring events expand into multiple dated occurrences', function () {
    $user = ukDoctor();
    $feed = CalendarFeed::factory()->for($user)->create();

    Http::fake(['*' => Http::response(icsFixture())]);
    app(CalendarFeedSync::class)->sync($feed->fresh());

    $mdtCount = $user->inboxItems()
        ->get()
        ->filter(fn ($i) => $i->raw_payload['title'] === 'Lung MDT')
        ->count();

    // ~30-day window with a weekly recurrence: several occurrences.
    expect($mdtCount)->toBeGreaterThanOrEqual(3);
});

test('ignore rules silence recurring noise from calendars', function () {
    $user = ukDoctor();
    $feed = CalendarFeed::factory()->for($user)->create();

    IgnoreRule::factory()->for($user)->create([
        'source' => EvidenceSource::Calendar,
        'field' => 'title',
        'operator' => 'contains',
        'value' => 'lung mdt',
    ]);

    Http::fake(['*' => Http::response(icsFixture())]);
    app(CalendarFeedSync::class)->sync($feed->fresh());

    $titles = $user->inboxItems()->pluck('raw_payload')->map(fn ($p) => $p['title']);

    expect($titles)->not->toContain('Lung MDT')
        ->toContain('Journal club');
});

test('a failing feed records the error and recovers on the next good sync', function () {
    $user = ukDoctor();
    $feed = CalendarFeed::factory()->for($user)->create();

    Http::fake(['*' => Http::sequence()->pushStatus(404)->push(icsFixture())]);

    try {
        app(CalendarFeedSync::class)->sync($feed->fresh());
    } catch (Throwable) {
        // expected
    }

    expect($feed->fresh()->status)->toBe(CalendarFeedStatus::Failing)
        ->and($feed->fresh()->last_sync_error)->not->toBeNull();

    app(CalendarFeedSync::class)->sync($feed->fresh());

    expect($feed->fresh()->status)->toBe(CalendarFeedStatus::Active)
        ->and($feed->fresh()->last_sync_error)->toBeNull();
});

test('feeds can be added via settings (webcal normalised) and the command queues syncs', function () {
    Queue::fake();
    $user = ukDoctor();

    $this->actingAs($user)->post('/settings/calendars', [
        'label' => 'Work calendar',
        'url' => 'webcal://outlook.office365.com/owa/calendar/abc/calendar.ics',
        'provider_hint' => 'outlook',
    ])->assertRedirect();

    $feed = $user->calendarFeeds()->firstOrFail();

    expect($feed->url)->toStartWith('https://');
    Queue::assertPushed(SyncCalendarFeed::class);

    $this->artisan('cpd:sync-calendars')->assertSuccessful();
    Queue::assertPushed(SyncCalendarFeed::class, 2);
});

test('an exported ics file can be imported once', function () {
    $user = ukDoctor();

    $file = UploadedFile::fake()->createWithContent('export.ics', icsFixture());

    $this->actingAs($user)
        ->post('/settings/calendars/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->inboxItems()->where('source', EvidenceSource::Calendar)->count())->toBeGreaterThanOrEqual(2);
});
