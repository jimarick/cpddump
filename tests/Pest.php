<?php

use App\Models\AppraisalPeriod;
use App\Models\Profession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * An onboarded UK doctor with a current appraisal period. Reference data
 * comes from the seeded DatabaseSeeder (see TestCase::$seed).
 */
function ukDoctor(): User
{
    $user = User::factory()->create([
        'profession_id' => Profession::where('slug', 'uk-doctor')->firstOrFail()->id,
        'onboarded_at' => now(),
    ]);

    AppraisalPeriod::factory()->for($user)->create();

    return $user;
}
