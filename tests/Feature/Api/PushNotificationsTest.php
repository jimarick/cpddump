<?php

use App\Enums\InboxItemStatus;
use App\Models\InboxItem;
use App\Models\PushToken;
use App\Notifications\InboxItemFailed;
use App\Notifications\InboxItemReady;
use App\Notifications\WeeklyNudge;
use App\Services\Apns\ApnsClient;
use App\Services\Apns\ApnsResponse;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

// ── Token registry ──────────────────────────────────────────────────────

test('a device registers its push token', function () {
    $user = ukDoctor();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/push-tokens', [
        'token' => str_repeat('a', 64),
        'platform' => 'ios',
        'device_name' => "James's iPhone",
    ])->assertCreated();

    expect($user->pushTokens()->count())->toBe(1);

    // Re-registering the same token updates in place, never duplicates.
    $this->postJson('/api/v1/push-tokens', [
        'token' => str_repeat('a', 64),
        'platform' => 'ios',
        'device_name' => "James's iPad",
    ])->assertCreated();

    expect($user->pushTokens()->count())->toBe(1)
        ->and($user->pushTokens()->first()->device_name)->toBe("James's iPad");
});

test('a token follows the device to whichever account signs in', function () {
    $previous = ukDoctor();
    $current = ukDoctor();

    $token = PushToken::factory()->for($previous)->create();

    Sanctum::actingAs($current);

    $this->postJson('/api/v1/push-tokens', [
        'token' => $token->token,
        'platform' => 'ios',
        'device_name' => 'Shared iPhone',
    ])->assertCreated();

    expect(PushToken::count())->toBe(1)
        ->and($token->fresh()->user_id)->toBe($current->id);
});

test('registration requires auth and a known platform', function () {
    $this->postJson('/api/v1/push-tokens', [
        'token' => str_repeat('a', 64),
        'platform' => 'ios',
        'device_name' => 'iPhone',
    ])->assertUnauthorized();

    Sanctum::actingAs(ukDoctor());

    $this->postJson('/api/v1/push-tokens', [
        'token' => str_repeat('a', 64),
        'platform' => 'android',
        'device_name' => 'Pixel',
    ])->assertStatus(422);
});

test('signing out deletes the user\'s push tokens', function () {
    $user = ukDoctor();
    $bearer = $user->createToken('iPhone')->plainTextToken;
    PushToken::factory()->for($user)->create();

    $this->withToken($bearer)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($user->pushTokens()->count())->toBe(0);
});

// ── Ready / Failed event pushes ─────────────────────────────────────────

test('an item turning ready pushes the draft title, points and badge', function () {
    Notification::fake();

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    $item->update([
        'status' => InboxItemStatus::Ready,
        'ai_analysis' => ['title' => 'Teaching debrief after ward round', 'cpd_points' => 1],
        'analysed_at' => now(),
    ]);

    Notification::assertSentTo($user, InboxItemReady::class, function (InboxItemReady $notification) use ($user, $item) {
        $payload = $notification->toApns($user);

        return $payload['aps']['alert']['title'] === 'Filed and ready to review'
            && $payload['aps']['alert']['body'] === 'Teaching debrief after ward round · 1 CPD pt'
            && $payload['aps']['badge'] === 1
            && $payload['aps']['sound'] === 'default'
            && $payload['inbox_item_id'] === $item->id;
    });
});

test('an item turning failed pushes a named couldn\'t-read alert', function () {
    Notification::fake();

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create([
        'raw_payload' => ['title' => 'Grand round slides'],
    ]);

    $item->update([
        'status' => InboxItemStatus::Failed,
        'failure_reason' => 'Analysis failed. You can retry, or fill the details in manually.',
    ]);

    Notification::assertSentTo($user, InboxItemFailed::class, function (InboxItemFailed $notification) use ($user, $item) {
        $payload = $notification->toApns($user);

        return $payload['aps']['alert']['title'] === "Couldn't read that"
            && str_contains($payload['aps']['alert']['body'], 'Grand round slides')
            && $payload['aps']['badge'] === 1
            && $payload['inbox_item_id'] === $item->id;
    });
});

test('items born ready and resolving transitions stay silent', function () {
    Notification::fake();

    $user = ukDoctor();

    // Recurring drafts are created already-Ready — expected, not news.
    $item = InboxItem::factory()->for($user)->ready()->create();

    // Approving (or binning) is the user's own action, not a server event.
    $item->update(['status' => InboxItemStatus::Approved, 'resolved_at' => now()]);

    Notification::assertNothingSent();
});

// ── Channel behaviour ───────────────────────────────────────────────────

test('tokens apns reports dead are pruned, live ones kept', function () {
    $user = ukDoctor();
    $dead = PushToken::factory()->for($user)->create();
    $live = PushToken::factory()->for($user)->create();

    $this->mock(ApnsClient::class, function ($mock) use ($dead) {
        $mock->shouldReceive('push')->twice()->andReturnUsing(
            fn (string $token) => $token === $dead->token
                ? new ApnsResponse(410, 'Unregistered')
                : new ApnsResponse(200),
        );
    });

    Notification::sendNow($user, new WeeklyNudge(3));

    expect(PushToken::whereKey($dead->id)->exists())->toBeFalse()
        ->and(PushToken::whereKey($live->id)->exists())->toBeTrue();
});

// ── Weekly nudge ────────────────────────────────────────────────────────

test('the weekly nudge reaches only opted-in users with a backlog', function () {
    Notification::fake();

    $due = ukDoctor();
    $due->update(['push_weekly_nudge_enabled' => true]);
    PushToken::factory()->for($due)->create();
    InboxItem::factory()->for($due)->ready()->create();
    InboxItem::factory()->for($due)->failed()->create();

    $emptyTray = ukDoctor();
    $emptyTray->update(['push_weekly_nudge_enabled' => true]);
    PushToken::factory()->for($emptyTray)->create();

    $optedOut = ukDoctor();
    PushToken::factory()->for($optedOut)->create();
    InboxItem::factory()->for($optedOut)->ready()->create();

    $noDevice = ukDoctor();
    $noDevice->update(['push_weekly_nudge_enabled' => true]);
    InboxItem::factory()->for($noDevice)->ready()->create();

    $this->artisan('cpd:send-push-nudges')->assertSuccessful();

    Notification::assertSentTo($due, WeeklyNudge::class, function (WeeklyNudge $notification) use ($due) {
        $payload = $notification->toApns($due);

        return $notification->awaiting === 2
            && $payload['aps']['alert']['title'] === "Don't let it pile up"
            && $payload['aps']['alert']['body'] === "2 things waiting for review — approve or bin, that's the job."
            && $payload['aps']['badge'] === 2
            && ! array_key_exists('inbox_item_id', $payload);
    });

    Notification::assertNotSentTo([$emptyTray, $optedOut, $noDevice], WeeklyNudge::class);

    // Singular backlog reads as English, not as a template.
    expect((new WeeklyNudge(1))->toApns($due)['aps']['alert']['body'])
        ->toBe("1 thing waiting for review — approve or bin, that's the job.");
});
