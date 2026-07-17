<?php

namespace Database\Factories;

use App\Models\AppraisalPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppraisalPeriod>
 */
class AppraisalPeriodFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->month >= 4
            ? now()->startOfYear()->addMonths(3)
            : now()->subYear()->startOfYear()->addMonths(3);

        return [
            'user_id' => User::factory(),
            'label' => $start->format('Y').'/'.$start->copy()->addYear()->format('y'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->addYear()->subDay()->toDateString(),
            'is_current' => true,
        ];
    }
}
