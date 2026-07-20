<?php

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\ActivityMerger;
use Illuminate\Support\Facades\Storage;

test('deleting a merged entry deletes every absorbed entry, their files and inbox rows', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $item = InboxItem::factory()->for($user)->ready()->create();

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'keep_attachment_ids' => [],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    // A file kept on an absorbed child (added post-merge via the child's
    // origin) — must be purged by the cascade.
    $file = Attachment::factory()->for($user)->create([
        'attachable_type' => $a->getMorphClass(), 'attachable_id' => $a->id,
    ]);

    $childIds = $parent->mergedChildren()->pluck('id');

    $parent->delete();

    expect(Activity::withMerged()->whereIn('id', $childIds)->count())->toBe(0)
        ->and(Activity::withMerged()->find($parent->id))->toBeNull()
        ->and(InboxItem::find($item->id))->toBeNull()
        ->and(Attachment::find($file->id))->toBeNull();
});

test('the web delete route takes the whole merged entry with it', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id, $b->id],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    $this->actingAs($user)->delete("/activities/{$parent->id}")->assertRedirect();

    expect(Activity::withMerged()->where('user_id', $user->id)->count())->toBe(0);
});
