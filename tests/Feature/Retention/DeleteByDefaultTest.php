<?php

use App\Models\InboxItem;
use App\Services\AttachmentStore;
use Illuminate\Support\Facades\Storage;

function readyItemWithFile(string $retention = 'ask'): InboxItem
{
    $user = ukDoctor();
    $user->update(['attachment_retention' => $retention]);

    $item = InboxItem::factory()->for($user)->ready()->create();

    Storage::disk('local')->put("evidence/{$user->id}/cert.pdf", '%PDF-1.4 evidence');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/cert.pdf",
        'original_filename' => 'cert.pdf',
        'mime_type' => 'application/pdf',
        'size' => 17,
        'extracted_text' => 'ALS certificate for course completion',
    ]);

    return $item;
}

/** @return array<string, mixed> */
function approvalPayload(InboxItem $item, array $extra = []): array
{
    return array_merge([
        'title' => 'ALS recertification',
        'activity_type_slug' => 'course',
        'cpd_points' => 2,
    ], $extra);
}

beforeEach(fn () => Storage::fake('local'));

test('files are purged to stubs at approval unless explicitly kept', function () {
    $item = readyItemWithFile();
    $attachment = $item->attachments()->sole();

    $item->approve(approvalPayload($item));

    $attachment->refresh();

    expect($attachment->isPurged())->toBeTrue()
        ->and($attachment->extracted_text)->toBe('ALS certificate for course completion');

    Storage::disk('local')->assertMissing($attachment->path);
});

test('a file the user ticked to keep survives approval with its text', function () {
    $item = readyItemWithFile();
    $attachment = $item->attachments()->sole();

    $item->approve(approvalPayload($item, ['keep_attachment_ids' => [$attachment->id]]));

    expect($attachment->refresh()->isPurged())->toBeFalse();
    Storage::disk('local')->assertExists($attachment->path);
});

test('always-keep users keep files without ticking anything', function () {
    $item = readyItemWithFile('always');
    $attachment = $item->attachments()->sole();

    $item->approve(approvalPayload($item));

    expect($attachment->refresh()->isPurged())->toBeFalse();
});

test('never-keep users lose files even if ids are sent', function () {
    $item = readyItemWithFile('never');
    $attachment = $item->attachments()->sole();

    $item->approve(approvalPayload($item, ['keep_attachment_ids' => [$attachment->id]]));

    expect($attachment->refresh()->isPurged())->toBeTrue();
});

test('scrubSourceText redacts payload and purged-stub text but leaves kept-file text', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create([
        'raw_payload' => ['subject' => 'MDT invite', 'body' => 'Full patient discussion notes'],
    ]);

    Storage::disk('local')->put("evidence/{$user->id}/kept.pdf", '%PDF');
    $kept = $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/kept.pdf",
        'original_filename' => 'kept.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4,
        'extracted_text' => 'Certificate text',
    ]);

    $stub = $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/gone.csv",
        'original_filename' => 'gone.csv',
        'mime_type' => 'text/csv',
        'size' => 100,
        'extracted_text' => 'row,data',
        'purged_at' => now(),
    ]);

    $item->scrubSourceText();

    expect($item->fresh()->raw_payload)->not->toHaveKey('body')
        ->and($item->fresh()->raw_payload['subject'])->toBe('MDT invite')
        ->and($stub->refresh()->extracted_text)->toBeNull()
        ->and($kept->refresh()->extracted_text)->toBe('Certificate text');
});

test('the storage quota stops new files but not the pipeline', function () {
    config(['cpd.ingest.user_storage_quota_bytes' => 10]);

    $item = readyItemWithFile();

    $stored = app(AttachmentStore::class)->store(
        item: $item,
        contents: str_repeat('x', 50),
        originalFilename: 'over-quota.txt',
        extension: 'txt',
        fallbackMime: 'text/plain',
    );

    expect($stored)->toBeNull();
});
