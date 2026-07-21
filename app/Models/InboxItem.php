<?php

namespace App\Models;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Observers\InboxItemObserver;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @property int $id
 * @property int $user_id
 * @property EvidenceSource $source
 * @property InboxItemStatus $status
 * @property array<string, mixed> $raw_payload
 * @property string|null $content_hash
 * @property string|null $external_id
 * @property array<string, mixed>|null $ai_analysis
 * @property array<string, mixed>|null $ai_warnings
 * @property int|null $activity_id
 * @property int|null $recurrence_id
 * @property string|null $failure_reason
 * @property Carbon|null $analysed_at
 * @property Carbon|null $resolved_at
 */
#[ObservedBy(InboxItemObserver::class)]
class InboxItem extends Model
{
    /** @use HasFactory<InboxItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => EvidenceSource::class,
            'status' => InboxItemStatus::class,
            'raw_payload' => 'array',
            'ai_analysis' => 'array',
            'ai_warnings' => 'array',
            'analysed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Recurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(Recurrence::class);
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', InboxItemStatus::open());
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [InboxItemStatus::Approved, InboxItemStatus::Dismissed], true);
    }

    /**
     * Approve this item, promoting the (possibly user-edited) analysis
     * payload into a real Activity with normalised relations. The payload
     * follows the InboxAnalystAgent extraction contract.
     *
     * @param  array<string, mixed>  $payload
     */
    public function approve(array $payload): Activity
    {
        return DB::transaction(function () use ($payload) {
            $user = $this->user;
            $profession = $user->profession;
            $period = $user->currentAppraisalPeriod();

            throw_unless($period, new RuntimeException('User has no current appraisal period.'));

            $type = ActivityType::availableTo($profession)
                ->where('slug', $payload['activity_type_slug'])
                ->firstOrFail();

            $reflectionKeys = collect($profession->reflectionPrompts())->pluck('key')->all();

            $activity = $user->activities()->create([
                'appraisal_period_id' => $period->id,
                'inbox_item_id' => $this->id,
                'recurrence_id' => $this->recurrence_id,
                'activity_type_id' => $type->id,
                'title' => $payload['title'],
                'starts_on' => $payload['starts_on'] ?? null,
                'ends_on' => $payload['ends_on'] ?? null,
                'cpd_points' => $payload['cpd_points'] ?? 0,
                'organisation' => $payload['organisation'] ?? null,
                'details' => $payload['summary'] ?? null,
                'reflection' => collect((array) ($payload['reflection_draft'] ?? []))->only($reflectionKeys)->all(),
                // Absent key (an older client) inherits the AI extraction;
                // an explicitly-sent empty list means the user cleared it.
                'nuggets' => $this->takeawaysFrom($payload, 'nuggets'),
                'actions' => $this->takeawaysFrom($payload, 'actions'),
                'source_notes' => $payload['source_notes']
                    ?? $this->raw_payload['notes']
                    ?? $this->ai_analysis['user_notes']
                    ?? null,
            ]);

            $activity->categories()->sync(
                $profession->categories()->whereIn('slug', $payload['category_slugs'] ?? [])->pluck('id')
            );

            $activity->frameworkDomains()->sync(
                $profession->frameworkDomains()->whereIn('code', $payload['domain_codes'] ?? [])->pluck('id')
            );

            $activity->frameworkAttributes()->sync(
                FrameworkAttribute::query()
                    ->whereIn('code', $payload['attribute_codes'] ?? [])
                    ->whereHas('domain', fn ($q) => $q->where('profession_id', $profession->id))
                    ->pluck('framework_attributes.id')
            );

            $activity->projects()->sync(
                $user->projects()->whereIn('id', $payload['project_ids'] ?? [])->pluck('id')
            );

            $activity->linkedActivities()->sync(
                $user->activities()->whereIn('id', $payload['linked_activity_ids'] ?? [])->pluck('id')
            );

            // Retention first (delete-by-default), then hand the survivors
            // and stubs to the activity — after the re-point this item's
            // attachments() relation is empty.
            $this->applyRetention($payload['keep_attachment_ids'] ?? []);

            $this->attachments()->update([
                'attachable_type' => $activity->getMorphClass(),
                'attachable_id' => $activity->id,
            ]);

            $this->update([
                'status' => InboxItemStatus::Approved,
                'activity_id' => $activity->id,
                'resolved_at' => now(),
            ]);

            // A capture counts toward its regular activity's tally.
            $this->recurrence?->update([
                'last_matched_on' => $activity->starts_on?->toDateString() ?? now()->toDateString(),
            ]);

            $this->redactPayload();
            $this->redactFlagExcerpts();

            return $activity;
        });
    }

    /**
     * The nugget/action list to promote onto the activity: the payload's
     * when the client sent the key, the AI extraction otherwise — items
     * normalised to {id, text, done}.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{id: string, text: string, done: bool}>
     */
    private function takeawaysFrom(array $payload, string $list): array
    {
        $items = array_key_exists($list, $payload)
            ? (array) ($payload[$list] ?? [])
            : (array) ($this->ai_analysis[$list] ?? []);

        return collect($items)
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['text'] ?? '')) !== '')
            ->map(fn ($item) => [
                'id' => (string) ($item['id'] ?? Str::ulid()),
                'text' => trim((string) $item['text']),
                'done' => (bool) ($item['done'] ?? false),
            ])
            ->values()
            ->all();
    }

    public function dismiss(): void
    {
        // Delete means delete: files, analysis, the row itself — gone.
        // The single exception is a calendar event's UID, remembered so the
        // weekly sync never resurrects what the user binned.
        if ($this->source === EvidenceSource::Calendar && $this->external_id !== null) {
            DismissedCalendarEvent::query()->firstOrCreate(
                ['user_id' => $this->user_id, 'uid' => $this->external_id],
                ['dismissed_at' => now()],
            );
        }

        $this->attachments()->get()->each->purge();
        $this->delete();
    }

    /**
     * Payload keys that carry third-party content (email bodies etc.).
     * User-authored text ('details', debrief 'notes') must never join this
     * list — the user chose to keep those words, and debrief notes survive
     * onto the activity as source_notes.
     */
    private const REDACTABLE_PAYLOAD_KEYS = ['body', 'transcript', 'page_text', 'html'];

    /**
     * Strip third-party content from the raw payload once the item is
     * resolved — the extracted analysis is what we keep, not the email
     * itself. Metadata (subject, sender, url) survives for ignore rules
     * and audit.
     */
    public function redactPayload(): void
    {
        $payload = $this->raw_payload ?? [];

        if (empty(array_intersect(self::REDACTABLE_PAYLOAD_KEYS, array_keys($payload)))) {
            return;
        }

        $this->update([
            'raw_payload' => collect($payload)
                ->except(self::REDACTABLE_PAYLOAD_KEYS)
                ->put('redacted_at', now()->toIso8601String())
                ->all(),
        ]);
    }

    /**
     * The PII gate blocks approval ONLY when something persistent still
     * holds flagged content: a stored file, or user-authored text. Flags
     * whose source was already-deleted text get an informational note, not
     * a prompt — asking users to delete what no longer exists trains them
     * to ignore the gate.
     */
    public function piiGateActive(): bool
    {
        $flags = $this->ai_warnings['pii_flags'] ?? [];

        if ($flags === [] || filled($this->ai_warnings['pii_resolved'] ?? null)) {
            return false;
        }

        $hasStoredFiles = $this->attachments()->whereNull('purged_at')->exists();
        $hasAuthoredText = in_array($this->source, [EvidenceSource::Manual, EvidenceSource::Upload, EvidenceSource::Debrief], true)
            && (filled($this->raw_payload['details'] ?? null) || filled($this->raw_payload['notes'] ?? null));

        return $hasStoredFiles || $hasAuthoredText;
    }

    /** Record how the user resolved a PII gate: 'removed' or 'affirmed'. */
    public function recordPiiResolution(string $how): void
    {
        $this->update([
            'ai_warnings' => array_merge($this->ai_warnings ?? [], [
                'pii_resolved' => $how,
                'pii_resolved_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Flagging an identifier must not preserve a copy of it: once the item
     * is resolved, flag excerpts collapse to type + severity only.
     */
    public function redactFlagExcerpts(): void
    {
        $strip = fn (array $flags) => array_map(
            fn ($flag) => is_array($flag) ? array_diff_key($flag, ['excerpt' => true]) : $flag,
            $flags,
        );

        $warnings = $this->ai_warnings ?? [];
        $analysis = $this->ai_analysis ?? [];

        if (($warnings['pii_flags'] ?? []) !== []) {
            $warnings['pii_flags'] = $strip($warnings['pii_flags']);
        }

        if (($analysis['pii_flags'] ?? []) !== []) {
            $analysis['pii_flags'] = $strip($analysis['pii_flags']);
        }

        $this->update(['ai_warnings' => $warnings, 'ai_analysis' => $analysis]);
    }

    /**
     * Delete-by-default: files survive approval only if the user kept them.
     * "ask" honours the per-file choices from the approve form; "always"
     * and "never" skip the question entirely. Purged files leave stubs.
     *
     * @param  array<int, int|string>  $keepIds
     */
    private function applyRetention(array $keepIds): void
    {
        $retention = $this->user->attachment_retention ?? 'ask';
        $keepIds = array_map('intval', $keepIds);

        foreach ($this->attachments()->whereNull('purged_at')->get() as $attachment) {
            $keep = match ($retention) {
                'always' => true,
                'never' => false,
                default => in_array($attachment->id, $keepIds, true),
            };

            if (! $keep) {
                $attachment->purgeToStub();
            }
        }
    }

    /**
     * Once analysis has succeeded, the raw source text has served its
     * purpose: the drafted entry is the evidence. Scrub free-floating text
     * (email bodies, transcripts, fetched pages) and the extracted text of
     * already-purged files (spreadsheets, parsed emails). Text belonging to
     * still-stored files stays until the file's own fate is decided.
     */
    public function scrubSourceText(): void
    {
        $this->redactPayload();

        $this->attachments()
            ->whereNotNull('purged_at')
            ->whereNotNull('extracted_text')
            ->update(['extracted_text' => null]);
    }
}
