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

            $this->attachments()->update([
                'attachable_type' => $activity->getMorphClass(),
                'attachable_id' => $activity->id,
            ]);

            $this->update([
                'status' => InboxItemStatus::Approved,
                'activity_id' => $activity->id,
                'resolved_at' => now(),
            ]);

            return $activity;
        });
    }

    public function dismiss(): void
    {
        $this->update([
            'status' => InboxItemStatus::Dismissed,
            'resolved_at' => now(),
        ]);
    }
}
