<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Support\Fakes;

use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\CropperInterface;
use ImageColorAnalyzer\Contracts\CropResult;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Options\CropOptions;

/**
 * OWNER: Developer A. Returns the input unchanged, so the clustering path can be
 * tested independently of the real cropper.
 */
final class PassthroughCropper implements CropperInterface
{
    public function crop(Raster $image, CropOptions $options): CropResult
    {
        return new CropResult(
            $image,
            new BoundingBox(0, 0, $image->width(), $image->height()),
            false,
        );
    }
}
