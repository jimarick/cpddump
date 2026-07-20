<?php

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\ActivityMerger;
use Illuminate\Validation\ValidationException;

test('splitting restores the originals exactly and deletes the parent shell', function () {
    $user = ukDoctor();

    $a = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Original A', 'cpd_points' => 1.5, 'starts_on' => '2026-02-01',
        'reflection' => ['why_selected' => 'My own words.'],
    ]);
    $a->categories()->sync($user->profession->categories()->where('slug', 'cpd')->pluck('id'));
    $file = Attachment::factory()->for($user)->create([
        'attachable_type' => $a->getMorphClass(), 'attachable_id' => $a->id,
    ]);

    $b = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Original B', 'cpd_points' => 2,
    ]);

    $merger = app(ActivityMerger::class);
    $parent = $merger->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged entry', 'activity_type_slug' => 'course', 'cpd_points' => 99,
    ]);

    $released = $merger->unmerge($parent);

    expect(Activity::find($parent->id))->toBeNull()
        ->and($released->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id]);

    $a->refresh();
    expect($a->merged_into_activity_id)->toBeNull()
        ->and($a->title)->toBe('Original A')
        ->and((float) $a->cpd_points)->toBe(1.5)
        ->and($a->reflection)->toBe(['why_selected' => 'My own words.'])
        ->and($a->categories->pluck('slug')->all())->toBe(['cpd'])
        ->and($a->unmerged_at)->not->toBeNull()
        ->and($file->fresh()->isPurged())->toBeFalse()
        ->and($file->fresh()->attachable_id)->toBe($a->id);

    // Both entries are back on the timeline.
    expect($user->activities()->count())->toBe(2);
});

test('an item merged without individual review returns as an activity, never to the inbox', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $merger = app(ActivityMerger::class);
    $parent = $merger->merge($user, [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'title' => 'Merged entry', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    $merger->unmerge($parent);

    // The item stays resolved — nothing reappears in the tray.
    expect($user->inboxItems()->open()->count())->toBe(0);

    $child = Activity::find($item->fresh()->activity_id);
    expect($child)->not->toBeNull()
        ->and($child->merge_unreviewed)->toBeTrue()
        ->and($child->unmerged_at)->not->toBeNull()
        ->and($child->title)->toBe('Advanced Life Support — recertification');
});

test('splitting a plain activity is refused', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);

    app(ActivityMerger::class)->unmerge($a);
})->throws(ValidationException::class);

test('the web unmerge route splits and reports the count', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged entry', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    $this->actingAs($user)
        ->from('/timeline')
        ->post("/activities/{$parent->id}/unmerge")
        ->assertRedirect('/timeline')
        ->assertSessionHas('success', 'Split back into 2 activities.');

    expect($user->activities()->count())->toBe(2);
});

test('users cannot split someone else\'s merged entry', function () {
    $user = ukDoctor();
    $other = ukDoctor();
    $a = Activity::factory()->for($other)->create(['appraisal_period_id' => $other->currentAppraisalPeriod()->id]);
    $b = Activity::factory()->for($other)->create(['appraisal_period_id' => $other->currentAppraisalPeriod()->id]);

    $parent = app(ActivityMerger::class)->merge($other, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged entry', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    $this->actingAs($user)
        ->post("/activities/{$parent->id}/unmerge")
        ->assertForbidden();
});
