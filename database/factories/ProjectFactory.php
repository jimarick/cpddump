<?php

namespace Database\Factories;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => fake()->randomElement(ProjectKind::cases()),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => ProjectStatus::Open,
        ];
    }
}
