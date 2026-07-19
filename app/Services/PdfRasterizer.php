<?php

namespace App\Services;

use Imagick;
use Throwable;

/**
 * Scanned PDFs (no extractable text — photographed certificates, scanner
 * output) are routinely 10-30MB. Rather than store and ship that to the
 * model, render each page at 150 DPI, normalise it like any other image
 * (sRGB, 1600px cap, stripped, JPEG) and recombine into a compact PDF —
 * one file, opens anywhere, ~95% smaller. Requires Ghostscript for the
 * render step (confirmed present on Laravel Cloud via cpd:check-image-support).
 */
class PdfRasterizer
{
    private const DPI = 150;

    private const MAX_EDGE = 1600;

    private const JPEG_QUALITY = 78;

    /**
     * Returns the compact PDF bytes, or null when the document shouldn't or
     * can't be rasterised (over the page cap, no pages, no Ghostscript) —
     * callers keep the original.
     */
    public function rasterize(string $pdfBytes): ?string
    {
        $pages = preg_match_all('#/Type\s*/Page\b#', $pdfBytes);
        $limit = (int) config('cpd.ai.max_scanned_pdf_pages');

        if ($pages === false || $pages === 0 || $pages > $limit) {
            return null;
        }

        try {
            $source = new Imagick;
            $source->setResolution(self::DPI, self::DPI);
            $source->readImageBlob($pdfBytes);

            $pageJpegs = [];

            for ($i = 0; $i < $source->getNumberImages(); $i++) {
                $source->setIteratorIndex($i);
                $page = $source->getImage();

                if ($page->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                    $page->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                }

                $page->setImageBackgroundColor('white');
                $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

                if (max($page->getImageWidth(), $page->getImageHeight()) > self::MAX_EDGE) {
                    $page->resizeImage(self::MAX_EDGE, self::MAX_EDGE, Imagick::FILTER_LANCZOS, 1, true);
                }

                $page->stripImage();
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(self::JPEG_QUALITY);

                $pageJpegs[] = $page->getImageBlob();
                $page->clear();
            }

            $source->clear();

            return $this->compactFromJpegs($pageJpegs);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Wrap page JPEGs into a single PDF (JPEG data embedded via DCT — no
     * recompression). Writing PDF is native ImageMagick; only reading needs
     * Ghostscript.
     *
     * @param  array<int, string>  $pageJpegs
     */
    public function compactFromJpegs(array $pageJpegs): ?string
    {
        if ($pageJpegs === []) {
            return null;
        }

        try {
            $document = new Imagick;

            foreach ($pageJpegs as $jpeg) {
                $page = new Imagick;
                $page->readImageBlob($jpeg);
                $page->setImageFormat('pdf');
                $page->setImageCompression(Imagick::COMPRESSION_JPEG);
                $document->addImage($page);
            }

            $document->resetIterator();
            $blob = $document->getImagesBlob();
            $document->clear();

            return str_starts_with($blob, '%PDF') ? $blob : null;
        } catch (Throwable) {
            return null;
        }
    }
}
