<?php

namespace Database\Factories;

use App\Enums\CalendarFeedStatus;
use App\Models\CalendarFeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarFeed>
 */
class CalendarFeedFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Work calendar',
            'url' => 'https://calendar.google.com/calendar/ical/'.fake()->uuid().'/private/basic.ics',
            'provider_hint' => 'google',
            'status' => CalendarFeedStatus::Active,
        ];
    }
}
