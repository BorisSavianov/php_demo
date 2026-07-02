<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\WhiteBackgroundCropper;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\CropperInterface;
use ImageColorAnalyzer\Contracts\CropResult;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\NotImplementedException;
use ImageColorAnalyzer\Options\CropOptions;

/**
 * OWNER: Developer B.
 *
 * Border-inward scan: from each of the four edges, advance until a scan line
 * contains a non-near-white, non-transparent pixel, then crop to the resulting
 * bounding box. "Near-white" is judged in CIELAB (L* >= lightnessMin and
 * chroma <= chromaMax) via the injected {@see ColorConverter}.
 *
 * TODO(B): implement crop(); cover all-white, no-margin, transparent-margin,
 * single-pixel-content, and off-center cases; honor lineContentFraction as a
 * noise guard so a few stray pixels do not defeat cropping.
 */
final class WhiteBackgroundCropper implements CropperInterface
{
    public function __construct(private readonly ColorConverter $converter)
    {
    }

    public function crop(Raster $image, CropOptions $options): CropResult
    {
        throw new NotImplementedException('WhiteBackgroundCropper::crop() pending — Developer B.');
    }
}
