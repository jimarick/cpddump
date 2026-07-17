<?php

use App\Enums\InboxItemStatus;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Models\Project;

test('approving a ready item promotes the full analysis into an activity', function () {
    $user = ukDoctor();
    $project = Project::factory()->for($user)->create();

    $item = InboxItem::factory()->for($user)->ready()->create();
    $attachment = Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
    ]);

    $payload = array_merge($item->ai_analysis, [
        'project_ids' => [$project->id],
    ]);

    $activity = $item->approve($payload);

    expect($activity->title)->toBe('Advanced Life Support — recertification')
        ->and($activity->type->slug)->toBe('course')
        ->and((float) $activity->cpd_points)->toBe(6.0)
        ->and($activity->organisation)->toBe('Resuscitation Council UK')
        ->and($activity->appraisal_period_id)->toBe($user->currentAppraisalPeriod()->id)
        ->and($activity->reflection)->toHaveKeys(['why_selected', 'learning_need', 'practice_change'])
        ->and($activity->categories->pluck('slug')->all())->toBe(['cpd'])
        ->and($activity->frameworkDomains->pluck('code')->all())->toBe(['D1'])
        ->and($activity->frameworkAttributes->pluck('code')->sort()->values()->all())->toBe(['1.1', '1.2'])
        ->and($activity->projects->pluck('id')->all())->toBe([$project->id]);

    // The attachment now belongs to the activity, not the inbox item.
    expect($attachment->fresh()->attachable_type)->toBe($activity->getMorphClass())
        ->and($attachment->fresh()->attachable_id)->toBe($activity->id);

    $item->refresh();
    expect($item->status)->toBe(InboxItemStatus::Approved)
        ->and($item->activity_id)->toBe($activity->id)
        ->and($item->resolved_at)->not->toBeNull();
});

test('promotion only accepts slugs and codes from the user\'s profession framework', function () {
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create();

    $payload = array_merge($item->ai_analysis, [
        'category_slugs' => ['cpd', 'nonexistent-category'],
        'domain_codes' => ['D1', 'D9'],
        'attribute_codes' => ['1.1', '99.9'],
    ]);

    $activity = $item->approve($payload);

    expect($activity->categories->pluck('slug')->all())->toBe(['cpd'])
        ->and($activity->frameworkDomains->pluck('code')->all())->toBe(['D1'])
        ->and($activity->frameworkAttributes->pluck('code')->all())->toBe(['1.1']);
});

test('user edits to the draft win over the AI analysis', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $activity = $item->approve(array_merge($item->ai_analysis, [
        'title' => 'My corrected title',
        'cpd_points' => 3.5,
    ]));

    expect($activity->title)->toBe('My corrected title')
        ->and((float) $activity->cpd_points)->toBe(3.5);
});

test('dismissing an item resolves it without creating an activity', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $item->dismiss();

    expect($item->fresh()->status)->toBe(InboxItemStatus::Dismissed)
        ->and($item->fresh()->activity_id)->toBeNull()
        ->and($user->activities()->count())->toBe(0);
});
