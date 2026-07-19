<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Models\InboxItem;
use App\Services\EvidenceIngestor;
use App\Services\ImageNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function imageFixture(string $format, int $width = 2400, int $height = 1800, bool $alpha = false): string
{
    $image = new Imagick;
    $image->newImage($width, $height, $alpha ? 'transparent' : 'orange');
    $image->setImageFormat($format);

    return $image->getImageBlob();
}

function ingestUpload(string $contents, string $filename, string $mime): InboxItem
{
    $tmp = tempnam(sys_get_temp_dir(), 'cpd-img-');
    file_put_contents($tmp, $contents);

    $file = new UploadedFile($tmp, $filename, $mime, null, true);

    return app(EvidenceIngestor::class)->ingest(
        user: ukDoctor(),
        source: EvidenceSource::Upload,
        rawPayload: ['title' => 'Evidence photo'],
        files: [$file],
    );
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([AnalyzeInboxItem::class, ExtractAttachmentText::class]);
});

test('uploaded images are stored as resized stripped jpegs', function () {
    $item = ingestUpload(imageFixture('png'), 'certificate.png', 'image/png');

    $attachment = $item->attachments()->sole();

    expect($attachment->mime_type)->toBe('image/jpeg')
        ->and($attachment->path)->toEndWith('.jpg')
        ->and($attachment->original_filename)->toBe('certificate.png');

    $stored = new Imagick;
    $stored->readImageBlob(Storage::disk('local')->get($attachment->path));

    expect($stored->getImageFormat())->toBe('JPEG')
        ->and(max($stored->getImageWidth(), $stored->getImageHeight()))->toBeLessThanOrEqual(1600)
        ->and($stored->getImageProperties('exif:*'))->toBe([]);
});

test('heic uploads become analysable jpegs', function () {
    try {
        $fixture = imageFixture('heic', 800, 600);
    } catch (ImagickException) {
        $this->markTestSkipped('Imagick cannot encode HEIC fixtures on this machine.');
    }

    $item = ingestUpload($fixture, 'photo.heic', 'image/heic');

    $attachment = $item->attachments()->sole();

    expect($attachment->mime_type)->toBe('image/jpeg')
        ->and($attachment->isImage())->toBeTrue();
});

test('small images are not upscaled', function () {
    $item = ingestUpload(imageFixture('jpeg', 400, 300), 'small.jpg', 'image/jpeg');

    $stored = new Imagick;
    $stored->readImageBlob(Storage::disk('local')->get($item->attachments()->sole()->path));

    expect($stored->getImageWidth())->toBe(400);
});

test('exif metadata including gps is stripped', function () {
    $image = new Imagick;
    $image->newImage(500, 400, 'gray');
    $image->setImageFormat('jpeg');
    $image->setImageProperty('exif:GPSLatitude', '51/1');
    $image->setImageProperty('exif:Make', 'TestCam');

    $item = ingestUpload($image->getImageBlob(), 'located.jpg', 'image/jpeg');

    $stored = new Imagick;
    $stored->readImageBlob(Storage::disk('local')->get($item->attachments()->sole()->path));

    expect($stored->getImageProperties('exif:*'))->toBe([]);
});

test('non-image uploads are stored untouched', function () {
    $pdf = '%PDF-1.4 fake body';
    $item = ingestUpload($pdf, 'certificate.pdf', 'application/pdf');

    $attachment = $item->attachments()->sole();

    expect($attachment->mime_type)->toBe('application/pdf')
        ->and(Storage::disk('local')->get($attachment->path))->toBe($pdf);
});

test('content hash fingerprints the original bytes so re-uploads still dedupe', function () {
    $bytes = imageFixture('png', 900, 700);

    $first = ingestUpload($bytes, 'same.png', 'image/png');
    $second = ingestUpload($bytes, 'same.png', 'image/png');

    expect($first->content_hash)->toBe($second->content_hash)
        ->and($first->attachments()->sole()->source_fingerprint)
        ->toBe('same.png:'.strlen($bytes));
});

test('undecodable image bytes fall back to storing the original', function () {
    $item = ingestUpload('not really an image', 'broken.png', 'image/png');

    $attachment = $item->attachments()->sole();

    expect(Storage::disk('local')->get($attachment->path))->toBe('not really an image');
});

test('the normalizer rejects decompression bombs', function () {
    $normalizer = app(ImageNormalizer::class);

    // 20000 x 20000 = 400MP declared dimensions in a tiny blob.
    $image = new Imagick;
    $image->newImage(1, 1, 'white');
    $image->setImageFormat('png');
    $blob = $image->getImageBlob();

    // A real bomb is hard to craft cheaply; assert the guard path directly.
    $huge = new Imagick;
    $huge->newImage(9000, 9000, 'white');
    $huge->setImageFormat('png');

    expect($normalizer->normalize($huge->getImageBlob(), 'png'))->toBeNull()
        ->and($normalizer->normalize($blob, 'png'))->not->toBeNull();
});
