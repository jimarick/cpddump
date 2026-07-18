<?php

namespace App\Console\Commands;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Mail\RecurrenceReminder;
use App\Models\InboxItem;
use App\Models\Recurrence;
use App\Services\EvidenceIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class GenerateRecurringDrafts extends Command
{
    protected $signature = 'cpd:generate-recurring';

    protected $description = 'Create template drafts for scheduled recurrences and prompts for overdue expectations';

    public function handle(EvidenceIngestor $ingestor): int
    {
        $created = 0;

        Recurrence::query()
            ->where('is_active', true)
            ->with(['user', 'type'])
            ->chunkById(100, function ($recurrences) use ($ingestor, &$created) {
                foreach ($recurrences as $recurrence) {
                    $created += $recurrence->isScheduled()
                        ? $this->generateScheduled($recurrence, $ingestor)
                        : $this->generateExpectationPrompt($recurrence, $ingestor);
                }
            });

        $this->info("Created {$created} recurring drafts.");

        return self::SUCCESS;
    }

    private function generateScheduled(Recurrence $recurrence, EvidenceIngestor $ingestor): int
    {
        $created = 0;

        // Catch up at most a few missed occurrences (downtime, first run) —
        // never flood the inbox with months of backlog.
        for ($i = 0; $i < 4; $i++) {
            $due = $recurrence->next_due_on;

            if ($due === null || $due->isFuture()) {
                break;
            }

            $item = $ingestor->ingest(
                user: $recurrence->user,
                source: EvidenceSource::Recurring,
                rawPayload: ['title' => $recurrence->title, 'occurred_on' => $due->toDateString()],
                externalId: "rec:{$recurrence->id}:{$due->toDateString()}",
                dispatch: false,
            );

            if ($item !== null && $item->wasRecentlyCreated) {
                $this->fillTemplate($item, $recurrence, $due->toDateString());
                $this->maybeRemind($recurrence, $item);
                $created++;
            }

            $recurrence->advanceSchedule();
        }

        return $created;
    }

    private function generateExpectationPrompt(Recurrence $recurrence, EvidenceIngestor $ingestor): int
    {
        if (! $recurrence->duePrompt()) {
            return 0;
        }

        $item = $ingestor->ingest(
            user: $recurrence->user,
            source: EvidenceSource::Recurring,
            rawPayload: ['title' => $recurrence->title, 'prompt' => true],
            externalId: "rec:{$recurrence->id}:prompt:".today()->toDateString(),
            dispatch: false,
        );

        if ($item === null || ! $item->wasRecentlyCreated) {
            return 0;
        }

        $this->fillTemplate($item, $recurrence, null);
        $recurrence->update(['last_prompted_on' => today()->toDateString()]);
        $this->maybeRemind($recurrence, $item);

        return 1;
    }

    /** Template drafts are ready instantly — no AI call, no cost. */
    private function fillTemplate(InboxItem $item, Recurrence $recurrence, ?string $date): void
    {
        $item->update([
            'status' => InboxItemStatus::Ready,
            'recurrence_id' => $recurrence->id,
            'ai_analysis' => $recurrence->templateAnalysis($date),
            'ai_warnings' => ['pii_flags' => [], 'missing_evidence' => [], 'possible_duplicate_activity_ids' => []],
            'analysed_at' => now(),
            'failure_reason' => null,
        ]);
    }

    private function maybeRemind(Recurrence $recurrence, InboxItem $item): void
    {
        if ($recurrence->reminder === 'same_day' && $recurrence->user->email_suppressed_at === null) {
            Mail::to($recurrence->user)->queue(new RecurrenceReminder($recurrence, $item));
        }
    }
}
