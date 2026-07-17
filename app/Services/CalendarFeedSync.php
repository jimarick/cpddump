<?php

namespace App\Services;

use App\Enums\CalendarFeedStatus;
use App\Enums\EvidenceSource;
use App\Models\CalendarFeed;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Pulls a pasted ICS feed (Google "secret address", Outlook published
 * calendar, NHSmail where allowed) and turns finished events into draft
 * inbox items. No OAuth anywhere — the URL is the credential.
 */
class CalendarFeedSync
{
    public function __construct(private EvidenceIngestor $ingestor) {}

    /** @return int Number of new inbox items created. */
    public function sync(CalendarFeed $feed): int
    {
        try {
            $ics = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'CPDDump/1.0 (+https://cpddump.com)'])
                ->get($feed->url)
                ->throw()
                ->body();

            $windowStart = $feed->last_synced_at
                ? $feed->last_synced_at->toImmutable()->subDay()
                : CarbonImmutable::now()->subDays(30);

            $created = $this->ingestIcs(
                user: $feed->user,
                label: $feed->label,
                externalPrefix: "feed{$feed->id}",
                ics: $ics,
                windowStart: $windowStart,
            );
        } catch (Throwable $e) {
            $feed->update([
                'status' => CalendarFeedStatus::Failing,
                'last_sync_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        $feed->update([
            'status' => CalendarFeedStatus::Active,
            'last_sync_error' => null,
            'last_synced_at' => now(),
        ]);

        return $created;
    }

    /**
     * Parse ICS content and ingest finished events. Also used directly by
     * the one-off .ics file upload path.
     */
    public function ingestIcs(User $user, string $label, string $externalPrefix, string $ics, CarbonImmutable $windowStart): int
    {
        $windowEnd = CarbonImmutable::now();

        $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            throw new \RuntimeException('Not a calendar document.');
        }

        // Expands recurring events into concrete occurrences in the window.
        $expanded = $calendar->expand(
            new \DateTimeImmutable($windowStart->toIso8601String()),
            new \DateTimeImmutable($windowEnd->toIso8601String()),
        );

        $created = 0;

        foreach ($expanded->select('VEVENT') as $event) {
            if ($this->shouldSkip($event)) {
                continue;
            }

            $start = CarbonImmutable::instance($event->DTSTART->getDateTime());
            $end = isset($event->DTEND) ? CarbonImmutable::instance($event->DTEND->getDateTime()) : $start;

            // Only events that have already finished are CPD evidence.
            if ($end->isAfter($windowEnd) || $start->isBefore($windowStart)) {
                continue;
            }

            $uid = (string) ($event->UID ?? md5((string) $event->SUMMARY.$start->toIso8601String()));

            $item = $this->ingestor->ingest(
                user: $user,
                source: EvidenceSource::Calendar,
                rawPayload: [
                    'title' => (string) ($event->SUMMARY ?? 'Untitled event'),
                    'organiser' => $this->organiser($event),
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'duration_hours' => round(abs($start->diffInHours($end)), 2),
                    'location' => isset($event->LOCATION) ? (string) $event->LOCATION : null,
                    'description' => isset($event->DESCRIPTION) ? mb_substr((string) $event->DESCRIPTION, 0, 2000) : null,
                    'calendar_label' => $label,
                ],
                externalId: "{$externalPrefix}:{$uid}:{$start->toDateString()}",
            );

            if ($item?->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function shouldSkip(VEvent $event): bool
    {
        // All-day events (date-only DTSTART) are leave, birthdays, noise.
        if (! isset($event->DTSTART) || ! $event->DTSTART->hasTime()) {
            return true;
        }

        // Cancelled events are not attendance.
        $status = isset($event->STATUS) ? strtoupper((string) $event->STATUS) : null;

        return $status === 'CANCELLED';
    }

    private function organiser(VEvent $event): ?string
    {
        if (! isset($event->ORGANIZER)) {
            return null;
        }

        $value = (string) $event->ORGANIZER;
        $cn = $event->ORGANIZER['CN'] ?? null;

        return $cn ? (string) $cn : str_replace('mailto:', '', $value);
    }
}
