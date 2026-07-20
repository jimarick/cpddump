<?php

use App\Enums\InboxItemStatus;
use App\Models\Activity;
use App\Models\AppraisalPeriod;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\ActivityMerger;
use Illuminate\Validation\ValidationException;

function mergePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Lung MDT — combined entry',
        'activity_type_slug' => 'course',
        'starts_on' => '2026-03-12',
        'ends_on' => '2026-03-14',
        'cpd_points' => 2,
        'reflection' => ['why_selected' => 'Combined reflection on the whole series.'],
        'category_slugs' => ['cpd'],
        'domain_codes' => ['D1'],
    ], $overrides);
}

function doctorActivity($user, array $attributes = []): Activity
{
    return Activity::factory()->for($user)->create(array_merge([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
    ], $attributes));
}

test('merging activities and a ready item stacks them under one parent', function () {
    $user = ukDoctor();
    $a = doctorActivity($user, ['cpd_points' => 1]);
    $b = doctorActivity($user, ['cpd_points' => 0.5]);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $parent = app(ActivityMerger::class)->merge($user, mergePayload([
        'activity_ids' => [$a->id, $b->id],
        'inbox_item_ids' => [$item->id],
    ]));

    expect($parent->title)->toBe('Lung MDT — combined entry')
        ->and((float) $parent->cpd_points)->toBe(2.0)
        ->and($parent->reflection)->toBe(['why_selected' => 'Combined reflection on the whole series.'])
        ->and($parent->categories->pluck('slug')->all())->toBe(['cpd']);

    // Children are hidden from ordinary queries but reachable via the parent.
    expect($user->activities()->count())->toBe(1)
        ->and(Activity::withMerged()->where('user_id', $user->id)->count())->toBe(4)
        ->and($parent->mergedChildren()->count())->toBe(3);

    // The inbox item was promoted through the ordinary approve path.
    $item->refresh();
    expect($item->status)->toBe(InboxItemStatus::Approved)
        ->and($item->activity_id)->not->toBeNull();

    $itemChild = Activity::withMerged()->find($item->activity_id);
    expect($itemChild->merge_unreviewed)->toBeTrue()
        ->and($itemChild->merged_into_activity_id)->toBe($parent->id);

    // Activity children were never individually unreviewed.
    expect($a->fresh()->merge_unreviewed)->toBeFalse()
        ->and($a->fresh()->merged_into_activity_id)->toBe($parent->id)
        ->and($a->fresh()->merged_at)->not->toBeNull();
});

test('merged children 404 on direct routes and vanish from the timeline', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);
    $b = doctorActivity($user);

    $parent = app(ActivityMerger::class)->merge($user, mergePayload([
        'activity_ids' => [$a->id, $b->id],
    ]));

    $this->actingAs($user)
        ->put("/activities/{$a->id}", ['title' => 'x', 'activity_type_slug' => 'course', 'cpd_points' => 1])
        ->assertNotFound();

    $page = $this->actingAs($user)->get('/timeline');
    $page->assertOk();
    $ids = collect($page->viewData('page')['props']['activities'])->pluck('id');
    expect($ids->all())->toBe([$parent->id]);
});

test('merging requires at least two sources', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);

    app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$a->id]]));
})->throws(ValidationException::class);

test('sources must all belong to one appraisal year', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);
    $old = AppraisalPeriod::factory()->for($user)->create(['is_current' => false, 'starts_on' => now()->subYears(2), 'ends_on' => now()->subYear()]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $old->id]);

    app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$a->id, $b->id]]));
})->throws(ValidationException::class);

test('pending and failed items cannot merge', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);
    $pending = InboxItem::factory()->for($user)->create();

    app(ActivityMerger::class)->merge($user, mergePayload([
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$pending->id],
    ]));
})->throws(ValidationException::class);

test('a merged parent cannot be a merge source', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);
    $b = doctorActivity($user);
    $parent = app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$a->id, $b->id]]));

    $c = doctorActivity($user);

    app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$parent->id, $c->id]]));
})->throws(ValidationException::class);

test('another user\'s activities are unavailable', function () {
    $user = ukDoctor();
    $other = ukDoctor();
    $mine = doctorActivity($user);
    $theirs = doctorActivity($other);

    app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$mine->id, $theirs->id]]));
})->throws(ValidationException::class);

test('a merged parent can absorb more sources as a target', function () {
    $user = ukDoctor();
    $a = doctorActivity($user);
    $b = doctorActivity($user);
    $parent = app(ActivityMerger::class)->merge($user, mergePayload(['activity_ids' => [$a->id, $b->id]]));

    $c = doctorActivity($user, ['cpd_points' => 4]);

    $updated = app(ActivityMerger::class)->merge($user, mergePayload([
        'activity_ids' => [$c->id],
        'into_activity_id' => $parent->id,
        'title' => 'Now with a third',
        'cpd_points' => 6,
    ]));

    expect($updated->id)->toBe($parent->id)
        ->and($updated->title)->toBe('Now with a third')
        ->and((float) $updated->cpd_points)->toBe(6.0)
        ->and($updated->mergedChildren()->count())->toBe(3);
});

test('item attachments honour the keep list; kept files live on the child', function () {
    $user = ukDoctor(); // attachment_retention defaults to "ask"
    $a = doctorActivity($user);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $kept = Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(), 'attachable_id' => $item->id,
    ]);
    $dropped = Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(), 'attachable_id' => $item->id,
        'original_filename' => 'slides-photo.jpg',
    ]);

    $parent = app(ActivityMerger::class)->merge($user, mergePayload([
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'keep_attachment_ids' => [$kept->id],
    ]));

    $child = Activity::withMerged()->find($item->fresh()->activity_id);

    expect($kept->fresh()->isPurged())->toBeFalse()
        ->and($kept->fresh()->attachable_id)->toBe($child->id)
        ->and($dropped->fresh()->isPurged())->toBeTrue();

    // The parent's union view includes the child's surviving file.
    expect($parent->allAttachments()->pluck('id'))->toContain($kept->id);
});

test('preview combines sums, spans and unions without touching anything', function () {
    $user = ukDoctor();
    $a = doctorActivity($user, ['cpd_points' => 1, 'starts_on' => '2026-01-06', 'title' => 'Audit meeting January']);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $preview = app(ActivityMerger::class)->preview($user, [$a->id], [$item->id]);

    expect($preview['defaults']['cpd_points'])->toBe(7.0)
        ->and($preview['defaults']['starts_on'])->toBe('2026-01-06')
        ->and($preview['defaults']['title'])->toBe('Audit meeting January')
        ->and($preview['defaults']['category_slugs'])->toContain('cpd')
        ->and(collect($preview['sources'])->pluck('kind')->all())->toBe(['activity', 'inbox_item'])
        ->and($preview['blocking']['pii_item_ids'])->toBe([]);

    // Preview is read-only.
    expect($user->activities()->count())->toBe(1)
        ->and($item->fresh()->status)->toBe(InboxItemStatus::Ready);
});
