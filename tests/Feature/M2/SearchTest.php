<?php

use App\Models\Activity;
use App\Models\InboxItem;

test('search finds activities by title, reflection content, and inbox payloads', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $period->id,
        'title' => 'FRCR physics teaching session',
    ]);

    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $period->id,
        'title' => 'Something unrelated',
        'reflection' => ['learning_need' => 'Discussed pneumothorax management'],
    ]);

    InboxItem::factory()->for($user)->ready()->create();

    $this->actingAs($user)->getJson('/search?q=physics')
        ->assertJsonCount(1, 'activities')
        ->assertJsonPath('activities.0.title', 'FRCR physics teaching session');

    $this->actingAs($user)->getJson('/search?q=pneumothorax')
        ->assertJsonCount(1, 'activities');

    $this->actingAs($user)->getJson('/search?q=recertification')
        ->assertJsonCount(1, 'inbox');
});

test('search never leaks other users\' data', function () {
    $owner = ukDoctor();
    Activity::factory()->for($owner)->create([
        'appraisal_period_id' => $owner->currentAppraisalPeriod()->id,
        'title' => 'Private cardiology audit',
    ]);

    $this->actingAs(ukDoctor())->getJson('/search?q=cardiology')
        ->assertJsonCount(0, 'activities')
        ->assertJsonCount(0, 'inbox');
});
