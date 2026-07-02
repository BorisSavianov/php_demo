<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InMemoryRasterTest extends TestCase
{
    public function testDimensionsAndPixelAccess(): void
    {
        $raster = $this->checkerboard();

        self::assertSame(2, $raster->width());
        self::assertSame(2, $raster->height());
        self::assertSame('#FF0000', $raster->pixelAt(0, 0)->toHex());
        self::assertSame('#0000FF', $raster->pixelAt(1, 1)->toHex());
    }

    public function testCropReturnsSubRegion(): void
    {
        $cropped = $this->checkerboard()->crop(new BoundingBox(1, 0, 1, 2));

        self::assertSame(1, $cropped->width());
        self::assertSame(2, $cropped->height());
        self::assertSame('#00FF00', $cropped->pixelAt(0, 0)->toHex());
    }

    public function testOutOfBoundsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->checkerboard()->pixelAt(5, 5);
    }

    private function checkerboard(): InMemoryRaster
    {
        return new InMemoryRaster(2, 2, [
            new ColorRGBA(255, 0, 0), new ColorRGBA(0, 255, 0),
            new ColorRGBA(255, 255, 0), new ColorRGBA(0, 0, 255),
        ]);
    }
}
