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

    public function testLoadsTruecolorPngPixels(): void
    {
        $image = imagecreatetruecolor(2, 2);
        imagefilledrectangle($image, 0, 0, 0, 0, self::rgb($image, 255, 0, 0));
        imagefilledrectangle($image, 1, 0, 1, 0, self::rgb($image, 0, 255, 0));
        imagefilledrectangle($image, 0, 1, 1, 1, self::rgb($image, 0, 0, 255));

        $raster = (new GdImageLoader())->load($this->pngSource($image));

        self::assertSame(2, $raster->width());
        self::assertSame(2, $raster->height());
        self::assertSame('#FF0000', $raster->pixelAt(0, 0)->toHex());
        self::assertSame('#00FF00', $raster->pixelAt(1, 0)->toHex());
        self::assertSame('#0000FF', $raster->pixelAt(0, 1)->toHex());
    }

    public function testPreservesAlphaChannel(): void
    {
        $image = imagecreatetruecolor(2, 1);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, 0, 0, self::rgb($image, 255, 0, 0));
        imagefilledrectangle($image, 1, 0, 1, 0, self::rgba($image, 0, 0, 0, 127));

        $raster = (new GdImageLoader())->load($this->pngSource($image));

        self::assertTrue($raster->hasAlpha());
        self::assertSame(255, $raster->pixelAt(0, 0)->a);
        self::assertTrue($raster->pixelAt(1, 0)->isTransparent());
    }

    public function testNormalizesPaletteImageToTruecolor(): void
    {
        $image = imagecreate(1, 1); // palette-based
        self::rgb($image, 10, 20, 30); // background (index 0)

        $raster = (new GdImageLoader())->load($this->pngSource($image));

        self::assertSame('#0A141E', $raster->pixelAt(0, 0)->toHex());
        self::assertFalse($raster->hasAlpha());
    }

    public function testLoadsJpegAsApproximateColor(): void
    {
        $image = imagecreatetruecolor(8, 8);
        imagefilledrectangle($image, 0, 0, 7, 7, self::rgb($image, 10, 120, 200));

        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        imagejpeg($image, $stream, 100);
        imagedestroy($image);
        rewind($stream);

        $raster = (new GdImageLoader())->load(FileImageSource::fromStream($stream));

        self::assertSame(8, $raster->width());
        $center = $raster->pixelAt(4, 4);
        self::assertEqualsWithDelta(10, $center->r, 12);
        self::assertEqualsWithDelta(120, $center->g, 12);
        self::assertEqualsWithDelta(200, $center->b, 12);
    }

    public function testRejectsCorruptImageData(): void
    {
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        // Valid PNG magic so the source sniffs as PNG, but the body is garbage.
        fwrite($stream, "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\xFF", 32));
        rewind($stream);

        $this->expectException(InvalidImageException::class);
        (new GdImageLoader())->load(FileImageSource::fromStream($stream));
    }

    public function testRejectsCmykJpeg(): void
    {
        // Minimal JPEG header whose SOF0 marker declares 4 components (CMYK).
        $bytes = "\xFF\xD8\xFF\xC0\x00\x11\x08\x00\x02\x00\x02\x04" . str_repeat("\x00", 12);
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $this->expectException(UnsupportedImageException::class);
        (new GdImageLoader())->load(FileImageSource::fromStream($stream));
    }

    /**
     * Encodes $image as PNG into a temp stream and wraps it as a source.
     *
     * @param \GdImage $image consumed (destroyed) by this helper
     */
    private function pngSource(\GdImage $image): FileImageSource
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        imagesavealpha($image, true);
        imagepng($image, $stream);
        imagedestroy($image);
        rewind($stream);

        return FileImageSource::fromStream($stream);
    }

    private static function rgb(\GdImage $image, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($image, $r, $g, $b);
        self::assertNotFalse($color);

        return $color;
    }

    private static function rgba(\GdImage $image, int $r, int $g, int $b, int $a): int
    {
        $color = imagecolorallocatealpha($image, $r, $g, $b, $a);
        self::assertNotFalse($color);

        return $color;
    }
}
