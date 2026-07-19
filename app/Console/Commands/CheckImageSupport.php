<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckImageSupport extends Command
{
    protected $signature = 'cpd:check-image-support';

    protected $description = 'Report what image processing this runtime can actually do (Imagick/GD formats, HEIC decode, exec availability)';

    public function handle(): int
    {
        $this->line('PHP '.PHP_VERSION.' on '.php_uname('s').' '.php_uname('m'));
        $this->newLine();

        $this->reportImagick();
        $this->newLine();
        $this->reportGd();
        $this->newLine();
        $this->reportExec();

        return self::SUCCESS;
    }

    private function reportImagick(): void
    {
        if (! extension_loaded('imagick')) {
            $this->warn('Imagick: NOT loaded');

            return;
        }

        $version = \Imagick::getVersion()['versionString'] ?? 'unknown';
        $this->info('Imagick: loaded ('.$version.')');

        foreach (['HEIC', 'HEIF', 'AVIF', 'WEBP', 'TIFF', 'PDF'] as $format) {
            $supported = \Imagick::queryFormats($format) !== [];
            $this->line(sprintf('  %-5s %s', $format, $supported ? '✔ supported' : '✘ not supported'));
        }

        $this->reportPdfRender();
    }

    /**
     * queryFormats('PDF') only proves the delegate is registered; actually
     * rasterising needs the Ghostscript binary present AND the ImageMagick
     * security policy to permit PDF coders (commonly disabled in policy.xml).
     */
    private function reportPdfRender(): void
    {
        $pdf = "%PDF-1.1\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF";

        try {
            $im = new \Imagick;
            $im->setResolution(72, 72);
            $im->readImageBlob($pdf);
            $im->setImageFormat('jpeg');
            $rendered = strlen($im->getImageBlob()) > 0;
            $im->clear();
            $this->line('  PDF→image render '.($rendered ? '✔ works (Ghostscript present, policy allows)' : '✘ produced empty output'));
        } catch (\Throwable $e) {
            $this->line('  PDF→image render ✘ FAILED: '.$e->getMessage());
        }
    }

    private function reportGd(): void
    {
        if (! extension_loaded('gd')) {
            $this->warn('GD: NOT loaded');

            return;
        }

        $this->info('GD: loaded ('.(gd_info()['GD Version'] ?? 'unknown').')');

        $types = imagetypes();

        foreach ([
            'JPEG' => IMG_JPG,
            'PNG' => IMG_PNG,
            'WEBP' => IMG_WEBP,
            'AVIF' => defined('IMG_AVIF') ? IMG_AVIF : 0,
        ] as $label => $flag) {
            $this->line(sprintf('  %-5s %s', $label, $flag && ($types & $flag) ? '✔ supported' : '✘ not supported'));
        }
    }

    /**
     * proc_open availability matters for the fallback plan: bundled static
     * converter binaries (e.g. php-heic-to-jpg) need it if Imagick lacks HEIC.
     */
    private function reportExec(): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (['proc_open', 'exec', 'shell_exec'] as $fn) {
            $available = function_exists($fn) && ! in_array($fn, $disabled, true);
            $this->line(sprintf('%-10s %s', $fn, $available ? '✔ available' : '✘ disabled'));
        }
    }
}
