<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A regular activity the user has declared: either a fixture on a known
 * cadence ("Lung MDT, every Thursday") that auto-creates template drafts,
 * or an expectation ("audit meetings, 4 a year") that prompts when a
 * stretch passes with nothing captured.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $title
 * @property int|null $activity_type_id
 * @property string $cpd_points
 * @property string|null $organisation
 * @property string|null $frequency
 * @property CarbonImmutable|null $next_due_on
 * @property int|null $expected_per_year
 * @property CarbonImmutable|null $last_prompted_on
 * @property CarbonImmutable|null $last_matched_on
 * @property string $reminder
 * @property bool $is_active
 */
class Recurrence extends Model
{
    /** @use HasFactory<RecurrenceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'next_due_on' => 'immutable_date',
            'last_prompted_on' => 'immutable_date',
            'last_matched_on' => 'immutable_date',
            'is_active' => 'boolean',
            'cpd_points' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ActivityType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    /** @return HasMany<InboxItem, $this> */
    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function isScheduled(): bool
    {
        return $this->kind === 'scheduled';
    }

    /** Move next_due_on forward one cadence step. */
    public function advanceSchedule(): void
    {
        $current = $this->next_due_on ?? CarbonImmutable::today();

        $this->update(['next_due_on' => match ($this->frequency) {
            'fortnightly' => $current->addWeeks(2),
            'monthly' => $current->addMonth(),
            default => $current->addWeek(),
        }]);
    }

    /** Days between expected occurrences for an expectation. */
    public function intervalDays(): int
    {
        return (int) ceil(365 / max(1, (int) $this->expected_per_year));
    }

    /**
     * An expectation is due a prompt when a full interval has passed since
     * anything happened (a capture, a prompt, or the recurrence's creation)
     * and no prompt draft is still sitting unresolved.
     */
    public function duePrompt(): bool
    {
        if ($this->kind !== 'expectation' || ! $this->is_active) {
            return false;
        }

        $lastEvent = collect([
            $this->last_matched_on,
            $this->last_prompted_on,
            CarbonImmutable::parse($this->created_at)->startOfDay(),
        ])->filter()->max();

        if ($lastEvent->addDays($this->intervalDays())->isFuture()) {
            return false;
        }

        return ! $this->inboxItems()
            ->whereIn('status', ['pending', 'ready'])
            ->exists();
    }

    /**
     * The template ai_analysis for a draft occurrence — no AI call, just
     * the user's own definition with the date filled in.
     *
     * @return array<string, mixed>
     */
    public function templateAnalysis(?string $date): array
    {
        return [
            'title' => $this->title,
            'activity_type_slug' => $this->type->slug ?? 'meeting',
            'starts_on' => $date,
            'ends_on' => null,
            'organisation' => $this->organisation,
            'cpd_points' => (float) $this->cpd_points,
            'summary' => $date !== null
                ? "Regular activity: {$this->title}."
                : "Did a \"{$this->title}\" happen recently? Add the date if so — or bin this if not.",
            'suggested_learning_points' => [],
            'reflection_draft' => (object) [],
            'category_slugs' => [],
            'domain_codes' => [],
            'attribute_codes' => [],
            'suggested_project_ids' => [],
            'possible_duplicate_activity_ids' => [],
            'confidence' => 1.0,
            'pii_flags' => [],
            'missing_evidence' => [],
        ];
    }
}
