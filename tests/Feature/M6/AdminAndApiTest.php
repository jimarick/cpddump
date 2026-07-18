<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\TranscribeVoiceNote;
use App\Models\InboxItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

test('admin pages are hidden from non-admins and visible to admins', function () {
    $user = ukDoctor();

    $this->actingAs($user)->get('/admin/users')->assertNotFound();

    $this->artisan('cpd:make-admin', ['email' => $user->email])->assertSuccessful();

    $this->actingAs($user->fresh())->get('/admin/users')
        ->assertInertia(fn ($page) => $page->component('admin/users'));

    $this->actingAs($user->fresh())->get('/admin/usage')
        ->assertInertia(fn ($page) => $page->component('admin/usage'));
});

test('dictation transcribes an uploaded audio clip', function () {
    $user = ukDoctor();

    Ai::fakeTranscriptions(['so basically the MDT today taught me about nodule follow-up']);

    $webm = "\x1A\x45\xDF\xA3".str_repeat("\x00", 512);

    $this->actingAs($user)
        ->post('/ai/transcribe', [
            'audio' => UploadedFile::fake()->createWithContent('dictation.webm', $webm),
        ])
        ->assertOk()
        ->assertJsonPath('text', 'so basically the MDT today taught me about nodule follow-up');
});

test('the companion API accepts a voice note and routes it through transcription', function () {
    Queue::fake([TranscribeVoiceNote::class, AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $token = $user->createToken('tauri')->plainTextToken;

    $m4a = "\x00\x00\x00\x1CftypM4A \x00\x00\x00\x00M4A mp42isom".str_repeat("\x00", 512);

    $this->withToken($token)
        ->postJson('/api/v1/inbox-items', [
            'audio' => UploadedFile::fake()->createWithContent('note.m4a', $m4a),
        ])
        ->assertCreated();

    $item = $user->inboxItems()->firstOrFail();

    expect($item->source)->toBe(EvidenceSource::VoiceNote)
        ->and($item->attachments()->count())->toBe(1);

    Queue::assertPushed(TranscribeVoiceNote::class);
    Queue::assertNotPushed(AnalyzeInboxItem::class);
});

test('the voice note job transcribes then hands off to analysis', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create(['source' => EvidenceSource::VoiceNote, 'raw_payload' => []]);

    Storage::disk('local')->put("evidence/{$user->id}/note.m4a", 'fake-audio');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/note.m4a",
        'original_filename' => 'note.m4a',
        'mime_type' => 'audio/mp4',
        'size' => 10,
    ]);

    Ai::fakeTranscriptions(['I taught the FRCR physics session this evening.']);

    (new TranscribeVoiceNote($item))->handle();

    expect($item->fresh()->raw_payload['transcript'])->toBe('I taught the FRCR physics session this evening.');
    Queue::assertPushed(AnalyzeInboxItem::class);
});

test('the API requires authentication', function () {
    $this->postJson('/api/v1/inbox-items', ['title' => 'x'])->assertUnauthorized();
});

test('everyone is premium during the beta', function () {
    expect(ukDoctor()->isPremium())->toBeTrue();
});
