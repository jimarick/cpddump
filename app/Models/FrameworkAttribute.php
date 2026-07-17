<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $framework_domain_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
class FrameworkAttribute extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<FrameworkDomain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(FrameworkDomain::class, 'framework_domain_id');
    }
}
