<?php

use App\Ai\InboxAnalystAgent;
use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\TranscribeVoiceNote;
use App\Services\AiGateway;
use App\Services\EvidenceIngestor;
use Database\Factories\InboxItemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

test('items with empty payloads but different files never share a content hash', function () {
    Queue::fake();
    Storage::fake('local');

    $user = ukDoctor();
    $ingestor = app(EvidenceIngestor::class);

    $upload = $ingestor->ingest($user, EvidenceSource::Upload, [], [
        UploadedFile::fake()->createWithContent('certificate.pdf', '%PDF-1.4 radiology'),
    ]);

    $voiceNote = $ingestor->ingest($user, EvidenceSource::VoiceNote, [], [
        UploadedFile::fake()->createWithContent('note.m4a', "\x00\x00\x00\x1CftypM4A gastro"),
    ]);

    expect($upload->content_hash)->not->toBe($voiceNote->content_hash);
});

test('a voice note does not inherit another item\'s analysis (the iOS cross-contamination bug)', function () {
    Queue::fake([AnalyzeInboxItem::class, TranscribeVoiceNote::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $ingestor = app(EvidenceIngestor::class);

    // Item A: an upload, already analysed as a radiology certificate.
    $upload = $ingestor->ingest($user, EvidenceSource::Upload, [], [
        UploadedFile::fake()->createWithContent('certificate.pdf', '%PDF-1.4 radiology'),
    ]);
    $upload->update([
        'status' => InboxItemStatus::Ready,
        'ai_analysis' => array_merge(
            (new InboxItemFactory)->exampleAnalysis(),
            ['title' => 'Radiopaedia R25 — Certificate'],
        ),
        'analysed_at' => now(),
    ]);

    // Item B: a voice note about something completely different.
    $voiceNote = $ingestor->ingest($user, EvidenceSource::VoiceNote, [], [
        UploadedFile::fake()->createWithContent('note.webm', "\x1A\x45\xDF\xA3gastro-audio"),
    ]);

    Ai::fakeTranscriptions(['Gastroenterology MDT today, discussed a complex Crohn\'s case.']);
    (new TranscribeVoiceNote($voiceNote))->handle();

    // Transcript changed the content, so the hash must have moved too.
    expect($voiceNote->fresh()->content_hash)->not->toBe($upload->content_hash);

    Ai::fakeAgent(InboxAnalystAgent::class, [array_merge(
        (new InboxItemFactory)->exampleAnalysis(),
        ['title' => 'Gastroenterology MDT — Crohn\'s case discussion'],
    )]);

    (new AnalyzeInboxItem($voiceNote))->handle(app(AiGateway::class));

    $voiceNote->refresh();

    // Its own analysis, not the certificate's.
    expect($voiceNote->ai_analysis['title'])->toContain('Gastroenterology')
        ->and($voiceNote->ai_warnings['possible_duplicate_inbox_item_ids'] ?? [])->toBeEmpty();
});

test('genuinely identical re-uploads still reuse the cached analysis', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $ingestor = app(EvidenceIngestor::class);

    $first = $ingestor->ingest($user, EvidenceSource::Upload, ['title' => 'ALS cert'], [
        UploadedFile::fake()->createWithContent('als.pdf', '%PDF-1.4 als'),
    ]);
    $first->update([
        'status' => InboxItemStatus::Ready,
        'ai_analysis' => (new InboxItemFactory)->exampleAnalysis(),
        'analysed_at' => now(),
    ]);

    $second = $ingestor->ingest($user, EvidenceSource::Upload, ['title' => 'ALS cert'], [
        UploadedFile::fake()->createWithContent('als.pdf', '%PDF-1.4 als'),
    ]);

    expect($second->content_hash)->toBe($first->content_hash);

    (new AnalyzeInboxItem($second))->handle(app(AiGateway::class));

    // No AI call needed — cache reuse marked with the duplicate pointer.
    expect($second->fresh()->status)->toBe(InboxItemStatus::Ready)
        ->and($second->fresh()->ai_warnings['possible_duplicate_inbox_item_ids'])->toBe([$first->id]);
});
