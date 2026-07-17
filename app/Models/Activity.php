<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

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
}
