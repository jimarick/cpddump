<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $profession_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
class FrameworkDomain extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Profession, $this> */
    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    /** @return HasMany<FrameworkAttribute, $this> */
    public function frameworkAttributes(): HasMany
    {
        return $this->hasMany(FrameworkAttribute::class)->orderBy('sort_order');
    }
}
