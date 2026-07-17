<?php

namespace App\Models;

use Database\Factories\AppraisalPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_current
 */
class AppraisalPeriod extends Model
{
    /** @use HasFactory<AppraisalPeriodFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** Make this the user's current period, retiring any other. */
    public function makeCurrent(): void
    {
        $this->user->appraisalPeriods()->where('id', '!=', $this->id)->update(['is_current' => false]);
        $this->update(['is_current' => true]);
    }
}
