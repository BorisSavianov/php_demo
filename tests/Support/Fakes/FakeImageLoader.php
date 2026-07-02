<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Support\Fakes;

use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Contracts\Raster;

/**
 * OWNER: Developer A. Returns a preset raster, letting B and C test the pipeline
 * before the real GD loader exists.
 */
final class FakeImageLoader implements ImageLoaderInterface
{
    public function __construct(private readonly Raster $raster)
    {
    }

    public function supports(ImageSource $source): bool
    {
        return true;
    }

    public function load(ImageSource $source): Raster
    {
        return $this->raster;
    }
}
