<?php

use App\Enums\EvidenceSource;
use App\Models\InboxItem;

test('approval promotes edited takeaways, notes and the weekly flag onto the activity', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => EvidenceSource::Debrief,
        'raw_payload' => ['title' => 'Masterclass', 'notes' => 'raw pasted notes'],
    ]);

    $activity = $item->approve([
        ...$item->ai_analysis,
        'nuggets' => [['id' => 'n1', 'text' => 'Edited nugget', 'done' => false]],
        // "Hide from notifications" arrives as items already marked done.
        'actions' => [['id' => 'a1', 'text' => 'Chase the protocol', 'done' => true]],
        'source_notes' => 'raw pasted notes (tidied)',
    ]);

    expect($activity->nuggets)->toBe([['id' => 'n1', 'text' => 'Edited nugget', 'done' => false]])
        ->and($activity->actions[0]['text'])->toBe('Chase the protocol')
        ->and($activity->actions[0]['done'])->toBeTrue()
        ->and($activity->source_notes)->toBe('raw pasted notes (tidied)');
});

test('a client that never sends the keys inherits the AI extraction and the raw notes', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => EvidenceSource::Debrief,
        'raw_payload' => ['title' => 'Masterclass', 'notes' => 'my original notes'],
    ]);

    $payload = collect($item->ai_analysis)
        ->except(['nuggets', 'actions', 'source_notes'])
        ->all();

    $activity = $item->approve($payload);

    expect($activity->nuggets)->toHaveCount(2)
        ->and($activity->actions)->toHaveCount(1)
        ->and($activity->source_notes)->toBe('my original notes');
});

test('without debrief notes, source_notes falls back to the analyst\'s verbatim user_notes', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $item->update(['ai_analysis' => [...$item->ai_analysis, 'user_notes' => 'really useful day — must read up on HBP']]);

    $activity = $item->approve(collect($item->ai_analysis)->except(['source_notes'])->all());

    expect($activity->source_notes)->toBe('really useful day — must read up on HBP');
});

test('explicitly empty lists mean the user cleared them', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $activity = $item->approve([...$item->ai_analysis, 'nuggets' => [], 'actions' => []]);

    expect($activity->nuggets)->toBe([])
        ->and($activity->actions)->toBe([]);
});
