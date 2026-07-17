<?php

namespace App\Models;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $appraisal_period_id
 * @property ProjectKind $kind
 * @property string $title
 * @property string|null $description
 * @property ProjectStatus $status
 * @property Carbon|null $due_on
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'status' => ProjectStatus::class,
            'due_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Activity, $this> */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class);
    }
}
