<?php

use App\Models\Activity;
use App\Models\Recurrence;
use App\Services\ActivityMerger;
use App\Services\StatsService;

test('points and counts never double-count merged children', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'cpd_points' => 1]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'cpd_points' => 2]);
    $a->categories()->sync($user->profession->categories()->where('slug', 'cpd')->pluck('id'));

    $before = app(StatsService::class)->forPeriod($user, $period);
    expect($before['activities'])->toBe(2)->and($before['points'])->toBe(3.0);

    app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'category_slugs' => ['cpd'],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 3,
    ]);

    $after = app(StatsService::class)->forPeriod($user, $period);

    expect($after['activities'])->toBe(1)
        ->and($after['points'])->toBe(3.0);

    // Gap analysis counts the parent once, not the hidden child.
    $cpdGap = collect($after['gaps']['categories'])->firstWhere('slug', 'cpd');
    expect($cpdGap['count'])->toBe(1);
});

test('recurrence expectations still count each absorbed capture', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    $recurrence = Recurrence::factory()->for($user)->create([
        'kind' => 'expectation',
        'expected_per_year' => 6,
        'is_active' => true,
    ]);

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'recurrence_id' => $recurrence->id]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'recurrence_id' => $recurrence->id]);

    app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged audits', 'activity_type_slug' => 'course', 'cpd_points' => 2,
    ]);

    $stats = app(StatsService::class)->forPeriod($user, $period);
    $expectation = collect($stats['gaps']['expectations'])->firstWhere('id', $recurrence->id);

    // Two meetings went in — the tally must not drop to zero because the
    // entries merged into one.
    expect($expectation['captured'])->toBe(2);
});

test('splitting restores the original stats', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'cpd_points' => 1]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'cpd_points' => 2]);

    $merger = app(ActivityMerger::class);
    $parent = $merger->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 5,
    ]);
    $merger->unmerge($parent);

    $stats = app(StatsService::class)->forPeriod($user, $period);
    expect($stats['activities'])->toBe(2)->and($stats['points'])->toBe(3.0);
});
