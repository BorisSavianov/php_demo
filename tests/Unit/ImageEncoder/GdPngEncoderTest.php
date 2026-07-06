<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageEncoder;

use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Contracts\PngEncoderInterface;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\ImageEncodingException;
use ImageColorAnalyzer\ImageEncoder\GdPngEncoder;
use ImageColorAnalyzer\ImageLoader\GdRaster;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GdPngEncoderTest extends TestCase
{
    public function testEncodesAnInMemoryRasterWithAlpha(): void
    {
        $raster = new InMemoryRaster(2, 1, [
            new ColorRGBA(255, 0, 0, 255),
            new ColorRGBA(0, 255, 0, 0),
        ], true);

        $encoder = new GdPngEncoder();
        self::assertInstanceOf(PngEncoderInterface::class, $encoder);

        $encoded = $encoder->encode($raster);

        self::assertSame(ImageFormat::PNG, $encoded->format);
        self::assertSame('image/png', $encoded->mediaType);
        self::assertSame(2, $encoded->width);
        self::assertSame(1, $encoded->height);
        self::assertStringStartsWith("\x89PNG\x0d\x0a\x1a\x0a", $encoded->bytes);

        $decoded = imagecreatefromstring($encoded->bytes);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame([255, 0, 0, 0], $this->rgbaAt($decoded, 0, 0));
        self::assertSame([0, 255, 0, 127], $this->rgbaAt($decoded, 1, 0));
    }

    public function testEncodesOnlyTheGdRasterCropView(): void
    {
        $image = imagecreatetruecolor(3, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 2, 1, $this->color($image, 255, 255, 255));
        imagesetpixel($image, 1, 0, $this->color($image, 0, 0, 255));

        $raster = (new GdRaster($image))->crop(new BoundingBox(1, 0, 1, 1));
        $encoded = (new GdPngEncoder())->encode($raster);

        $decoded = imagecreatefromstring($encoded->bytes);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame(1, imagesx($decoded));
        self::assertSame(1, imagesy($decoded));
        self::assertSame([0, 0, 255, 0], $this->rgbaAt($decoded, 0, 0));
    }

    public function testWrapsRasterReadFailuresAsImageEncodingErrors(): void
    {
        $raster = new class () implements Raster {
            public function width(): int
            {
                return 1;
            }

            public function height(): int
            {
                return 1;
            }

            public function hasAlpha(): bool
            {
                return false;
            }

            public function pixelAt(int $x, int $y): ColorRGBA
            {
                throw new RuntimeException('read failed');
            }

            public function pixels(): iterable
            {
                throw new RuntimeException('read failed');
            }

            public function crop(BoundingBox $box): Raster
            {
                return $this;
            }
        };

        $this->expectException(ImageEncodingException::class);
        $this->expectExceptionMessage('Unable to encode cropped image as PNG.');

        (new GdPngEncoder())->encode($raster);
    }

    /**
     * @return array{int, int, int, int}
     */
    private function rgbaAt(\GdImage $image, int $x, int $y): array
    {
        $value = imagecolorat($image, $x, $y);
        self::assertIsInt($value);

        return [
            ($value >> 16) & 0xFF,
            ($value >> 8) & 0xFF,
            $value & 0xFF,
            ($value >> 24) & 0x7F,
        ];
    }

    private function color(\GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
        self::assertNotFalse($color);

        return $color;
    }
}
