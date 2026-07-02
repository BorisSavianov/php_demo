<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;
use InvalidArgumentException;
use Throwable;

/**
 * OWNER: Developer A.
 *
 * Decodes sources with ext-gd, normalizing all formats to an immutable RGBA raster.
 */
final class GdImageLoader implements ImageLoaderInterface
{
    use ReadsImageSourceStreams;

    private const DEFAULT_MAX_PIXELS = 64_000_000;

    public function __construct(private readonly int $maxPixels = self::DEFAULT_MAX_PIXELS)
    {
        if ($maxPixels <= 0) {
            throw new InvalidArgumentException('Maximum pixel count must be positive.');
        }
    }

    public function supports(ImageSource $source): bool
    {
        return in_array($source->detectedFormat(), [ImageFormat::PNG, ImageFormat::JPEG], true);
    }

    public function load(ImageSource $source): Raster
    {
        if (!$this->supports($source)) {
            throw new UnsupportedImageException('GD loader supports only PNG and JPEG images.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new UnsupportedImageException('The GD extension is required to decode images.');
        }

        $bytes = $this->readAllBytes($source);
        $this->rejectUnsupportedJpeg($source, $bytes);

        $image = $this->createImageFromBytes($bytes);

        try {
            $this->assertImageSizeSupported($image);
            $normalized = $this->normalizeTruecolorWithAlpha($image);
            if ($normalized !== $image) {
                imagedestroy($image);
                $image = $normalized;
            }

            return $this->rasterFromGdImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function rejectUnsupportedJpeg(ImageSource $source, string $bytes): void
    {
        if ($source->detectedFormat() !== ImageFormat::JPEG) {
            return;
        }

        $info = $this->imageInfoFromBytes($bytes);

        if (($info['channels'] ?? 3) === 4) {
            throw new UnsupportedImageException(
                'CMYK JPEG images require the optional Imagick loader; GD cannot decode them reliably.',
            );
        }
    }

    private function createImageFromBytes(string $bytes): \GdImage
    {
        set_error_handler(static fn (): bool => true);
        try {
            $image = imagecreatefromstring($bytes);
        } catch (Throwable $e) {
            throw new InvalidImageException('GD could not decode the image bytes.', previous: $e);
        } finally {
            restore_error_handler();
        }

        if (!$image instanceof \GdImage) {
            throw new InvalidImageException('GD could not decode the image bytes.');
        }

        return $image;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function imageInfoFromBytes(string $bytes): array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $info = getimagesizefromstring($bytes);
        } catch (Throwable $e) {
            throw new InvalidImageException('JPEG metadata could not be read.', previous: $e);
        } finally {
            restore_error_handler();
        }

        if ($info === false) {
            throw new InvalidImageException('JPEG metadata could not be read.');
        }

        return $info;
    }

    private function normalizeTruecolorWithAlpha(\GdImage $image): \GdImage
    {
        if (imageistruecolor($image)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $truecolor = imagecreatetruecolor($width, $height);
        if (!$truecolor instanceof \GdImage) {
            throw new InvalidImageException('Unable to create truecolor image.');
        }

        imagealphablending($truecolor, false);
        imagesavealpha($truecolor, true);

        $transparent = imagecolorallocatealpha($truecolor, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($truecolor);
            throw new InvalidImageException('Unable to allocate transparent palette color.');
        }
        if (!imagefilledrectangle($truecolor, 0, 0, $width - 1, $height - 1, $transparent)) {
            imagedestroy($truecolor);
            throw new InvalidImageException('Unable to initialize transparent truecolor image.');
        }

        try {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $index = imagecolorat($image, $x, $y);
                    if ($index === false) {
                        throw new InvalidImageException("Unable to read palette pixel ({$x},{$y}).");
                    }
                    $channels = imagecolorsforindex($image, $index);
                    $color = imagecolorallocatealpha(
                        $truecolor,
                        $channels['red'],
                        $channels['green'],
                        $channels['blue'],
                        $channels['alpha'],
                    );
                    if ($color === false) {
                        throw new InvalidImageException('Unable to allocate normalized truecolor pixel.');
                    }
                    if (!imagesetpixel($truecolor, $x, $y, $color)) {
                        throw new InvalidImageException("Unable to write normalized pixel ({$x},{$y}).");
                    }
                }
            }
        } catch (InvalidImageException $e) {
            imagedestroy($truecolor);
            throw $e;
        }

        return $truecolor;
    }

    private function rasterFromGdImage(\GdImage $image): Raster
    {
        $this->assertImageSizeSupported($image);

        $width = imagesx($image);
        $height = imagesy($image);

        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        $hasAlpha = false;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = $this->readPixel($image, $x, $y);
                $pixels[] = $color;
                $hasAlpha = $hasAlpha || $color->a < 255;
            }
        }

        return new InMemoryRaster($width, $height, $pixels, $hasAlpha);
    }

    private function readPixel(\GdImage $image, int $x, int $y): ColorRGBA
    {
        $rgba = imagecolorat($image, $x, $y);
        if ($rgba === false) {
            throw new InvalidImageException("Unable to read pixel ({$x},{$y}) from GD image.");
        }
        $gdAlpha = ($rgba & 0x7F000000) >> 24;

        return new ColorRGBA(
            ($rgba >> 16) & 0xFF,
            ($rgba >> 8) & 0xFF,
            $rgba & 0xFF,
            (int) round((127 - $gdAlpha) * 255 / 127),
        );
    }

    private function assertImageSizeSupported(\GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > intdiv($this->maxPixels, $height)) {
            throw new UnsupportedImageException(sprintf(
                'Image dimensions %dx%d exceed the maximum supported pixel count of %d.',
                $width,
                $height,
                $this->maxPixels,
            ));
        }
    }
}
