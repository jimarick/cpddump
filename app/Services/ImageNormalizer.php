<?php

namespace App\Services;

use Imagick;
use Throwable;

/**
 * Every image is normalised before storage: decoded (HEIC/TIFF/AVIF included
 * — production Imagick has the delegates), auto-oriented, converted to sRGB,
 * capped at 1600px on the long edge, stripped of all EXIF/GPS metadata and
 * re-encoded as JPEG. What we store is always small, readable by the vision
 * models and free of hidden location data. The as-received bytes are never
 * written to storage.
 */
class ImageNormalizer
{
    /** Extensions treated as images and routed through normalisation. */
    public const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'tiff', 'tif', 'avif', 'bmp',
    ];

    private const MAX_EDGE = 1600;

    private const JPEG_QUALITY = 80;

    /** Decompression-bomb guard: refuse to decode anything this large. */
    private const MAX_PIXELS = 80_000_000;

    /**
     * Returns null when the extension isn't an image or the bytes can't be
     * decoded — callers fall back to storing the original.
     *
     * @return array{bytes: string, mime: string, extension: string}|null
     */
    public function normalize(string $bytes, string $extension): ?array
    {
        if (! in_array(strtolower($extension), self::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        try {
            $probe = new Imagick;
            $probe->pingImageBlob($bytes);
            $pixels = $probe->getImageWidth() * $probe->getImageHeight();
            $probe->clear();

            if ($pixels === 0 || $pixels > self::MAX_PIXELS) {
                return null;
            }

            $image = new Imagick;
            $image->readImageBlob($bytes);

            // Animated GIFs: evidence is the first frame.
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
                $image = $image->getImage();
            }

            // Apply the EXIF orientation flag before we strip it, or every
            // portrait phone photo comes out sideways.
            $image->autoOrient();

            if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            // JPEG has no alpha: composite transparency onto white.
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor('white');
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_EDGE) {
                $image->resizeImage(self::MAX_EDGE, self::MAX_EDGE, Imagick::FILTER_LANCZOS, 1, true);
            }

            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::JPEG_QUALITY);

            $normalized = $image->getImageBlob();
            $image->clear();

            return [
                'bytes' => $normalized,
                'mime' => 'image/jpeg',
                'extension' => 'jpg',
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
