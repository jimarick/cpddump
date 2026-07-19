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
    /** Content types that are never evidence, whatever the filename says. */
    private const REJECTED_MIMES = [
        'application/x-dosexec',
        'application/x-executable',
        'application/x-sharedlib',
        'application/x-pie-executable',
        'application/x-mach-binary',
        'application/vnd.microsoft.portable-executable',
    ];

    /** Extension fallbacks for when content sniffing comes back generic. */
    private const EXTENSION_MIMES = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'eml' => 'message/rfc822',
        'md' => 'text/markdown',
        'rtf' => 'application/rtf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(private ImageNormalizer $images) {}

    public function store(
        InboxItem $item,
        string $contents,
        string $originalFilename,
        string $extension,
        string $fallbackMime,
        ?string $fingerprint = null,
    ): ?Attachment {
        $fingerprint ??= $originalFilename.':'.strlen($contents);

        // Sniff the real type — a renamed executable never gets stored,
        // whatever its extension claims.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null;

        if ($detected !== null && in_array($detected, self::REJECTED_MIMES, true)) {
            return null;
        }

        $normalized = $this->images->normalize($contents, $extension);

        if ($normalized !== null) {
            $contents = $normalized['bytes'];
            $extension = $normalized['extension'];
            $mime = $normalized['mime'];
        } else {
            $mime = $fallbackMime === 'application/octet-stream' && $detected !== null
                ? $detected
                : $fallbackMime;

            // Generic bytes with a meaningful extension: classify by name so
            // downstream routing (audio → transcription, office → extraction)
            // still works.
            if ($mime === 'application/octet-stream') {
                $mime = self::EXTENSION_MIMES[strtolower($extension)] ?? $mime;
            }
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
