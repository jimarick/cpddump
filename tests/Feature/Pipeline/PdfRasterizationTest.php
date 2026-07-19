<?php

use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Models\InboxItem;
use App\Services\PdfRasterizer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

function pageJpeg(string $color = 'white', int $width = 1240, int $height = 1754): string
{
    $image = new Imagick;
    $image->newImage($width, $height, $color);
    $image->setImageFormat('jpeg');

    return $image->getImageBlob();
}

function canRenderPdfs(): bool
{
    try {
        $probe = new Imagick;
        $probe->setResolution(72, 72);
        $probe->readImageBlob(app(PdfRasterizer::class)->compactFromJpegs([pageJpeg()]) ?? '');
        $probe->clear();

        return true;
    } catch (Throwable) {
        return false; // No Ghostscript on this machine (prod has it).
    }
}

test('page jpegs recombine into a single compact pdf', function () {
    $compact = app(PdfRasterizer::class)->compactFromJpegs([
        pageJpeg('white'),
        pageJpeg('gray'),
        pageJpeg('beige'),
    ]);

    expect($compact)->not->toBeNull()
        ->and(str_starts_with($compact, '%PDF'))->toBeTrue()
        ->and((new Parser)->parseContent($compact)->getPages())->toHaveCount(3);
});

test('rasterize declines documents over the page cap or with no pages', function () {
    config(['cpd.ai.max_scanned_pdf_pages' => 2]);

    $threePages = str_repeat('/Type /Page ', 3).'not really a pdf';

    expect(app(PdfRasterizer::class)->rasterize($threePages))->toBeNull()
        ->and(app(PdfRasterizer::class)->rasterize('no pages here'))->toBeNull();
});

test('a scanned pdf is rebuilt as a compact jpeg-page pdf', function () {
    if (! canRenderPdfs()) {
        $this->markTestSkipped('No Ghostscript locally — render path verified on production.');
    }

    // Build a "scan": one large uncompressed page image wrapped as PDF.
    $bigPage = new Imagick;
    $bigPage->newImage(2480, 3508, 'white');
    $bigPage->setImageFormat('pdf');
    $bigPage->setImageCompression(Imagick::COMPRESSION_UNDEFINED);
    $scan = $bigPage->getImagesBlob();

    $compact = app(PdfRasterizer::class)->rasterize($scan);

    expect($compact)->not->toBeNull()
        ->and(strlen($compact))->toBeLessThan(strlen($scan));
});

test('the extraction job swaps oversized or scanned pdfs for compact rebuilds only when smaller', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    // A text PDF under the cap: untouched even though rasterisation is unavailable locally.
    $textPdf = "%PDF-1.4\n1 0 obj<</Type/Page>>endobj\ntrailer\n%%EOF";
    Storage::disk('local')->put("evidence/{$user->id}/small.pdf", $textPdf);
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/small.pdf",
        'original_filename' => 'small.pdf',
        'mime_type' => 'application/pdf',
        'size' => strlen($textPdf),
        'extracted_text' => 'Existing text',
    ]);

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class));

    expect(Storage::disk('local')->get("evidence/{$user->id}/small.pdf"))->toBe($textPdf);

    Queue::assertPushed(AnalyzeInboxItem::class);
});
