<?php

use App\Models\GeneratedReport;
use App\Models\InboxItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('approving an item strips third-party content but keeps metadata', function () {
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'raw_payload' => [
            'subject' => 'ALS certificate',
            'from' => 'no-reply@resus.org.uk',
            'body' => 'Dear Dr Ricketts, congratulations on completing…',
        ],
    ]);

    $item->approve([
        'title' => 'ALS recertification',
        'activity_type_slug' => 'course',
        'cpd_points' => 5,
        'reflection_draft' => [],
        'category_slugs' => [],
        'domain_codes' => [],
        'attribute_codes' => [],
    ]);

    $payload = $item->fresh()->raw_payload;

    expect($payload)->not->toHaveKey('body')
        ->and($payload['subject'])->toBe('ALS certificate')
        ->and($payload['from'])->toBe('no-reply@resus.org.uk')
        ->and($payload)->toHaveKey('redacted_at');
});

test('binning an item deletes it outright, transcript and all', function () {
    $user = ukDoctor();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'raw_payload' => ['transcript' => 'Gastro MDT ramble with case details…'],
    ]);

    $item->dismiss();

    expect(InboxItem::find($item->id))->toBeNull();
});

test('the prune command sweeps stragglers, orphans, stale exports and old failed jobs', function () {
    Storage::fake('local');

    $user = ukDoctor();

    // A resolved-but-unredacted straggler from before redaction existed.
    $straggler = InboxItem::factory()->for($user)->create([
        'status' => 'approved',
        'resolved_at' => now()->subDays(10),
        'raw_payload' => ['subject' => 'Old email', 'body' => 'lingering content'],
    ]);

    // An orphaned file no attachment row references.
    Storage::disk('local')->put("evidence/{$user->id}/orphan.pdf", 'ghost');

    // A referenced file that must survive.
    Storage::disk('local')->put("evidence/{$user->id}/kept.pdf", 'evidence');
    $keeper = InboxItem::factory()->for($user)->ready()->create();
    $keeper->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/kept.pdf",
        'original_filename' => 'kept.pdf',
        'mime_type' => 'application/pdf',
        'size' => 8,
    ]);

    // A stale evidence zip and an old failed job.
    Storage::disk('local')->put("exports/{$user->id}/evidence-1.zip", 'zip');
    $zip = $user->generatedReports()->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'kind' => 'evidence_zip',
        'params' => [],
        'status' => 'ready',
        'content' => "exports/{$user->id}/evidence-1.zip",
    ]);
    $zip->forceFill(['created_at' => now()->subDays(45)])->save();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'old',
        'failed_at' => now()->subDays(45),
    ]);

    $this->artisan('cpd:prune-evidence')->assertSuccessful();

    expect($straggler->fresh()->raw_payload)->not->toHaveKey('body');
    Storage::disk('local')->assertMissing("evidence/{$user->id}/orphan.pdf");
    Storage::disk('local')->assertExists("evidence/{$user->id}/kept.pdf");
    Storage::disk('local')->assertMissing("exports/{$user->id}/evidence-1.zip");
    expect(GeneratedReport::find($zip->id))->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
