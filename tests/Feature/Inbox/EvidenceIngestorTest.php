<?php

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\FetchLinkContent;
use App\Models\IgnoreRule;
use App\Services\EvidenceIngestor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
});

test('manual evidence creates a pending inbox item and queues analysis', function () {
    $user = ukDoctor();

    $item = app(EvidenceIngestor::class)->ingest($user, EvidenceSource::Manual, [
        'title' => 'Journal club on incidental findings',
        'details' => 'Presented two papers on pulmonary nodule follow-up.',
    ]);

    expect($item->status)->toBe(InboxItemStatus::Pending)
        ->and($item->content_hash)->not->toBeNull();

    Queue::assertPushed(AnalyzeInboxItem::class, fn ($job) => $job->item->is($item));
});

test('a pdf upload is stored and routed through text extraction first', function () {
    $user = ukDoctor();

    $item = app(EvidenceIngestor::class)->ingest(
        $user,
        EvidenceSource::Upload,
        ['title' => 'Certificate upload'],
        [UploadedFile::fake()->create('certificate.pdf', 200, 'application/pdf')],
    );

    expect($item->attachments)->toHaveCount(1)
        ->and($item->attachments->first()->mime_type)->toBe('application/pdf');

    Storage::disk('local')->assertExists($item->attachments->first()->path);

    Queue::assertPushed(ExtractAttachmentText::class);
    Queue::assertNotPushed(AnalyzeInboxItem::class);
});

test('links are routed through content fetching first', function () {
    $user = ukDoctor();

    app(EvidenceIngestor::class)->ingest($user, EvidenceSource::Link, [
        'url' => 'https://www.bmj.com/content/some-article',
    ]);

    Queue::assertPushed(FetchLinkContent::class);
    Queue::assertNotPushed(AnalyzeInboxItem::class);
});

test('an active ignore rule silently drops matching evidence and counts the hit', function () {
    $user = ukDoctor();

    $rule = IgnoreRule::factory()->for($user)->create([
        'source' => EvidenceSource::Calendar,
        'field' => 'title',
        'operator' => 'contains',
        'value' => 'lung mdt',
    ]);

    $item = app(EvidenceIngestor::class)->ingest($user, EvidenceSource::Calendar, [
        'title' => 'Lung MDT — weekly',
        'organiser' => 'someone@nhs.net',
    ]);

    expect($item)->toBeNull()
        ->and($user->inboxItems()->count())->toBe(0)
        ->and($rule->fresh()->hit_count)->toBe(1);

    Queue::assertNothingPushed();
});

test('the same external id is not ingested twice', function () {
    $user = ukDoctor();
    $ingestor = app(EvidenceIngestor::class);

    $first = $ingestor->ingest($user, EvidenceSource::Calendar, ['title' => 'Journal club'], externalId: 'uid-123');
    $second = $ingestor->ingest($user, EvidenceSource::Calendar, ['title' => 'Journal club'], externalId: 'uid-123');

    expect($second->id)->toBe($first->id)
        ->and($user->inboxItems()->count())->toBe(1);
});
