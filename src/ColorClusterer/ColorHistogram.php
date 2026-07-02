<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ColorClusterer;

use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\NotImplementedException;

/**
 * OWNER: Developer C.
 *
 * Reduces a raster to a weighted color histogram: bins colors to
 * `bitsPerChannel` resolution, skips transparent pixels, and returns unique
 * colors with their pixel counts plus the total analyzed pixel count. Running
 * clustering on this (instead of raw pixels) makes cost depend on color
 * diversity rather than image resolution.
 *
 * TODO(C): implement build().
 */
final class ColorHistogram
{
    /**
     * @return array{colors: list<array{0:int,1:int,2:int}>, weights: list<int>, total: int}
     */
    public function build(Raster $image, int $bitsPerChannel = 5, int $alphaThreshold = 8): array
    {
        throw new NotImplementedException('ColorHistogram::build() pending — Developer C.');
    }
}
