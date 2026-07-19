<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\AttachmentStore;
use App\Services\PdfRasterizer;
use App\Support\HtmlToText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser;
use Throwable;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;
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
            if (! $attachment->isExtractable() || filled($attachment->extracted_text) || $attachment->isPurged()) {
                continue;
            }

            try {
                $contents = Storage::disk($attachment->disk)->get($attachment->path);

                if ($contents === null) {
                    continue;
                }

                $text = $this->extract($attachment, $contents);

                $attachment->update(['extracted_text' => trim($text) ?: null]);
            } catch (Throwable) {
                // Unreadable (scanned PDF, corrupt office file): leave the
                // text null. PDFs then go to the model directly; anything
                // else gets flagged to the analyst as an unread attachment.
            }
        }

        foreach ($item->attachments as $attachment) {
            if ($attachment->isPurged()) {
                continue;
            }

            try {
                $this->compactScannedPdf($rasterizer, $attachment);
                $this->extractOfficeMedia($store, $item, $attachment);
                $this->parseEmlAttachment($store, $item, $attachment);
                $this->dropTextOnlyFile($attachment);
            } catch (Throwable) {
                // Best-effort: a failed compaction or media pull never
                // blocks analysis of the rest of the item.
            }
        }

        AnalyzeInboxItem::dispatch($item);
    }

    /**
     * A dragged-in .eml is an email that arrived by hand: parse it with the
     * same machinery as inbound mail, keep body text + attachments, never
     * keep the raw email file.
     */
    private function parseEmlAttachment(AttachmentStore $store, InboxItem $item, Attachment $attachment): void
    {
        if ($attachment->extension() !== 'eml' && $attachment->mime_type !== 'message/rfc822') {
            return;
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);

        if ($contents === null) {
            return;
        }

        $parsed = (new MailMimeParser)->parse($contents, true);

        $body = trim((string) $parsed->getTextContent())
            ?: HtmlToText::convert((string) $parsed->getHtmlContent());

        $attachment->update(['extracted_text' => Str::limit(implode("\n", [
            'From: '.trim((string) $parsed->getHeaderValue(HeaderConsts::FROM)),
            'Subject: '.trim((string) $parsed->getHeaderValue(HeaderConsts::SUBJECT)),
            '',
            $body,
        ]), 30_000, '') ?: null]);

        $marker = "derived:{$attachment->id}:";

        foreach ($parsed->getAllAttachmentParts() as $part) {
            $filename = (string) ($part->getFilename() ?: 'attachment');
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // No nested .eml recursion; everything else follows the allowlist.
            if ($extension === 'eml' || ! in_array($extension, config('cpd.ingest.allowed_extensions'), true)) {
                continue;
            }

            $bytes = (string) $part->getContent();

            if ($bytes === '') {
                continue;
            }

            $store->store(
                item: $item,
                contents: $bytes,
                originalFilename: $filename.' (from '.$attachment->original_filename.')',
                extension: $extension,
                fallbackMime: (string) ($part->getContentType() ?: 'application/octet-stream'),
                fingerprint: $marker.$filename.':'.strlen($bytes),
            );
        }

        $attachment->purgeToStub();
    }

    /**
     * Spreadsheets and plain-text types live on as extracted text only —
     * the file itself is never kept once read.
     */
    private function dropTextOnlyFile(Attachment $attachment): void
    {
        $textOnly = ['csv', 'xlsx', 'xls', 'md', 'rtf'];

        if (in_array($attachment->extension(), $textOnly, true) && filled($attachment->extracted_text)) {
            $attachment->purgeToStub();
        }
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

    private function extract(Attachment $attachment, string $contents): string
    {
        return match ($attachment->extension()) {
            'pdf' => (new Parser)->parseContent($contents)->getText(),
            'txt', 'md' => $contents,
            'rtf' => $this->rtfText($contents),
            'csv' => $this->cappedRows(explode("\n", $contents)),
            'xlsx', 'xls' => $this->spreadsheetText($contents),
            'docx' => $this->wordText($contents),
            'pptx' => $this->presentationText($contents),
            default => match ($attachment->mime_type) {
                'application/pdf' => (new Parser)->parseContent($contents)->getText(),
                'text/plain', 'text/markdown' => $contents,
                'text/rtf', 'application/rtf' => $this->rtfText($contents),
                'text/csv' => $this->cappedRows(explode("\n", $contents)),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel' => $this->spreadsheetText($contents),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->wordText($contents),
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => $this->presentationText($contents),
                default => '',
            },
        };
    }

    /** Crude but serviceable: drop RTF control words and groups, keep the prose. */
    private function rtfText(string $contents): string
    {
        $text = preg_replace('/\\\\\'[0-9a-f]{2}/i', ' ', $contents) ?? '';
        $text = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $text) ?? '';

        return trim(str_replace(['{', '}', '\\'], ' ', $text));
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function cappedRows(array $rows): string
    {
        $capped = implode("\n", array_slice($rows, 0, 500));

        return Str::limit($capped, 1_000_000, "\n[truncated]");
    }

    private function spreadsheetText(string $contents): string
    {
        return $this->withTempFile($contents, function (string $path): string {
            $workbook = SpreadsheetIOFactory::load($path);
            $rows = [];

            foreach ($workbook->getAllSheets() as $sheet) {
                $rows[] = '# '.$sheet->getTitle();

                foreach ($sheet->toArray() as $row) {
                    $rows[] = implode("\t", array_map(fn ($cell) => (string) $cell, $row));

                    if (count($rows) > 520) {
                        break 2;
                    }
                }
            }

            return $this->cappedRows($rows);
        });
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
