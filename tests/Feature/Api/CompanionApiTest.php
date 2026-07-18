<?php

use App\Ai\TextAssistAgent;
use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\FetchLinkContent;
use App\Models\Attachment;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

// ── Auth ────────────────────────────────────────────────────────────────

test('valid credentials exchange for a bearer token', function () {
    $user = ukDoctor();

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => "James's iPhone",
    ])->assertCreated();

    $token = $response->json('token');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($response->json('user.email'))->toBe($user->email)
        ->and($response->json('user.period.id'))->toBe($user->currentAppraisalPeriod()->id);

    $this->withToken($token)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

test('wrong password is rejected', function () {
    $user = ukDoctor();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'not-the-password',
        'device_name' => 'iPhone',
    ])->assertStatus(422);
});

test('two-factor users must supply a valid TOTP code', function () {
    $user = ukDoctor();

    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY-CODE-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $base = ['email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'];

    $this->postJson('/api/v1/auth/token', $base)->assertStatus(422);

    $this->postJson('/api/v1/auth/token', $base + ['code' => '000000'])->assertStatus(422);

    $this->postJson('/api/v1/auth/token', $base + ['code' => $engine->getCurrentOtp($secret)])
        ->assertCreated();

    $this->postJson('/api/v1/auth/token', $base + ['recovery_code' => 'RECOVERY-CODE-1'])
        ->assertCreated();
});

test('revoking the token signs the device out', function () {
    $user = ukDoctor();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

// ── Inbox review ────────────────────────────────────────────────────────

test('the inbox list returns only the user\'s open items', function () {
    $user = ukDoctor();
    $other = ukDoctor();

    $open = InboxItem::factory()->for($user)->ready()->create();
    InboxItem::factory()->for($user)->create(['status' => InboxItemStatus::Dismissed, 'resolved_at' => now()]);
    InboxItem::factory()->for($other)->ready()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/inbox-items')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $open->id)
        ->assertJsonPath('items.0.status', 'ready');
});

test('the item detail includes analysis and attachment urls', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    $attachment = Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/inbox-items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('item.ai_analysis.title', $item->ai_analysis['title'])
        ->assertJsonPath('item.attachments.0.url', "/api/v1/attachments/{$attachment->id}");
});

test('items belonging to someone else are forbidden', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for(ukDoctor())->ready()->create();

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/inbox-items/{$item->id}")->assertForbidden();
    $this->postJson("/api/v1/inbox-items/{$item->id}/approve", $item->ai_analysis)->assertForbidden();
    $this->deleteJson("/api/v1/inbox-items/{$item->id}")->assertForbidden();
});

test('approving over the API creates the activity', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/v1/inbox-items/{$item->id}/approve", array_merge($item->ai_analysis, [
        'title' => 'Edited on the phone',
    ]))->assertOk();

    $activity = $user->activities()->findOrFail($response->json('activity_id'));

    expect($activity->title)->toBe('Edited on the phone')
        ->and($item->fresh()->status)->toBe(InboxItemStatus::Approved);
});

test('approving twice is rejected', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/inbox-items/{$item->id}/approve", $item->ai_analysis)->assertOk();
    $this->postJson("/api/v1/inbox-items/{$item->id}/approve", $item->ai_analysis)->assertStatus(422);
});

test('binning over the API purges attachments and can add an ignore rule', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    Attachment::factory()->for($user)->create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/inbox-items/{$item->id}", [
        'ignore_rule' => ['field' => 'title', 'operator' => 'contains', 'value' => 'newsletter'],
    ])->assertOk()->assertJsonPath('status', 'dismissed');

    expect($item->fresh()->status)->toBe(InboxItemStatus::Dismissed)
        ->and($item->attachments()->count())->toBe(0)
        ->and($user->ignoreRules()->where('value', 'newsletter')->exists())->toBeTrue();
});

test('retry resets a failed item and re-queues analysis', function () {
    Queue::fake([AnalyzeInboxItem::class]);

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create([
        'status' => InboxItemStatus::Failed,
        'failure_reason' => 'The AI provider timed out.',
        'raw_payload' => ['title' => 'MDT meeting'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/inbox-items/{$item->id}/retry")
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    expect($item->fresh()->failure_reason)->toBeNull();
    Queue::assertPushed(AnalyzeInboxItem::class);
});

// ── Capture ─────────────────────────────────────────────────────────────

test('sharing a url creates a link item and fetches the page', function () {
    Queue::fake([FetchLinkContent::class]);

    $user = ukDoctor();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/inbox-items', [
        'url' => 'https://rcemlearning.co.uk/sepsis',
    ])->assertCreated();

    $item = $user->inboxItems()->firstOrFail();

    expect($item->source)->toBe(EvidenceSource::Link)
        ->and($item->raw_payload['url'])->toBe('https://rcemlearning.co.uk/sepsis');

    Queue::assertPushed(FetchLinkContent::class);
});

// ── Timeline ────────────────────────────────────────────────────────────

test('the activities timeline is scoped, paginated and read-only', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    $item->approve($item->ai_analysis);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/activities')->assertOk();

    expect($response->json('activities'))->toHaveCount(1)
        ->and($response->json('activities.0.type.slug'))->toBe('course')
        ->and($response->json('meta.total'))->toBe(1);

    $id = $response->json('activities.0.id');

    $this->getJson("/api/v1/activities/{$id}")
        ->assertOk()
        ->assertJsonPath('activity.title', $item->ai_analysis['title']);

    // No write routes exist for activities in the API.
    $this->putJson("/api/v1/activities/{$id}", [])->assertStatus(405);
});

// ── Reference & stats ───────────────────────────────────────────────────

test('reference data includes the profession framework', function () {
    Sanctum::actingAs(ukDoctor());

    $response = $this->getJson('/api/v1/reference')->assertOk();

    expect(collect($response->json('activity_types'))->pluck('slug'))->toContain('course')
        ->and($response->json('domains'))->not->toBeEmpty()
        ->and($response->json('reflection_prompts'))->not->toBeEmpty()
        ->and($response->json('periods'))->toHaveCount(1);
});

test('stats power the banner', function () {
    $user = ukDoctor();
    InboxItem::factory()->for($user)->ready()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/stats')
        ->assertOk()
        ->assertJsonPath('stats.awaiting', 1)
        ->assertJsonPath('stats.activities', 0);
});

// ── AI assist ───────────────────────────────────────────────────────────

test('the sparkle button works over the API', function () {
    Ai::fakeAgent(TextAssistAgent::class, [
        ['text' => 'A polished reflection.'],
    ]);

    Sanctum::actingAs(ukDoctor());

    $this->postJson('/api/v1/ai/text-assist', [
        'field' => 'Reflection — what was learned',
        'text' => 'learned sepsis stuff',
    ])->assertOk()->assertJsonPath('text', 'A polished reflection.');
});

test('dictation transcribes over the API', function () {
    Ai::fakeTranscriptions(['the audit showed our door-to-needle time improved']);

    Sanctum::actingAs(ukDoctor());

    $webm = "\x1A\x45\xDF\xA3".str_repeat("\x00", 512);

    $this->postJson('/api/v1/ai/transcribe', [
        'audio' => \Illuminate\Http\UploadedFile::fake()->createWithContent('note.webm', $webm),
    ])->assertOk()->assertJsonPath('text', 'the audit showed our door-to-needle time improved');
});
