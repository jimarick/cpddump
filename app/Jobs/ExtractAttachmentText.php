<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\InboxItem;
use App\Services\PdfRasterizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser;
use Throwable;

class ExtractAttachmentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public InboxItem $item) {}

    public function handle(PdfRasterizer $rasterizer): void
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
            $this->compactScannedPdf($rasterizer, $attachment);
        }

        AnalyzeInboxItem::dispatch($item);
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
     * @param  callable(string): string  $callback
     */
    private function withTempFile(string $contents, callable $callback): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cpd-extract-');

        if ($path === false) {
            return '';
        }

        file_put_contents($path, $contents);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
