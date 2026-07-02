<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\NotImplementedException;

/**
 * OWNER: Developer A.
 *
 * Decodes PNG/JPEG via ext-gd into an {@see InMemoryRaster}.
 *
 * TODO(A): implement load():
 *   - read all bytes from $source->stream(); imagecreatefromstring()
 *   - imagepalettetotruecolor(); imagesavealpha(, true)
 *   - read pixels with imagecolorat(); convert GD alpha (0-127) to 0-255:
 *       $a = (int) round((127 - $gdAlpha) * 255 / 127)
 *   - build a list<ColorRGBA> and return new InMemoryRaster(w, h, pixels, $hasAlpha)
 *   - throw UnsupportedImageException for CMYK JPEG (route to Imagick if available)
 */
final class GdImageLoader implements ImageLoaderInterface
{
    public function supports(ImageSource $source): bool
    {
        return true; // GD handles both supported formats (PNG, JPEG).
    }

    public function load(ImageSource $source): Raster
    {
        throw new NotImplementedException('GdImageLoader::load() pending — Developer A.');
    }
}
