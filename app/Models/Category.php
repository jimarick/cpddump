<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $profession_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 */
class Category extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Profession, $this> */
    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }
}
