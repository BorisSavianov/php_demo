<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\ImageLoader\GdImageLoader;
use PHPUnit\Framework\TestCase;

final class GdImageLoaderTest extends TestCase
{
    public function testSupportsReportsTrue(): void
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        fwrite($stream, "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));
        rewind($stream);

        $loader = new GdImageLoader();
        self::assertTrue($loader->supports(FileImageSource::fromStream($stream)));
    }

    public function testLoadDecodesPixels(): void
    {
        $source = FileImageSource::fromBytes($this->pngBytes(static function (\GdImage $image): void {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
            $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
            if ($red === false || $transparent === false) {
                TestCase::fail('Unable to allocate PNG test colors.');
            }
            imagesetpixel($image, 0, 0, $red);
            imagesetpixel($image, 1, 0, $transparent);
        }, 2, 1));

        $raster = (new GdImageLoader())->load($source);

        self::assertSame(2, $raster->width());
        self::assertSame(1, $raster->height());
        self::assertTrue($raster->hasAlpha());
        self::assertSame('#FF0000', $raster->pixelAt(0, 0)->toHex());
        self::assertSame(255, $raster->pixelAt(0, 0)->a);
        self::assertSame('#0A141E', $raster->pixelAt(1, 0)->toHex());
        self::assertSame(0, $raster->pixelAt(1, 0)->a);
    }

    public function testLoadDecodesJpegPixels(): void
    {
        $source = FileImageSource::fromBytes($this->jpegBytes(static function (\GdImage $image): void {
            $brown = imagecolorallocate($image, 120, 60, 30);
            if ($brown === false) {
                TestCase::fail('Unable to allocate JPEG test color.');
            }
            imagefilledrectangle($image, 0, 0, 0, 0, $brown);
        }));

        $pixel = (new GdImageLoader())->load($source)->pixelAt(0, 0);

        self::assertEqualsWithDelta(120, $pixel->r, 3);
        self::assertEqualsWithDelta(60, $pixel->g, 3);
        self::assertEqualsWithDelta(30, $pixel->b, 3);
        self::assertSame(255, $pixel->a);
    }

    public function testLoadNormalizesPalettePng(): void
    {
        $image = imagecreate(2, 1);
        if (!$image instanceof \GdImage) {
            self::fail('Unable to create palette image.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
        $partialAlpha = imagecolorallocatealpha($image, 10, 20, 30, 63);
        if ($red === false || $partialAlpha === false) {
            self::fail('Unable to allocate palette colors.');
        }
        imagesetpixel($image, 0, 0, $red);
        imagesetpixel($image, 1, 0, $partialAlpha);

        $source = FileImageSource::fromBytes($this->encodePng($image));
        $raster = (new GdImageLoader())->load($source);

        self::assertTrue($raster->hasAlpha());
        self::assertSame('#FF0000', $raster->pixelAt(0, 0)->toHex());
        self::assertSame(255, $raster->pixelAt(0, 0)->a);
        self::assertSame('#0A141E', $raster->pixelAt(1, 0)->toHex());
        self::assertEqualsWithDelta(129, $raster->pixelAt(1, 0)->a, 1);
    }

    public function testRejectsOversizedDecodedImageBeforeRasterizing(): void
    {
        $source = FileImageSource::fromBytes($this->pngBytes(static function (\GdImage $image): void {
            $red = imagecolorallocate($image, 255, 0, 0);
            if ($red === false) {
                TestCase::fail('Unable to allocate test color.');
            }
            imagefilledrectangle($image, 0, 0, 1, 0, $red);
        }, 2, 1));

        $this->expectException(UnsupportedImageException::class);

        (new GdImageLoader(maxPixels: 1))->load($source);
    }

    public function testCorruptImageThrowsClearException(): void
    {
        $this->expectException(InvalidImageException::class);

        (new GdImageLoader())->load(FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . 'not really png'));
    }

    public function testRejectsCmykJpegBeforeGdDecode(): void
    {
        $this->expectException(UnsupportedImageException::class);

        (new GdImageLoader())->load(FileImageSource::fromBytes($this->cmykJpegHeaderBytes()));
    }

    /**
     * @param callable(\GdImage): void $draw
     */
    private function pngBytes(callable $draw, int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof \GdImage) {
            self::fail('Unable to create truecolor image.');
        }

        $draw($image);

        return $this->encodePng($image);
    }

    /**
     * @param callable(\GdImage): void $draw
     */
    private function jpegBytes(callable $draw): string
    {
        $image = imagecreatetruecolor(1, 1);
        if (!$image instanceof \GdImage) {
            self::fail('Unable to create truecolor image.');
        }

        $draw($image);

        ob_start();
        $encoded = imagejpeg($image, null, 100);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || !is_string($bytes)) {
            self::fail('Unable to encode JPEG test fixture.');
        }

        return $bytes;
    }

    private function encodePng(\GdImage $image): string
    {
        ob_start();
        $encoded = imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || !is_string($bytes)) {
            self::fail('Unable to encode PNG test fixture.');
        }

        return $bytes;
    }

    private function cmykJpegHeaderBytes(): string
    {
        return "\xFF\xD8"
            . "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xC0\x00\x14\x08\x00\x01\x00\x01\x04"
            . "\x01\x11\x00\x02\x11\x00\x03\x11\x00\x04\x11\x00"
            . "\xFF\xD9";
    }
}
