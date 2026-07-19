<?php

use App\Models\InboxItem;
use App\Services\PidScanner;
use Illuminate\Support\Facades\Storage;

// 943 476 5919 is the NHS's published example number (valid checksum).
const VALID_NHS = '943 476 5919';

test('the scanner catches checksummed nhs numbers and labelled dobs but not phone numbers', function () {
    $scanner = app(PidScanner::class);

    $flags = $scanner->scan("Patient NHS number: 943 476 5919\nDOB: 12/03/1958\nCall us on 020 7946 0000");

    expect(collect($flags)->pluck('type')->all())->toBe(['nhs_number', 'date_of_birth'])
        ->and($flags[0]['excerpt'])->toBe(VALID_NHS)
        ->and($flags[0]['detected_by'])->toBe('scanner');

    // Ten digits, invalid checksum: ignored.
    expect($scanner->scan('Reference 123 456 7890'))->toBe([]);
});

test('scrubNhsNumbers removes verified numbers from text', function () {
    $result = app(PidScanner::class)->scrubNhsNumbers('Discussed patient 943 476 5919 at MDT');

    expect($result['found'])->toBeTrue()
        ->and($result['text'])->toBe('Discussed patient [NHS number removed] at MDT');
});

test('the pii gate is active only when flagged content persists', function () {
    Storage::fake('local');
    $user = ukDoctor();

    $flagged = ['pii_flags' => [['type' => 'nhs_number', 'excerpt' => VALID_NHS, 'severity' => 'high']]];

    // Flags but nothing stored (email whose body was scrubbed): no gate.
    $ephemeral = InboxItem::factory()->for($user)->ready()->create([
        'source' => 'email',
        'ai_warnings' => $flagged,
    ]);
    expect($ephemeral->piiGateActive())->toBeFalse();

    // Flags plus a stored file: gate.
    $withFile = InboxItem::factory()->for($user)->ready()->create(['ai_warnings' => $flagged]);
    Storage::disk('local')->put("evidence/{$user->id}/scan.jpg", 'jpg');
    $withFile->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/scan.jpg",
        'original_filename' => 'scan.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 3,
    ]);
    expect($withFile->piiGateActive())->toBeTrue();

    // Flags plus user-authored manual text: gate.
    $manual = InboxItem::factory()->for($user)->ready()->create([
        'source' => 'manual',
        'raw_payload' => ['title' => 'Case discussion', 'details' => 'Patient 943 476 5919 reviewed'],
        'ai_warnings' => $flagged,
    ]);
    expect($manual->piiGateActive())->toBeTrue();
});

test('a gated item cannot be approved without acknowledgement', function () {
    Storage::fake('local');
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'ai_warnings' => ['pii_flags' => [['type' => 'patient_name', 'excerpt' => 'John Smith', 'severity' => 'high']]],
    ]);
    Storage::disk('local')->put("evidence/{$user->id}/list.pdf", '%PDF');
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/list.pdf",
        'original_filename' => 'list.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4,
    ]);

    $payload = ['title' => 'MDT', 'activity_type_slug' => 'course', 'cpd_points' => 1];

    $this->actingAs($user)
        ->post("/inbox/{$item->id}/approve", $payload)
        ->assertSessionHasErrors('pii');

    expect($item->fresh()->status->value)->toBe('ready');

    // With the affirmation, approval proceeds and is recorded.
    $this->actingAs($user)
        ->post("/inbox/{$item->id}/approve", $payload + ['pii_ack' => true])
        ->assertSessionHasNoErrors();

    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('approved')
        ->and($fresh->ai_warnings['pii_resolved'])->toBe('affirmed');
});

test('remove patient info purges files and scrubs authored text, lifting the gate', function () {
    Storage::fake('local');
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'source' => 'manual',
        'raw_payload' => ['title' => 'Case', 'details' => 'Patient 943 476 5919 discussed'],
        'ai_warnings' => ['pii_flags' => [['type' => 'nhs_number', 'excerpt' => VALID_NHS, 'severity' => 'high']]],
    ]);
    Storage::disk('local')->put("evidence/{$user->id}/screen.jpg", 'jpg');
    $attachment = $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/screen.jpg",
        'original_filename' => 'screen.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 3,
        'extracted_text' => 'contains 943 476 5919',
    ]);

    $this->actingAs($user)->post("/inbox/{$item->id}/remove-pii")->assertRedirect();

    $fresh = $item->fresh();

    expect($attachment->refresh()->isPurged())->toBeTrue()
        ->and($attachment->extracted_text)->toBeNull()
        ->and($fresh->raw_payload['details'])->toBe('Patient [NHS number removed] discussed')
        ->and($fresh->ai_warnings['pii_resolved'])->toBe('removed')
        ->and($fresh->piiGateActive())->toBeFalse();

    Storage::disk('local')->assertMissing($attachment->path);
});

test('flag excerpts are redacted to type and severity at approval', function () {
    $user = ukDoctor();

    // Dismissal now hard-deletes, so approval is where redaction shows.
    $item = InboxItem::factory()->for($user)->ready()->create([
        'ai_warnings' => ['pii_flags' => [['type' => 'nhs_number', 'excerpt' => VALID_NHS, 'severity' => 'high']]],
        'ai_analysis' => ['title' => 'MDT', 'pii_flags' => [['type' => 'nhs_number', 'excerpt' => VALID_NHS, 'severity' => 'high']]],
    ]);

    $item->approve(['title' => 'MDT', 'activity_type_slug' => 'course', 'cpd_points' => 1]);

    $fresh = $item->fresh();

    expect($fresh->ai_warnings['pii_flags'][0])->not->toHaveKey('excerpt')
        ->and($fresh->ai_analysis['pii_flags'][0])->not->toHaveKey('excerpt')
        ->and($fresh->ai_warnings['pii_flags'][0]['type'])->toBe('nhs_number');
});

test('post-approval remedy scrubs an activity and purges its files', function () {
    Storage::fake('local');
    $user = ukDoctor();

    $activity = $user->activities()->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'activity_type_id' => 1,
        'title' => 'Case review 943 476 5919',
        'details' => 'Discussed patient 943 476 5919.',
        'reflection' => ['learning' => 'The patient 943 476 5919 taught me much'],
    ]);

    Storage::disk('local')->put("evidence/{$user->id}/kept.pdf", '%PDF');
    $attachment = $activity->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/kept.pdf",
        'original_filename' => 'kept.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4,
    ]);

    $this->actingAs($user)->post("/activities/{$activity->id}/remove-pii")->assertRedirect();

    $fresh = $activity->fresh();

    expect($fresh->title)->toBe('Case review [NHS number removed]')
        ->and($fresh->details)->toBe('Discussed patient [NHS number removed].')
        ->and($fresh->reflection['learning'])->toContain('[NHS number removed]')
        ->and($attachment->refresh()->isPurged())->toBeTrue();
});
