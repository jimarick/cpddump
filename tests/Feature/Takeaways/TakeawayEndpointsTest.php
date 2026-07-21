<?php

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\URL;

function activityWithTakeaways(?User $user = null): Activity
{
    $user ??= ukDoctor();

    return Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [
            ['id' => 'n1', 'text' => 'First nugget', 'done' => false],
            ['id' => 'n2', 'text' => 'Second nugget', 'done' => true],
        ],
        'actions' => [['id' => 'a1', 'text' => 'One action', 'done' => false]],
        'source_notes' => 'the original notes',
    ]);
}

test('the takeaways page lists the period activities that carry takeaways', function () {
    $user = ukDoctor();
    $activity = activityWithTakeaways($user);

    // No takeaways at all: stays off the wall.
    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [],
        'actions' => [],
    ]);

    $this->actingAs($user)
        ->get('/takeaways')
        ->assertInertia(fn ($page) => $page
            ->component('takeaways')
            ->has('activities', 1)
            ->where('activities.0.id', $activity->id)
            ->where('activities.0.has_source_notes', true)
            ->has('activities.0.nuggets', 2));
});

test('ticking, un-ticking, reclassifying and deleting are id-addressed', function () {
    $user = ukDoctor();
    $activity = activityWithTakeaways($user);

    $this->actingAs($user)
        ->patch("/activities/{$activity->id}/takeaways/n1", ['done' => true])
        ->assertRedirect();
    expect($activity->refresh()->nuggets[0]['done'])->toBeTrue();

    $this->actingAs($user)
        ->patch("/activities/{$activity->id}/takeaways/n2", ['done' => false])
        ->assertRedirect();
    expect($activity->refresh()->nuggets[1]['done'])->toBeFalse();

    // Reclassify keeps the id stable and moves the object between lists.
    $this->actingAs($user)
        ->patch("/activities/{$activity->id}/takeaways/n1", ['kind' => 'action'])
        ->assertRedirect();
    $activity->refresh();
    expect(collect($activity->nuggets)->pluck('id'))->not->toContain('n1')
        ->and(collect($activity->actions)->pluck('id'))->toContain('n1');

    $this->actingAs($user)
        ->delete("/activities/{$activity->id}/takeaways/a1")
        ->assertRedirect();
    expect(collect($activity->refresh()->actions)->pluck('id'))->not->toContain('a1');

    // Unknown id is a 404, not a silent success.
    $this->actingAs($user)
        ->patch("/activities/{$activity->id}/takeaways/nope", ['done' => true])
        ->assertNotFound();
});

test('another user cannot touch your takeaways', function () {
    $activity = activityWithTakeaways();

    $this->actingAs(ukDoctor())
        ->patch("/activities/{$activity->id}/takeaways/n1", ['done' => true])
        ->assertForbidden();
});

test('the signed email link marks exactly the listed takeaways done', function () {
    $user = ukDoctor();
    $activity = activityWithTakeaways($user);

    $url = URL::signedRoute('email.takeaways.done', ['user' => $user->id, 'ids' => 'n1,a1,deleted-id']);

    $this->get($url)->assertOk()->assertSee('2 takeaways');

    $activity->refresh();

    expect($activity->nuggets[0]['done'])->toBeTrue()
        ->and($activity->actions[0]['done'])->toBeTrue();
});

test('the mark-done link requires a valid signature', function () {
    $user = ukDoctor();

    $this->get("/email/takeaways/done/{$user->id}?ids=n1")->assertForbidden();
});
