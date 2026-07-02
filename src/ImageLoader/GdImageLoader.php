<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;

/**
 * OWNER: Developer A.
 *
 * Decodes PNG/JPEG via ext-gd into an {@see InMemoryRaster}. Palette and
 * grayscale images are normalized to truecolor, and GD's 7-bit alpha (0 opaque
 * .. 127 transparent) is expanded to the 0-255 range the rest of the library
 * uses. CMYK JPEGs (which GD cannot read faithfully) are rejected with a clear
 * {@see UnsupportedImageException} pointing at the Imagick adapter.
 */
final class GdImageLoader implements ImageLoaderInterface
{
    public function supports(ImageSource $source): bool
    {
        return in_array($source->detectedFormat(), [ImageFormat::PNG, ImageFormat::JPEG], true);
    }

    public function load(ImageSource $source): Raster
    {
        $bytes = stream_get_contents($source->stream());
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Image source produced no bytes.');
        }

        if ($source->detectedFormat() === ImageFormat::JPEG && $this->jpegComponentCount($bytes) === 4) {
            throw new UnsupportedImageException(
                'CMYK JPEG is not supported by the GD loader; install ext-imagick and use the Imagick adapter.',
            );
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidImageException('GD could not decode the image (corrupt or unsupported).');
        }

        try {
            imagepalettetotruecolor($image);
            imagesavealpha($image, true);

            $width = imagesx($image);
            $height = imagesy($image);

            /** @var list<ColorRGBA> $pixels */
            $pixels = [];
            $hasAlpha = false;
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $argb = imagecolorat($image, $x, $y);
                    $gdAlpha = ($argb >> 24) & 0x7F;
                    $alpha = (int) round((127 - $gdAlpha) * 255 / 127);
                    if ($alpha < 255) {
                        $hasAlpha = true;
                    }
                    $pixels[] = new ColorRGBA(($argb >> 16) & 0xFF, ($argb >> 8) & 0xFF, $argb & 0xFF, $alpha);
                }
            }
        } finally {
            imagedestroy($image);
        }

        return new InMemoryRaster($width, $height, $pixels, $hasAlpha);
    }

    /**
     * Reads the component count (Nf) from a JPEG's Start-Of-Frame marker.
     * 1 = grayscale, 3 = YCbCr, 4 = CMYK/YCCK. Returns null if not determinable.
     */
    private function jpegComponentCount(string $bytes): ?int
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $sofMarkers = [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF];
        $pos = 2;
        while ($pos + 4 <= $length) {
            if ($bytes[$pos] !== "\xFF") {
                $pos++;
                continue;
            }

            $marker = ord($bytes[$pos + 1]);

            // Standalone markers (no payload): SOI/EOI, restart markers, TEM.
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;
                continue;
            }
            // Start of scan: compressed data follows, stop header parsing.
            if ($marker === 0xDA) {
                break;
            }

            $segmentLength = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if (in_array($marker, $sofMarkers, true)) {
                return $pos + 9 < $length ? ord($bytes[$pos + 9]) : null;
            }

            $pos += 2 + $segmentLength;
        }

        return null;
    }
}
