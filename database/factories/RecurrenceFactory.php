<?php

namespace Database\Factories;

use App\Models\ActivityType;
use App\Models\Recurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurrence>
 */
class RecurrenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'scheduled',
            'title' => 'Lung MDT',
            'activity_type_id' => ActivityType::where('slug', 'mdt')->value('id'),
            'cpd_points' => 0.5,
            'frequency' => 'weekly',
            'next_due_on' => now()->toDateString(),
            'reminder' => 'weekly',
            'is_active' => true,
        ];
    }

    public function expectation(int $perYear = 4): static
    {
        return $this->state([
            'kind' => 'expectation',
            'title' => 'Audit meeting',
            'frequency' => null,
            'next_due_on' => null,
            'expected_per_year' => $perYear,
        ]);
    }
}
