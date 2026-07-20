<?php

use App\Models\Activity;
use App\Models\GeneratedReport;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Storage;

test('owners can view their attachment inline; strangers get a 404', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $stranger = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    Storage::disk('local')->put("evidence/{$user->id}/cert.pdf", '%PDF-1.4 fake');

    $attachment = $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/cert.pdf",
        'original_filename' => 'certificate.pdf',
        'mime_type' => 'application/pdf',
        'size' => 14,
    ]);

    $this->actingAs($user)
        ->get("/attachments/{$attachment->id}")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($stranger)
        ->get("/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('owners can delete a kept file to a stub; strangers get a 404', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $stranger = ukDoctor();
    $activity = Activity::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/cert.pdf", '%PDF-1.4 fake');

    $attachment = $activity->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/cert.pdf",
        'original_filename' => 'certificate.pdf',
        'mime_type' => 'application/pdf',
        'size' => 14,
        'extracted_text' => 'Certificate for Dr Example',
    ]);

    $this->actingAs($stranger)
        ->delete("/attachments/{$attachment->id}")
        ->assertNotFound();

    $this->actingAs($user)
        ->delete("/attachments/{$attachment->id}")
        ->assertRedirect();

    $attachment->refresh();

    // The file is gone; the row survives as an honest stub with no text copy.
    Storage::disk('local')->assertMissing("evidence/{$user->id}/cert.pdf");
    expect($attachment->purged_at)->not->toBeNull()
        ->and($attachment->extracted_text)->toBeNull()
        ->and($activity->fresh())->not->toBeNull();
});

test('the evidence export bundles a period\'s files into a downloadable zip', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    $activity = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $period->id,
        'title' => 'ALS recertification',
        'starts_on' => $period->starts_on->addMonth(),
    ]);

    Storage::disk('local')->put("evidence/{$user->id}/als.pdf", '%PDF-1.4 fake certificate');

    $activity->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/als.pdf",
        'original_filename' => 'als-certificate.pdf',
        'mime_type' => 'application/pdf',
        'size' => 25,
    ]);

    $this->actingAs($user)->post('/reports/evidence-export')->assertRedirect();

    $report = GeneratedReport::where('user_id', $user->id)->where('kind', 'evidence_zip')->firstOrFail();

    expect($report->status)->toBe('ready')
        ->and($report->params['files'])->toBe(1)
        ->and(Storage::disk('local')->exists($report->content))->toBeTrue();

    $this->actingAs($user)
        ->get("/reports/{$report->id}/download")
        ->assertOk()
        ->assertDownload();

    // Strangers cannot download it.
    $this->actingAs(ukDoctor())
        ->get("/reports/{$report->id}/download")
        ->assertNotFound();

    // The zip actually contains the file under the activity's folder.
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($report->content));

    expect($zip->numFiles)->toBe(1)
        ->and($zip->getNameIndex(0))->toContain('ALS recertification')
        ->and($zip->getNameIndex(0))->toContain('als-certificate.pdf');

    $zip->close();
});

test('an evidence export with no files fails with a readable reason', function () {
    Storage::fake('local');

    $user = ukDoctor();

    $this->actingAs($user)->post('/reports/evidence-export')->assertRedirect();

    $report = GeneratedReport::where('user_id', $user->id)->where('kind', 'evidence_zip')->firstOrFail();

    expect($report->status)->toBe('failed')
        ->and($report->params['failure_reason'])->toContain('No evidence files');
});

test('deleting an evidence export deletes the zip from disk', function () {
    Storage::fake('local');

    $user = ukDoctor();

    Storage::disk('local')->put("exports/{$user->id}/evidence-99.zip", 'zip-bytes');

    $report = $user->generatedReports()->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'kind' => 'evidence_zip',
        'params' => [],
        'status' => 'ready',
        'content' => "exports/{$user->id}/evidence-99.zip",
    ]);

    $this->actingAs($user)->delete("/reports/{$report->id}")->assertRedirect();

    Storage::disk('local')->assertMissing("exports/{$user->id}/evidence-99.zip");
});
