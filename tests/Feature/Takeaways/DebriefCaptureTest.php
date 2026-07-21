<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\FetchLinkContent;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Queue;

test('pasted notes make the capture a debrief, title optional', function () {
    Queue::fake();
    $user = ukDoctor();

    $this->actingAs($user)->post('/inbox', [
        'notes' => "## LI-RADS recap\n**Gadoxetate HBP: no uptake => almost never FNH**\nTODO ask MRI lead about gadoxetate",
        'occurred_on' => '2026-07-20',
    ])->assertRedirect();

    $item = $user->inboxItems()->sole();

    expect($item->source)->toBe(EvidenceSource::Debrief)
        ->and($item->raw_payload['notes'])->toContain('Gadoxetate')
        ->and($item->raw_payload['occurred_on'])->toBe('2026-07-20');

    Queue::assertPushed(AnalyzeInboxItem::class);
    Queue::assertNotPushed(FetchLinkContent::class);
});

test('a debrief with a link gets the page read too', function () {
    Queue::fake();
    $user = ukDoctor();

    $this->actingAs($user)->post('/inbox', [
        'title' => 'Liver MRI masterclass',
        'notes' => 'mRECIST for TACE response, not RECIST 1.1',
        'url' => 'https://example.org/liver-mri',
    ])->assertRedirect();

    expect($user->inboxItems()->sole()->source)->toBe(EvidenceSource::Debrief);

    Queue::assertPushed(FetchLinkContent::class);
    Queue::assertNotPushed(AnalyzeInboxItem::class);
});

test('notes are user-authored: they survive the post-analysis scrub', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create([
        'source' => EvidenceSource::Debrief,
        'raw_payload' => [
            'title' => 'Masterclass',
            'notes' => 'My own typed notes',
            'page_text' => 'Fetched third-party page text',
        ],
    ]);

    $item->scrubSourceText();
    $item->refresh();

    expect($item->raw_payload['notes'])->toBe('My own typed notes')
        ->and($item->raw_payload)->not->toHaveKey('page_text');
});

test('the PII gate covers flagged debrief notes, and remove-pii scrubs them', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => EvidenceSource::Debrief,
        'raw_payload' => ['title' => 'Clinic debrief', 'notes' => 'Discussed patient NHS 943 476 5919 at length'],
        'ai_warnings' => ['pii_flags' => [['type' => 'nhs_number', 'excerpt' => '943 476 5919', 'severity' => 'high']]],
    ]);

    expect($item->piiGateActive())->toBeTrue();

    $this->actingAs($user)->post("/inbox/{$item->id}/remove-pii")->assertRedirect();

    $item->refresh();

    expect($item->raw_payload['notes'])->not->toContain('943 476 5919')
        ->and($item->piiGateActive())->toBeFalse();
});

test('oversized notes are rejected', function () {
    $user = ukDoctor();

    $this->actingAs($user)
        ->from('/inbox')
        ->post('/inbox', ['notes' => str_repeat('a', 50001)])
        ->assertSessionHasErrors('notes');
});
