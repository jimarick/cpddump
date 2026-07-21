<?php

use App\Ai\TakeawayExtractorAgent;
use App\Models\Activity;
use App\Models\User;
use Laravel\Ai\Ai;
use Laravel\Sanctum\Sanctum;

function bareActivity(User $user): Activity
{
    return Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [],
        'actions' => [],
        'source_notes' => 'HBP changes my work-up. TODO ask about gadoxetate lists.',
    ]);
}

test('generate extracts and stores wrapped takeaways for a bare activity', function () {
    $user = ukDoctor();
    $activity = bareActivity($user);

    Ai::fakeAgent(TakeawayExtractorAgent::class, [[
        'nuggets' => ['Gadoxetate HBP: no uptake rules out FNH'],
        'actions' => ['Ask the MRI lead about gadoxetate lists'],
    ]]);

    $this->actingAs($user)
        ->post("/activities/{$activity->id}/takeaways/generate")
        ->assertRedirect();

    $activity->refresh();

    expect($activity->nuggets)->toHaveCount(1)
        ->and($activity->nuggets[0]['text'])->toBe('Gadoxetate HBP: no uptake rules out FNH')
        ->and($activity->nuggets[0]['id'])->toBeString()->not->toBeEmpty()
        ->and($activity->nuggets[0]['done'])->toBeFalse()
        ->and($activity->actions[0]['text'])->toBe('Ask the MRI lead about gadoxetate lists');
});

test('generate refuses when the activity already has takeaways, and for other users', function () {
    $user = ukDoctor();
    $activity = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [['id' => 'n1', 'text' => 'Existing', 'done' => false]],
        'actions' => [],
    ]);

    $this->actingAs($user)
        ->post("/activities/{$activity->id}/takeaways/generate")
        ->assertStatus(422);

    $this->actingAs(ukDoctor())
        ->post("/activities/{$activity->id}/takeaways/generate")
        ->assertForbidden();
});

test('the companion app can generate takeaways too', function () {
    $user = ukDoctor();
    $activity = bareActivity($user);
    Sanctum::actingAs($user);

    Ai::fakeAgent(TakeawayExtractorAgent::class, [[
        'nuggets' => ['mRECIST for TACE response'],
        'actions' => [],
    ]]);

    $this->postJson("/api/v1/activities/{$activity->id}/takeaways/generate")
        ->assertOk()
        ->assertJsonPath('nuggets.0.text', 'mRECIST for TACE response')
        ->assertJsonPath('actions', []);
});
