<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\ImageLoader\GdRaster;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GdRasterTest extends TestCase
{
    public function testReportsDimensionsAndIteratesPixelsInRowMajorOrder(): void
    {
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);

        imagesetpixel($image, 0, 0, $this->rgb($image, 255, 0, 0));
        imagesetpixel($image, 1, 0, $this->rgb($image, 0, 255, 0));
        imagesetpixel($image, 0, 1, $this->rgb($image, 0, 0, 255));
        imagesetpixel($image, 1, 1, $this->rgb($image, 255, 255, 0));

        $raster = new GdRaster($image);
        $hexes = [];
        foreach ($raster->pixels() as $pixel) {
            $hexes[] = $pixel->toHex();
        }

        self::assertSame(2, $raster->width());
        self::assertSame(2, $raster->height());
        self::assertFalse($raster->hasAlpha());
        self::assertSame(['#FF0000', '#00FF00', '#0000FF', '#FFFF00'], $hexes);
    }

    public function testExpandsGdAlphaAndDetectsTransparency(): void
    {
        $image = imagecreatetruecolor(2, 1);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        imagesetpixel($image, 0, 0, $this->rgba($image, 10, 20, 30, 0));
        imagesetpixel($image, 1, 0, $this->rgba($image, 40, 50, 60, 127));

        $raster = new GdRaster($image);

        self::assertTrue($raster->hasAlpha());
        self::assertSame(255, $raster->pixelAt(0, 0)->a);
        self::assertSame(0, $raster->pixelAt(1, 0)->a);
    }

    public function testNestedCropsReadFromTheExpectedSourceCoordinates(): void
    {
        $image = imagecreatetruecolor(3, 3);
        self::assertInstanceOf(\GdImage::class, $image);

        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                imagesetpixel($image, $x, $y, $this->rgb($image, $x * 50, $y * 50, 0));
            }
        }

        $first = (new GdRaster($image))->crop(new BoundingBox(1, 0, 2, 3));
        $nested = $first->crop(new BoundingBox(0, 1, 1, 2));

        self::assertSame(1, $nested->width());
        self::assertSame(2, $nested->height());
        self::assertSame('#326400', $nested->pixelAt(0, 1)->toHex());
        self::assertSame(['#323200', '#326400'], array_map(
            static fn (ColorRGBA $pixel): string => $pixel->toHex(),
            iterator_to_array($nested->pixels()),
        ));
    }

    public function testPixelAccessRejectsCoordinatesOutsideTheView(): void
    {
        $image = imagecreatetruecolor(1, 1);
        self::assertInstanceOf(\GdImage::class, $image);

        $this->expectException(InvalidArgumentException::class);

        (new GdRaster($image))->pixelAt(1, 0);
    }

    public function testCropRejectsBoxesOutsideTheView(): void
    {
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);

        $this->expectException(InvalidArgumentException::class);

        (new GdRaster($image))->crop(new BoundingBox(1, 1, 2, 2));
    }

    private function rgb(\GdImage $image, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($image, $r, $g, $b);
        self::assertNotFalse($color);

        return $color;
    }

    private function rgba(\GdImage $image, int $r, int $g, int $b, int $a): int
    {
        $color = imagecolorallocatealpha($image, $r, $g, $b, $a);
        self::assertNotFalse($color);

        return $color;
    }
}
