<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Models\InboxItem;
use App\Services\EvidenceIngestor;
use Illuminate\Support\Facades\Queue;

test('items beyond the daily cap are stored but their analysis is deferred', function () {
    Queue::fake();
    config(['cpd.ingest.daily_item_cap' => 3]);

    $user = ukDoctor();
    $ingestor = app(EvidenceIngestor::class);

    foreach (range(1, 3) as $i) {
        $ingestor->ingest($user, EvidenceSource::Manual, ['title' => "Item {$i}"]);
    }

    $overflow = $ingestor->ingest($user, EvidenceSource::Manual, ['title' => 'One too many']);

    expect($overflow)->not->toBeNull()
        ->and($overflow->failure_reason)->toContain('Daily dump limit')
        ->and(InboxItem::where('user_id', $user->id)->count())->toBe(4);

    // First three dispatch immediately; the overflow item is delayed.
    Queue::assertPushed(AnalyzeInboxItem::class, function (AnalyzeInboxItem $job) use ($overflow) {
        return $job->item->is($overflow) ? $job->delay !== null : true;
    });

    $delayed = collect(Queue::pushedJobs()[AnalyzeInboxItem::class])
        ->filter(fn ($pushed) => $pushed['job']->item->is($overflow));

    expect($delayed)->toHaveCount(1)
        ->and($delayed->first()['job']->delay)->not->toBeNull();
});

test('items under the cap are analysed without delay', function () {
    Queue::fake();

    $user = ukDoctor();
    $item = app(EvidenceIngestor::class)->ingest($user, EvidenceSource::Manual, ['title' => 'Normal day']);

    expect($item->failure_reason)->toBeNull();

    Queue::assertPushed(AnalyzeInboxItem::class, fn (AnalyzeInboxItem $job) => $job->delay === null);
});

test('the dump address can be regenerated and the old one stops working', function () {
    $user = ukDoctor();
    $user->ensureInboundEmailToken();
    $old = $user->fresh()->inbound_email_token;

    $this->actingAs($user)
        ->post('/settings/evidence/regenerate-address')
        ->assertRedirect();

    $new = $user->fresh()->inbound_email_token;

    expect($new)->not->toBeNull()
        ->and($new)->not->toBe($old);
});
