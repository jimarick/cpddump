<?php

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function apiActivityWithTakeaways(User $user): Activity
{
    return Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [['id' => 'n1', 'text' => 'API nugget', 'done' => false]],
        'actions' => [['id' => 'a1', 'text' => 'API action', 'done' => false]],
        'source_notes' => 'notes',
    ]);
}

test('the app can list the period takeaways', function () {
    $user = ukDoctor();
    apiActivityWithTakeaways($user);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/takeaways')
        ->assertOk()
        ->assertJsonPath('activities.0.nuggets.0.text', 'API nugget')
        ->assertJsonPath('activities.0.has_source_notes', true);
});

test('the app can tick, reclassify and delete takeaways and gets fresh lists back', function () {
    $user = ukDoctor();
    $activity = apiActivityWithTakeaways($user);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/activities/{$activity->id}/takeaways/n1", ['done' => true])
        ->assertOk()
        ->assertJsonPath('nuggets.0.done', true);

    $this->patchJson("/api/v1/activities/{$activity->id}/takeaways/a1", ['kind' => 'nugget'])
        ->assertOk()
        ->assertJsonPath('actions', []);

    $this->deleteJson("/api/v1/activities/{$activity->id}/takeaways/n1")
        ->assertOk();

    expect(collect($activity->refresh()->nuggets)->pluck('id')->all())->toBe(['a1']);
});

test('takeaway mutations are owner-only', function () {
    $activity = apiActivityWithTakeaways(ukDoctor());
    Sanctum::actingAs(ukDoctor());

    $this->patchJson("/api/v1/activities/{$activity->id}/takeaways/n1", ['done' => true])
        ->assertForbidden();
});

test('the app can read and update push preferences', function () {
    // Refresh: boolean defaults come from the database, not the factory.
    $user = ukDoctor()->refresh();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('user.push_morning_gem_enabled', true)
        ->assertJsonPath('user.push_weekly_nudge_enabled', false);

    $this->patchJson('/api/v1/user/preferences', ['push_morning_gem_enabled' => false])
        ->assertOk()
        ->assertJsonPath('user.push_morning_gem_enabled', false);

    expect($user->refresh()->push_morning_gem_enabled)->toBeFalse();

    $this->patchJson('/api/v1/user/preferences', ['push_weekly_nudge_enabled' => 'not-a-bool'])
        ->assertUnprocessable();
});

test('activity detail and capture carry the new fields', function () {
    $user = ukDoctor();
    $activity = apiActivityWithTakeaways($user);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/activities/{$activity->id}")
        ->assertOk()
        ->assertJsonPath('activity.nuggets.0.text', 'API nugget')
        ->assertJsonPath('activity.source_notes', 'notes');

    Queue::fake();

    $this->postJson('/api/v1/inbox-items', [
        'notes' => 'Dictated debrief notes from the conference floor',
        'occurred_on' => '2026-07-21',
    ])->assertCreated();

    expect($user->inboxItems()->sole()->source->value)->toBe('debrief');
});
