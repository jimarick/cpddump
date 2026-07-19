<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\DismissedCalendarEvent;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\EvidenceIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([AnalyzeInboxItem::class]);
});

test('dismissing an item deletes the row, its analysis and its files', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    Storage::disk('local')->put("evidence/{$user->id}/gone.pdf", '%PDF');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/gone.pdf",
        'original_filename' => 'gone.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4,
    ]);

    $item->dismiss();

    expect(InboxItem::find($item->id))->toBeNull()
        ->and($user->attachments()->count())->toBe(0);

    Storage::disk('local')->assertMissing("evidence/{$user->id}/gone.pdf");
});

test('a binned calendar event never resurrects on the next sync', function () {
    $user = ukDoctor();

    $item = app(EvidenceIngestor::class)->ingest(
        user: $user,
        source: EvidenceSource::Calendar,
        rawPayload: ['title' => 'Weekly MDT'],
        externalId: 'cal-uid-123',
        dispatch: false,
    );

    $item->dismiss();

    expect(DismissedCalendarEvent::where('user_id', $user->id)->where('uid', 'cal-uid-123')->exists())
        ->toBeTrue();

    // The sync tries again next week: nothing is created.
    $again = app(EvidenceIngestor::class)->ingest(
        user: $user,
        source: EvidenceSource::Calendar,
        rawPayload: ['title' => 'Weekly MDT'],
        externalId: 'cal-uid-123',
        dispatch: false,
    );

    expect($again)->toBeNull()
        ->and($user->inboxItems()->count())->toBe(0);
});

test('deleting an activity removes it, its files, and its originating inbox item', function () {
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create();
    Storage::disk('local')->put("evidence/{$user->id}/kept.pdf", '%PDF');
    $attachment = $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/kept.pdf",
        'original_filename' => 'kept.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4,
    ]);

    $activity = $item->approve([
        'title' => 'Audit meeting',
        'activity_type_slug' => 'course',
        'cpd_points' => 1,
        'keep_attachment_ids' => [$attachment->id],
    ]);

    $this->actingAs($user)->delete("/activities/{$activity->id}")->assertRedirect();

    expect(Activity::find($activity->id))->toBeNull()
        ->and(InboxItem::find($item->id))->toBeNull()
        ->and($user->attachments()->count())->toBe(0);

    Storage::disk('local')->assertMissing("evidence/{$user->id}/kept.pdf");
});

test('ai cost rows survive item deletion', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $generation = $user->aiGenerations()->create([
        'purpose' => 'inbox_analysis',
        'provider' => 'openai',
        'model' => 'gpt-test',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'estimated_cost' => 0.001,
        'used_user_key' => false,
        'generatable_type' => $item->getMorphClass(),
        'generatable_id' => $item->id,
    ]);

    $item->dismiss();

    expect($user->aiGenerations()->count())->toBe(1)
        ->and($generation->fresh())->not->toBeNull();
});

test('account deletion removes every file and row', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    Storage::disk('local')->put("evidence/{$user->id}/mine.jpg", 'jpg');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/mine.jpg",
        'original_filename' => 'mine.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 3,
    ]);

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(InboxItem::count())->toBe(0)
        ->and(Attachment::count())->toBe(0);

    Storage::disk('local')->assertMissing("evidence/{$user->id}/mine.jpg");
});
