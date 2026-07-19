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
