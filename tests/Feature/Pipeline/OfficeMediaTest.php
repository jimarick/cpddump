<?php

use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Models\InboxItem;
use App\Services\AttachmentStore;
use App\Services\PdfRasterizer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function officeZipWithMedia(string $mediaPrefix): string
{
    $chart = new Imagick;
    $chart->newPseudoImage(1200, 900, 'plasma:');
    $chart->setImageFormat('png');
    $chartBytes = $chart->getImageBlob();

    expect(strlen($chartBytes))->toBeGreaterThan(51_200);

    $tmp = tempnam(sys_get_temp_dir(), 'cpd-office-');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString("{$mediaPrefix}chart1.png", $chartBytes);
    $zip->addFromString("{$mediaPrefix}icon.png", str_repeat('x', 500)); // decoration, below 50KB
    $zip->addFromString("{$mediaPrefix}diagram.svg", str_repeat('<svg/>', 20_000)); // vector, skipped
    $zip->close();

    $contents = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $contents;
}

function officeItem(string $filename, string $mime, string $zipContents): InboxItem
{
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/{$filename}", $zipContents);
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/{$filename}",
        'original_filename' => $filename,
        'mime_type' => $mime,
        'size' => strlen($zipContents),
    ]);

    return $item;
}

beforeEach(function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');
});

test('substantial images inside a pptx become normalised image attachments', function () {
    $item = officeItem(
        'teaching-deck.pptx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        officeZipWithMedia('ppt/media/'),
    );

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    $derived = $item->attachments()->where('mime_type', 'image/jpeg')->get();

    expect($derived)->toHaveCount(1)
        ->and($derived->first()->original_filename)->toBe('chart1.png (from teaching-deck.pptx)')
        ->and($derived->first()->isImage())->toBeTrue();

    Queue::assertPushed(AnalyzeInboxItem::class);
});

test('docx embedded media extracts from word/media', function () {
    $item = officeItem(
        'report.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        officeZipWithMedia('word/media/'),
    );

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    expect($item->attachments()->where('mime_type', 'image/jpeg')->count())->toBe(1);
});

test('re-running the job does not duplicate derived media', function () {
    $item = officeItem(
        'deck.pptx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        officeZipWithMedia('ppt/media/'),
    );

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));
    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    expect($item->attachments()->where('mime_type', 'image/jpeg')->count())->toBe(1);
});
