<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 * @property array<string, mixed> $settings
 */
class Profession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort_order');
    }

    /** @return HasMany<FrameworkDomain, $this> */
    public function frameworkDomains(): HasMany
    {
        return $this->hasMany(FrameworkDomain::class)->orderBy('sort_order');
    }

    /** @return HasMany<ActivityType, $this> */
    public function activityTypes(): HasMany
    {
        return $this->hasMany(ActivityType::class)->orderBy('sort_order');
    }

    /**
     * Reflection prompt definitions: [{key, label, question}, ...]
     *
     * @return array<int, array{key: string, label: string, question: string}>
     */
    public function reflectionPrompts(): array
    {
        return $this->settings['reflection_prompts'] ?? [];
    }
}
