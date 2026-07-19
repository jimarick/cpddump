<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\AttachmentStore;
use App\Services\PdfRasterizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

class ExtractAttachmentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public InboxItem $item) {}

    public function handle(PdfRasterizer $rasterizer, AttachmentStore $store): void
    {
        $item = $this->item->fresh('attachments');

        if (! $item) {
            return;
        }

        foreach ($item->attachments as $attachment) {
            if (! $attachment->isExtractable() || filled($attachment->extracted_text)) {
                continue;
            }

            try {
                $contents = Storage::disk($attachment->disk)->get($attachment->path);

                if ($contents === null) {
                    continue;
                }

                $text = $this->extract($attachment->mime_type, $contents);

                $attachment->update(['extracted_text' => trim($text) ?: null]);
            } catch (Throwable) {
                // Unreadable (scanned PDF, corrupt office file): leave the
                // text null. PDFs then go to the model directly; anything
                // else gets flagged to the analyst as an unread attachment.
            }
        }

        foreach ($item->attachments as $attachment) {
            try {
                $this->compactScannedPdf($rasterizer, $attachment);
                $this->extractOfficeMedia($store, $item, $attachment);
            } catch (Throwable) {
                // Best-effort: a failed compaction or media pull never
                // blocks analysis of the rest of the item.
            }
        }

        AnalyzeInboxItem::dispatch($item);
    }

    /**
     * Office documents are ZIP archives with their images under a media/
     * folder. Text extraction alone leaves the AI blind to a deck's graphs
     * and photos — pull the substantial embedded images out and store them
     * as image attachments (normalised like any upload), so the vision
     * model sees them alongside the extracted text.
     */
    private function extractOfficeMedia(AttachmentStore $store, InboxItem $item, Attachment $attachment): void
    {
        $prefix = match ($attachment->mime_type) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word/media/',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt/media/',
            default => null,
        };

        if ($prefix === null) {
            return;
        }

        // Already extracted (job retry): derived rows carry a marker fingerprint.
        $marker = "derived:{$attachment->id}:";

        if ($item->attachments()->where('source_fingerprint', 'like', "{$marker}%")->exists()) {
            return;
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);

        if ($contents === null) {
            return;
        }

        foreach ($this->substantialMedia($contents, $prefix) as $media) {
            $store->store(
                item: $item,
                contents: $media['bytes'],
                originalFilename: basename($media['name']).' (from '.$attachment->original_filename.')',
                extension: strtolower(pathinfo($media['name'], PATHINFO_EXTENSION)),
                fallbackMime: 'application/octet-stream',
                fingerprint: $marker.basename($media['name']).':'.strlen($media['bytes']),
            );
        }
    }

    /**
     * The largest embedded raster images, skipping bullet icons and logos.
     *
     * @return array<int, array{name: string, bytes: string}>
     */
    private function substantialMedia(string $zipContents, string $prefix): array
    {
        $minBytes = 51_200; // 50KB — below this it's decoration, not evidence.
        $maxImages = 10;
        $rasterExtensions = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'tif'];

        $media = $this->withTempFile($zipContents, function (string $path) use ($prefix, $minBytes, $maxImages, $rasterExtensions): array {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return [];
            }

            $candidates = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false || ! str_starts_with($stat['name'], $prefix) || $stat['size'] < $minBytes) {
                    continue;
                }

                if (! in_array(strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)), $rasterExtensions, true)) {
                    continue;
                }

                $candidates[] = ['name' => $stat['name'], 'size' => $stat['size']];
            }

            usort($candidates, fn ($a, $b) => $b['size'] <=> $a['size']);

            $media = [];

            foreach (array_slice($candidates, 0, $maxImages) as $candidate) {
                $bytes = $zip->getFromName($candidate['name']);

                if ($bytes !== false && $bytes !== '') {
                    $media[] = ['name' => $candidate['name'], 'bytes' => $bytes];
                }
            }

            $zip->close();

            return $media;
        });

        return $media;
    }

    /**
     * Scanned PDFs (no extractable text) — and outsized "text" PDFs that
     * are really scan hybrids — are re-rendered as compact JPEG-page PDFs:
     * ~95% smaller to store and far cheaper to send to the model.
     */
    private function compactScannedPdf(PdfRasterizer $rasterizer, Attachment $attachment): void
    {
        $maxTextPdfBytes = (int) config('cpd.ingest.text_pdf_max_bytes');

        if (! $attachment->isPdf()) {
            return;
        }

        if (filled($attachment->extracted_text) && $attachment->size <= $maxTextPdfBytes) {
            return;
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);

        if ($contents === null) {
            return;
        }

        $compact = $rasterizer->rasterize($contents);

        // Only swap when it actually helps — a well-compressed original
        // can beat a re-render.
        if ($compact === null || strlen($compact) >= $attachment->size) {
            return;
        }

        Storage::disk($attachment->disk)->put($attachment->path, $compact);
        $attachment->update(['size' => strlen($compact)]);
    }

    private function extract(string $mime, string $contents): string
    {
        return match ($mime) {
            'application/pdf' => (new Parser)->parseContent($contents)->getText(),
            'text/plain' => $contents,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->wordText($contents),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => $this->presentationText($contents),
            default => '',
        };
    }

    private function wordText(string $contents): string
    {
        return $this->withTempFile($contents, function (string $path): string {
            $document = WordIOFactory::load($path, 'Word2007');
            $text = '';

            foreach ($document->getSections() as $section) {
                $text .= $this->elementText($section->getElements());
            }

            return $text;
        });
    }

    /** @param array<int, object> $elements */
    private function elementText(array $elements): string
    {
        $out = '';

        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $text = $element->getText();

                if (is_string($text) && $text !== '') {
                    $out .= $text."\n";

                    continue;
                }
            }

            if (method_exists($element, 'getElements')) {
                $out .= $this->elementText($element->getElements());
            }
        }

        return $out;
    }

    private function presentationText(string $contents): string
    {
        return $this->withTempFile($contents, function (string $path): string {
            $deck = PresentationIOFactory::load($path);
            $out = '';

            foreach ($deck->getAllSlides() as $slide) {
                foreach ($slide->getShapeCollection() as $shape) {
                    if (! $shape instanceof RichText) {
                        continue;
                    }

                    foreach ($shape->getParagraphs() as $paragraph) {
                        foreach ($paragraph->getRichTextElements() as $element) {
                            $out .= $element->getText().' ';
                        }

                        $out .= "\n";
                    }
                }

                $out .= "\n";
            }

            return $out;
        });
    }

    /**
     * The office readers want a real file path, but attachments may live
     * on S3 — round-trip through a temp file.
     *
     * @template T of string|array
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function withTempFile(string $contents, callable $callback): string|array
    {
        $path = tempnam(sys_get_temp_dir(), 'cpd-extract-');

        if ($path === false) {
            throw new \RuntimeException('Could not allocate a temp file for extraction.');
        }

        file_put_contents($path, $contents);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
