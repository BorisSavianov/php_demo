<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\ImageLoader\ImagickImageLoader;
use PHPUnit\Framework\TestCase;

final class ImagickImageLoaderTest extends TestCase
{
    public function testSupportReflectsWhetherImagickExtensionIsLoaded(): void
    {
        $loader = new ImagickImageLoader();
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        self::assertSame(class_exists('Imagick'), $loader->supports($source));
    }

    public function testLoadThrowsClearExceptionWhenImagickIsMissing(): void
    {
        if (class_exists('Imagick')) {
            self::markTestSkipped('This assertion applies only when ext-imagick is not loaded.');
        }

        $loader = new ImagickImageLoader();
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        $this->expectException(UnsupportedImageException::class);
        $loader->load($source);
    }

    public function testLoadDecodesPngWhenImagickIsAvailable(): void
    {
        if (!class_exists('Imagick')) {
            self::markTestSkipped('This assertion applies only when ext-imagick is loaded.');
        }

        $image = imagecreatetruecolor(2, 1);
        if (!$image instanceof \GdImage) {
            self::fail('Unable to create PNG test image.');
        }
        $red = imagecolorallocate($image, 255, 0, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        if ($red === false || $blue === false) {
            self::fail('Unable to allocate PNG test colors.');
        }
        imagesetpixel($image, 0, 0, $red);
        imagesetpixel($image, 1, 0, $blue);

        ob_start();
        $encoded = imagepng($image);
        $bytes = ob_get_clean();
        if ($encoded === false || !is_string($bytes)) {
            self::fail('Unable to encode PNG test image.');
        }

        $raster = (new ImagickImageLoader())->load(FileImageSource::fromBytes($bytes));

        self::assertSame(2, $raster->width());
        self::assertSame(1, $raster->height());
        self::assertSame('#FF0000', $raster->pixelAt(0, 0)->toHex());
        self::assertSame('#0000FF', $raster->pixelAt(1, 0)->toHex());
    }
}
