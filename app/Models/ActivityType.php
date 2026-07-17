<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $profession_id
 * @property string $slug
 * @property string $name
 * @property string $color
 * @property string $icon
 * @property int $sort_order
 * @property bool $is_active
 */
class ActivityType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Profession, $this> */
    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    /**
     * Types available to a profession: global types plus profession-specific ones.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailableTo(Builder $query, ?Profession $profession): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($profession) {
                $q->whereNull('profession_id');

                if ($profession) {
                    $q->orWhere('profession_id', $profession->id);
                }
            })
            ->orderBy('sort_order');
    }
}
