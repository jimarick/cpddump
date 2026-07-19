<?php

namespace Database\Factories;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => fake()->unique()->sha256(),
            'platform' => 'ios',
            'device_name' => "James's iPhone",
        ];
    }
}
