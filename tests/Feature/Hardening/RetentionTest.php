<?php

use App\Models\InboxItem;
use Illuminate\Support\Facades\Storage;

function storedAttachment(InboxItem $item, string $name = 'certificate.pdf'): string
{
    $path = "evidence/{$item->user_id}/{$name}";
    Storage::disk('local')->put($path, '%PDF-1.4 fake');

    $item->attachments()->create([
        'user_id' => $item->user_id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => $name,
        'mime_type' => 'application/pdf',
        'size' => 14,
    ]);

    return $path;
}

test('binning an inbox item deletes its stored files immediately', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    $path = storedAttachment($item);

    $this->actingAs($user)->delete("/inbox/{$item->id}")->assertRedirect();

    Storage::disk('local')->assertMissing($path);

    expect($item->fresh()->attachments()->count())->toBe(0)
        ->and($item->fresh()->status->value)->toBe('dismissed');
});

test('approved evidence keeps its files until the activity is deleted', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    $path = storedAttachment($item);

    $activity = $item->approve([
        'title' => 'ALS recertification',
        'activity_type_slug' => 'course',
        'cpd_points' => 5,
        'reflection_draft' => [],
        'category_slugs' => [],
        'domain_codes' => [],
        'attribute_codes' => [],
    ]);

    // Approval re-points the file to the activity — nothing deleted.
    Storage::disk('local')->assertExists($path);
    expect($activity->attachments()->count())->toBe(1);

    $this->actingAs($user)->delete("/activities/{$activity->id}")->assertRedirect();

    Storage::disk('local')->assertMissing($path);
    expect($activity->fresh()->attachments()->count())->toBe(0);
});
