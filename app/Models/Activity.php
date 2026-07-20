<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int $appraisal_period_id
 * @property int|null $inbox_item_id
 * @property int $activity_type_id
 * @property string $title
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property numeric-string $cpd_points
 * @property string|null $organisation
 * @property string|null $details
 * @property array<string, string> $reflection
 * @property int|null $merged_into_activity_id
 * @property Carbon|null $merged_at
 * @property bool $merge_unreviewed
 * @property Carbon|null $unmerged_at
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Activities absorbed into a merged entry vanish from every query —
        // lists, stats, serialisers, route binding — until un-merge nulls
        // the pointer. Table-qualified so joined queries stay unambiguous.
        static::addGlobalScope('unmerged', function (Builder $query): void {
            $query->whereNull('activities.merged_into_activity_id');
        });

        // Deleting an activity is permanent (no soft-delete bin — the
        // confirm modal carries that weight): its files go, and so does
        // the originating inbox item row, which holds a copy of the AI
        // analysis. Pivots cascade at the database level. Runs on
        // `deleting` — the FK nulls inbox_items.activity_id once the row
        // is actually gone, so the lookup must happen first.
        //
        // A merged entry takes its absorbed sources with it: orphaning them
        // back would resurrect content the user just asked to destroy.
        // Un-merge releases children before deleting the parent shell, so
        // this cascade never fires during a split.
        static::deleting(function (Activity $activity): void {
            $activity->mergedChildren()->get()->each->delete();

            $activity->attachments()->get()->each->purge();

            InboxItem::where('activity_id', $activity->id)->get()->each(function (InboxItem $item) {
                $item->attachments()->get()->each->purge();
                $item->delete();
            });
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cpd_points' => 'decimal:2',
            'reflection' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AppraisalPeriod, $this> */
    public function appraisalPeriod(): BelongsTo
    {
        return $this->belongsTo(AppraisalPeriod::class);
    }

    /** @return BelongsTo<InboxItem, $this> */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }

    /** @return BelongsTo<ActivityType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsToMany<FrameworkDomain, $this> */
    public function frameworkDomains(): BelongsToMany
    {
        return $this->belongsToMany(FrameworkDomain::class);
    }

    /** @return BelongsToMany<FrameworkAttribute, $this> */
    public function frameworkAttributes(): BelongsToMany
    {
        return $this->belongsToMany(FrameworkAttribute::class);
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /** @return BelongsToMany<Activity, $this> */
    public function linkedActivities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_links', 'activity_id', 'linked_activity_id')
            ->withPivot('note');
    }

    /** @return BelongsToMany<Activity, $this> */
    public function linkedFromActivities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_links', 'linked_activity_id', 'activity_id')
            ->withPivot('note');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Links in both directions, as a flat collection of related activities.
     *
     * @return Collection<int, Activity>
     */
    public function allLinkedActivities(): Collection
    {
        return $this->linkedActivities->merge($this->linkedFromActivities)->unique('id')->values();
    }

    /**
     * The activities absorbed into this merged entry. Children are hidden
     * by the `unmerged` scope, so the relation must look past it.
     *
     * @return HasMany<Activity, $this>
     */
    public function mergedChildren(): HasMany
    {
        return $this->hasMany(Activity::class, 'merged_into_activity_id')
            ->withoutGlobalScope('unmerged');
    }

    /** @return BelongsTo<Activity, $this> */
    public function mergedParent(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'merged_into_activity_id');
    }

    public function isMergedParent(): bool
    {
        return $this->mergedChildren()->exists();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithMerged(Builder $query): Builder
    {
        return $query->withoutGlobalScope('unmerged');
    }

    /**
     * This entry's attachments plus its absorbed children's, for display
     * and export. Files never move on merge — each stays owned by its
     * source entry — so a merged parent shows the union at read time.
     *
     * @return Collection<int, Attachment>
     */
    public function allAttachments(): Collection
    {
        $own = $this->attachments()->get();

        if (! $this->isMergedParent()) {
            return $own;
        }

        $childAttachments = Attachment::query()
            ->where('attachable_type', $this->getMorphClass())
            ->whereIn('attachable_id', $this->mergedChildren()->pluck('id'))
            ->get();

        return $own->concat($childAttachments)->unique('id')->values();
    }
}
