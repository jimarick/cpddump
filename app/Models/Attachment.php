<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size
 * @property string|null $source_fingerprint
 * @property Carbon|null $purged_at
 * @property string|null $extracted_text
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['purged_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Mime types we can pull text out of server-side. */
    public const EXTRACTABLE_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
        'text/csv',
        'text/markdown',
        'text/rtf',
        'application/rtf',
        'message/rfc822',
    ];

    /** Extensions we can extract from, when the mime type lies or is generic. */
    public const EXTRACTABLE_EXTENSIONS = [
        'pdf', 'docx', 'pptx', 'txt', 'csv', 'xlsx', 'xls', 'eml', 'md', 'rtf',
    ];

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/') || $this->mime_type === 'video/webm';
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isExtractable(): bool
    {
        return in_array($this->mime_type, self::EXTRACTABLE_MIMES, true)
            || in_array($this->extension(), self::EXTRACTABLE_EXTENSIONS, true);
    }

    /**
     * True when the AI will get no view of this file's contents: not an
     * image, not a raw-readable PDF, and no text was extracted.
     */
    public function isUnreadable(): bool
    {
        return blank($this->extracted_text) && ! $this->isImage() && ! $this->isPdf();
    }

    /** Delete the stored file and the record — evidence lifecycle cleanup. */
    public function purge(): void
    {
        Storage::disk($this->disk)->delete($this->path);
        $this->delete();
    }

    /**
     * Delete the stored file but keep the row as an honest metadata stub —
     * used when the content lives on as text (parsed emails, spreadsheets)
     * or when the user chose not to keep a file.
     */
    public function purgeToStub(): void
    {
        Storage::disk($this->disk)->delete($this->path);
        $this->update(['purged_at' => now()]);
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        $disk = Storage::disk($this->disk);

        try {
            return $disk->temporaryUrl($this->path, now()->addMinutes($minutes));
        } catch (\RuntimeException) {
            return $disk->url($this->path);
        }
    }
}
