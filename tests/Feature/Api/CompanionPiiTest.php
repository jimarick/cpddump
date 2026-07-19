<?php

use App\Models\InboxItem;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function gatedApiItem(): array
{
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => 'manual',
        'raw_payload' => ['title' => 'Case', 'details' => 'Patient 943 476 5919 discussed'],
        'ai_warnings' => ['pii_flags' => [['type' => 'nhs_number', 'excerpt' => '943 476 5919', 'severity' => 'high']]],
    ]);

    Storage::disk('local')->put("evidence/{$user->id}/scan.jpg", 'jpg');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/scan.jpg",
        'original_filename' => 'scan.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 3,
    ]);

    return [$user, $item];
}

beforeEach(fn () => Storage::fake('local'));

test('the API approve endpoint enforces the pii gate like the web', function () {
    [$user, $item] = gatedApiItem();
    Sanctum::actingAs($user);

    $payload = ['title' => 'MDT', 'activity_type_slug' => 'course', 'cpd_points' => 1];

    $this->postJson("/api/v1/inbox-items/{$item->id}/approve", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('pii');

    expect($item->fresh()->status->value)->toBe('ready');

    $this->postJson("/api/v1/inbox-items/{$item->id}/approve", $payload + ['pii_ack' => true])
        ->assertOk();

    expect($item->fresh()->ai_warnings['pii_resolved'])->toBe('affirmed');
});

test('the API serialiser exposes pii_gate and trims raw payloads', function () {
    [$user, $item] = gatedApiItem();
    Sanctum::actingAs($user);

    $item->update([
        'raw_payload' => $item->raw_payload + ['body' => 'raw email body text'],
    ]);

    $this->getJson("/api/v1/inbox-items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('item.pii_gate', true)
        ->assertJsonPath('item.raw_payload.title', 'Case')
        ->assertJsonMissingPath('item.raw_payload.body');
});

test('the API remove-pii endpoint purges files, scrubs text and lifts the gate', function () {
    [$user, $item] = gatedApiItem();
    Sanctum::actingAs($user);

    $response = $this->postJson("/api/v1/inbox-items/{$item->id}/remove-pii")
        ->assertOk()
        ->assertJsonPath('item.pii_gate', false)
        ->assertJsonPath('item.attachments.0.purged', true);

    expect($response->json('item.attachments.0'))->not->toHaveKey('url')
        ->and($item->fresh()->raw_payload['details'])->toBe('Patient [NHS number removed] discussed')
        ->and($item->fresh()->ai_warnings['pii_resolved'])->toBe('removed');

    Storage::disk('local')->assertMissing("evidence/{$user->id}/scan.jpg");
});

test('purged activity attachments carry the purged flag and no url', function () {
    [$user, $item] = gatedApiItem();
    Sanctum::actingAs($user);

    $activity = $item->approve([
        'title' => 'MDT',
        'activity_type_slug' => 'course',
        'cpd_points' => 1,
        'pii_ack' => true,
        // No keep ids: the file purges to a stub at approval.
    ]);

    $this->getJson("/api/v1/activities/{$activity->id}")
        ->assertOk()
        ->assertJsonPath('activity.attachments.0.purged', true)
        ->assertJsonMissingPath('activity.attachments.0.url');
});
