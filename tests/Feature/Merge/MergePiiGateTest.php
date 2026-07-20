<?php

use App\Enums\EvidenceSource;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\ActivityMerger;
use Illuminate\Validation\ValidationException;

function gatedItem($user): InboxItem
{
    // A stored file plus a PII flag keeps the gate active.
    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => EvidenceSource::Upload,
        'ai_warnings' => [
            'pii_flags' => [['type' => 'patient_name', 'severity' => 'high']],
            'missing_evidence' => [],
            'possible_duplicate_activity_ids' => [],
        ],
    ]);

    Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
    ]);

    return $item;
}

test('a PII-gated item blocks the merge until acknowledged', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $item = gatedItem($user);

    try {
        app(ActivityMerger::class)->merge($user, [
            'activity_ids' => [$a->id],
            'inbox_item_ids' => [$item->id],
            'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
        ]);

        $this->fail('Expected the PII gate to block the merge.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('pii')
            ->and($e->errors()['pii_item_ids'])->toBe([(string) $item->id]);
    }

    // Nothing was merged or promoted.
    expect($user->activities()->count())->toBe(1)
        ->and($item->fresh()->activity_id)->toBeNull();
});

test('an ack in the merge payload lifts the gate and records the resolution', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $item = gatedItem($user);

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'pii_acks' => [$item->id],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    expect($parent->mergedChildren()->count())->toBe(2)
        ->and($item->fresh()->ai_warnings['pii_resolved'])->toBe('affirmed');
});

test('a prior remove-pii also clears the way', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $item = gatedItem($user);

    $item->attachments()->get()->each->purgeToStub();
    $item->recordPiiResolution('removed');

    $parent = app(ActivityMerger::class)->merge($user, [
        'activity_ids' => [$a->id],
        'inbox_item_ids' => [$item->id],
        'title' => 'Merged', 'activity_type_slug' => 'course', 'cpd_points' => 1,
    ]);

    expect($parent->mergedChildren()->count())->toBe(2);
});

test('the preview reports which items block', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $gated = gatedItem($user);
    $clean = InboxItem::factory()->for($user)->ready()->create();

    $preview = app(ActivityMerger::class)->preview($user, [$a->id], [$gated->id, $clean->id]);

    expect($preview['blocking']['pii_item_ids'])->toBe([$gated->id]);

    $gatedSource = collect($preview['sources'])->firstWhere('id', $gated->id);
    expect($gatedSource['pii_gate'])->toBeTrue()
        ->and($gatedSource['pii_flags'])->toBe([['type' => 'patient_name', 'severity' => 'high']]);
});
