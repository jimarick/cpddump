<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\AppraisalPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'appraisal_period_id' => AppraisalPeriod::factory(),
            'activity_type_id' => fn () => ActivityType::query()->whereNull('profession_id')->inRandomOrder()->value('id')
                ?? ActivityType::create(['slug' => 'course', 'name' => 'Course', 'color' => '#f4590c', 'icon' => 'graduation-cap'])->id,
            'title' => fake()->sentence(4),
            'starts_on' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'cpd_points' => fake()->randomFloat(1, 0.5, 8),
            'organisation' => fake()->company(),
            'details' => fake()->paragraph(),
            'reflection' => [],
        ];
    }
}
