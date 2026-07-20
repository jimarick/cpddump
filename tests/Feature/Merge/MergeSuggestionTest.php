<?php

use App\Models\Activity;
use App\Models\AppraisalPeriod;
use App\Models\InboxItem;
use App\Models\Recurrence;
use App\Services\ActivityMerger;
use App\Services\MergeSuggester;

test('a recurrence-matched item suggests merging into the existing occurrence', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->create();

    $existing = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'recurrence_id' => $recurrence->id,
        'title' => 'Clinical audit meeting',
    ]);

    $item = InboxItem::factory()->for($user)->ready()->create([
        'recurrence_id' => $recurrence->id,
    ]);

    $suggestions = app(MergeSuggester::class)->forItems($user, collect([$item]));

    expect($suggestions[$item->id])->toHaveCount(1)
        ->and($suggestions[$item->id][0])->toMatchArray([
            'kind' => 'activity',
            'id' => $existing->id,
            'title' => 'Clinical audit meeting',
            'merged' => false,
            'reason' => 'recurrence',
        ]);
});

test('a merged parent is suggested when its absorbed children carry the recurrence', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->create();
    $period = $user->currentAppraisalPeriod();

    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'recurrence_id' => $recurrence->id]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'recurrence_id' => $recurrence->id]);

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Audit meetings (combined)', 'activity_type_slug' => 'course', 'cpd_points' => 2,
    ]);

    $item = InboxItem::factory()->for($user)->ready()->create(['recurrence_id' => $recurrence->id]);

    $suggestions = app(MergeSuggester::class)->forItems($user, collect([$item]));

    expect($suggestions[$item->id][0])->toMatchArray([
        'kind' => 'activity',
        'id' => $parent->id,
        'merged' => true,
        'reason' => 'recurrence',
    ]);
});

test('duplicate and related activity ids become titled suggestions, current period only', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    $current = Activity::factory()->for($user)->create(['appraisal_period_id' => $period->id, 'title' => 'Lung MDT']);
    $old = Activity::factory()->for($user)->create([
        'appraisal_period_id' => AppraisalPeriod::factory()->for($user)->create(['is_current' => false])->id,
    ]);
    $period->makeCurrent();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'ai_warnings' => [
            'pii_flags' => [],
            'missing_evidence' => [],
            'possible_duplicate_activity_ids' => [$current->id, $old->id],
        ],
    ]);

    $suggestions = app(MergeSuggester::class)->forItems($user, collect([$item]));

    expect(collect($suggestions[$item->id])->pluck('id')->all())->toBe([$current->id])
        ->and($suggestions[$item->id][0]['reason'])->toBe('duplicate');
});

test('related waiting items suggest each other and the inbox page ships them', function () {
    $user = ukDoctor();

    $other = InboxItem::factory()->for($user)->ready()->create();
    $item = InboxItem::factory()->for($user)->ready()->create([
        'ai_warnings' => [
            'pii_flags' => [],
            'missing_evidence' => [],
            'possible_duplicate_activity_ids' => [],
            'possible_related_inbox_item_ids' => [$other->id],
        ],
    ]);

    $page = $this->actingAs($user)->get('/inbox');
    $page->assertOk();

    $items = collect($page->viewData('page')['props']['items']);
    $serialised = $items->firstWhere('id', $item->id);

    expect($serialised['merge_suggestions'])->toHaveCount(1)
        ->and($serialised['merge_suggestions'][0])->toMatchArray([
            'kind' => 'inbox',
            'id' => $other->id,
            'reason' => 'related',
        ]);
});

test('suggestions stay quiet with no current period or no matches', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $suggestions = app(MergeSuggester::class)->forItems($user, collect([$item]));

    expect($suggestions[$item->id])->toBe([]);
});
