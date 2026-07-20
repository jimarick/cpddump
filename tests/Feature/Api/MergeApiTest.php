<?php

use App\Models\Activity;
use App\Models\InboxItem;
use App\Services\ActivityMerger;
use Laravel\Sanctum\Sanctum;

test('the companion app can preview, merge and split', function () {
    $user = ukDoctor();
    Sanctum::actingAs($user);

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id, 'cpd_points' => 1]);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $preview = $this->postJson('/api/v1/merges/preview', [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
    ])->assertOk()->json();

    expect($preview['defaults']['cpd_points'])->toBe(7)
        ->and($preview['blocking']['pii_item_ids'])->toBe([]);

    $merged = $this->postJson('/api/v1/merges', [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'title' => 'Combined from the app',
        'activity_type_slug' => 'course',
        'cpd_points' => 7,
    ])->assertCreated()->json();

    // The index shows one merged entry; the absorbed child 404s directly.
    $index = $this->getJson('/api/v1/activities')->assertOk()->json();
    expect(collect($index['activities'])->pluck('id')->all())->toBe([$merged['activity_id']])
        ->and(collect($index['activities'])->first()['merged'])->toBeTrue();

    $this->getJson("/api/v1/activities/{$a->id}")->assertNotFound();

    $show = $this->getJson("/api/v1/activities/{$merged['activity_id']}")->assertOk()->json('activity');
    expect(collect($show['merged_from'])->pluck('id'))->toContain($a->id)
        ->and($show['formerly_merged'])->toBeFalse();

    $split = $this->postJson("/api/v1/activities/{$merged['activity_id']}/unmerge")
        ->assertOk()->json();

    expect($split['activity_ids'])->toContain($a->id)
        ->and($this->getJson("/api/v1/activities/{$a->id}")->status())->toBe(200);
});

test('merge validation errors carry the blocking item ids', function () {
    $user = ukDoctor();
    Sanctum::actingAs($user);

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);

    $this->postJson('/api/v1/merges', [
        'activity_ids' => [$a->id],
        'title' => 'Too few sources',
        'activity_type_slug' => 'course',
        'cpd_points' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors(['activity_ids']);
});

test('the companion app can edit an activity, including a merged one', function () {
    $user = ukDoctor();
    Sanctum::actingAs($user);

    $activity = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
    ]);

    $updated = $this->putJson("/api/v1/activities/{$activity->id}", [
        'title' => 'Edited from the app',
        'activity_type_slug' => 'course',
        'cpd_points' => 3.5,
        'reflection' => ['why_selected' => 'Updated on the go.', 'bogus_key' => 'dropped'],
        'category_slugs' => ['cpd'],
    ])->assertOk()->json('activity');

    expect($updated['title'])->toBe('Edited from the app')
        ->and($updated['cpd_points'])->toBe(3.5)
        ->and($updated['reflection'])->toBe(['why_selected' => 'Updated on the go.'])
        ->and(collect($updated['categories'])->pluck('slug')->all())->toBe(['cpd']);

    // Someone else's activity is untouchable.
    $other = ukDoctor();
    $theirs = Activity::factory()->for($other)->create([
        'appraisal_period_id' => $other->currentAppraisalPeriod()->id,
    ]);

    $this->putJson("/api/v1/activities/{$theirs->id}", [
        'title' => 'x', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ])->assertForbidden();
});

test('another user\'s merged entry cannot be split through the API', function () {
    $user = ukDoctor();
    $other = ukDoctor();

    $a = Activity::factory()->for($other)->create(['appraisal_period_id' => $other->currentAppraisalPeriod()->id]);
    $b = Activity::factory()->for($other)->create(['appraisal_period_id' => $other->currentAppraisalPeriod()->id]);
    $parent = app(ActivityMerger::class)->merge($other, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/activities/{$parent->id}/unmerge")->assertForbidden();
});
