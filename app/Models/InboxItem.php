<?php

namespace App\Models;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

            return $activity;
        });
    }

    public function dismiss(): void
    {
        // Binned means not evidence: stored files are deleted immediately.
        // The item row (and its analysis) stays for dedupe and audit.
        $this->attachments()->get()->each->purge();

        $this->update([
            'status' => InboxItemStatus::Dismissed,
            'resolved_at' => now(),
        ]);

        $this->redactPayload();
    }

    /** Payload keys that carry third-party content (email bodies etc.). */
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
