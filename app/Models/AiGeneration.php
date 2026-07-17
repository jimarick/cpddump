<?php

namespace App\Models;

use App\Enums\AiPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property AiPurpose $purpose
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property numeric-string $estimated_cost
 * @property bool $used_user_key
 */
class AiGeneration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purpose' => AiPurpose::class,
            'used_user_key' => 'boolean',
            'estimated_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function generatable(): MorphTo
    {
        return $this->morphTo();
    }
}
