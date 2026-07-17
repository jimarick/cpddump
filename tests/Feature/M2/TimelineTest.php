<?php

use App\Models\Activity;

test('the timeline renders activities for the current period with stats and gaps', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    Activity::factory()->for($user)->count(3)->create([
        'appraisal_period_id' => $period->id,
        'starts_on' => $period->starts_on->copy()->addMonths(2),
    ]);

    $this->actingAs($user)
        ->get('/timeline')
        ->assertInertia(fn ($page) => $page
            ->component('timeline')
            ->has('activities', 3)
            ->has('periods', 1)
            ->has('stats.gaps.domains', 4)
            ->where('period.is_current', true)
        );
});

test('resetting the window opens the next appraisal year and keeps the old one', function () {
    $user = ukDoctor();
    $original = $user->currentAppraisalPeriod();

    $this->actingAs($user)->post('/timeline/reset')->assertRedirect();

    $user->refresh();
    $current = $user->currentAppraisalPeriod();

    expect($user->appraisalPeriods()->count())->toBe(2)
        ->and($current->id)->not->toBe($original->id)
        ->and($current->starts_on->toDateString())->toBe($original->ends_on->copy()->addDay()->toDateString())
        ->and($original->fresh()->is_current)->toBeFalse();
});

test('past periods remain viewable via the period switcher', function () {
    $user = ukDoctor();
    $old = $user->currentAppraisalPeriod();

    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $old->id,
        'starts_on' => $old->starts_on->copy()->addMonth(),
        'title' => 'Old year activity',
    ]);

    $this->actingAs($user)->post('/timeline/reset');

    // Default view (new year) is empty; explicit period shows the old year.
    $this->actingAs($user)
        ->get('/timeline')
        ->assertInertia(fn ($page) => $page->has('activities', 0));

    $this->actingAs($user)
        ->get("/timeline?period={$old->id}")
        ->assertInertia(fn ($page) => $page
            ->has('activities', 1)
            ->where('activities.0.title', 'Old year activity')
        );
});
