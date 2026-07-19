<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one place attachment bytes become stored files. Images are normalised
 * (resized, EXIF-stripped, re-encoded as JPEG) before storage — the
 * as-received bytes are never written. The source fingerprint is captured
 * from the ORIGINAL bytes so dedupe still recognises re-uploads.
 */
class AttachmentStore
{
    public function __construct(private ImageNormalizer $images) {}

    public function store(
        InboxItem $item,
        string $contents,
        string $originalFilename,
        string $extension,
        string $fallbackMime,
    ): Attachment {
        $fingerprint = $originalFilename.':'.strlen($contents);

        $normalized = $this->images->normalize($contents, $extension);

        if ($normalized !== null) {
            $contents = $normalized['bytes'];
            $extension = $normalized['extension'];
            $mime = $normalized['mime'];
        } else {
            $mime = $fallbackMime;
        }

        $disk = config('filesystems.default');
        $path = "evidence/{$item->user_id}/".Str::uuid().($extension !== '' ? ".{$extension}" : '');

        Storage::disk($disk)->put($path, $contents);

        return $item->attachments()->create([
            'user_id' => $item->user_id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $originalFilename,
            'mime_type' => $mime,
            'size' => strlen($contents),
            'source_fingerprint' => $fingerprint,
        ]);
    }
}
