<?php

namespace App\Models;

use App\Enums\EvidenceSource;
use App\Enums\IgnoreRuleField;
use App\Enums\IgnoreRuleOperator;
use Database\Factories\IgnoreRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property EvidenceSource|null $source
 * @property IgnoreRuleField $field
 * @property IgnoreRuleOperator $operator
 * @property string $value
 * @property bool $is_active
 * @property int $hit_count
 */
class IgnoreRule extends Model
{
    /** @use HasFactory<IgnoreRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => EvidenceSource::class,
            'field' => IgnoreRuleField::class,
            'operator' => IgnoreRuleOperator::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Does this rule match the given evidence? $fields maps field names
     * (title, organiser, sender, sender_domain) to candidate values.
     *
     * @param  array<string, string|null>  $fields
     */
    public function matches(EvidenceSource $source, array $fields): bool
    {
        if ($this->source !== null && $this->source !== $source) {
            return false;
        }

        $candidate = $fields[$this->field->value] ?? null;

        return $candidate !== null && $this->operator->matches($this->value, $candidate);
    }
}
