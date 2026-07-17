<?php

namespace Database\Factories;

use App\Enums\IgnoreRuleField;
use App\Enums\IgnoreRuleOperator;
use App\Models\IgnoreRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IgnoreRule>
 */
class IgnoreRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'field' => IgnoreRuleField::Title,
            'operator' => IgnoreRuleOperator::Contains,
            'value' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
