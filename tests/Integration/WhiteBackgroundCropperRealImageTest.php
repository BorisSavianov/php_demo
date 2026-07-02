<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Integration;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\Options\CropOptions;
use ImageColorAnalyzer\WhiteBackgroundCropper\WhiteBackgroundCropper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OWNER: Developer B. Proves the cropper behaves correctly on genuinely decoded
 * pixels — true alpha and real JPEG anti-aliasing — not only on synthetic
 * rasters. Fixtures live in tests/Fixtures/real/ (see the README there).
 *
 * The GD decode here mirrors the conversion documented on
 * {@see \ImageColorAnalyzer\ImageLoader\GdImageLoader}; at the Week-3 wiring
 * session it is replaced by that loader once Developer A ships it.
 */
final class WhiteBackgroundCropperRealImageTest extends TestCase
{
    /** Known content rectangle drawn into every fixture: x 40..159, y 30..119. */
    private const CONTENT = ['x' => 40, 'y' => 30, 'w' => 120, 'h' => 90];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required to decode the real image fixtures.');
        }
    }

    /**
     * Lossless fixtures decode deterministically, so we can assert the exact box.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function losslessFixtures(): iterable
    {
        yield 'opaque white border (PNG)' => ['logo_white_border.png'];
        yield 'transparent border (PNG)' => ['transparent_border.png'];
    }

    #[DataProvider('losslessFixtures')]
    public function testCropsLosslessBorderToExactContent(string $fixture): void
    {
        $result = (new WhiteBackgroundCropper(new ColorConverter()))
            ->crop($this->decode($fixture), new CropOptions());

        $box = $result->boundingBox;
        self::assertTrue($result->wasCropped);
        self::assertSame(
            [self::CONTENT['x'], self::CONTENT['y'], self::CONTENT['w'], self::CONTENT['h']],
            [$box->x, $box->y, $box->width, $box->height],
        );
        // Top-left of the crop is the real content colour (red block: #C82828).
        self::assertSame('#C82828', $result->raster->pixelAt(0, 0)->toHex());
    }

    /**
     * JPEG ringing around hard edges nudges the crop outward by a few pixels and
     * varies with the libjpeg build, so we assert robust invariants instead of an
     * exact box: the border is substantially removed, and no content is lost.
     */
    public function testCropsLossyOffWhiteScanWithoutLosingContent(): void
    {
        $raster = $this->decode('scan_offwhite_border.jpg');
        $result = (new WhiteBackgroundCropper(new ColorConverter()))->crop($raster, new CropOptions());
        $box = $result->boundingBox;

        self::assertTrue($result->wasCropped, 'The off-white border must be detected and trimmed.');

        // The crop still contains the full known content rectangle (nothing erased).
        self::assertLessThanOrEqual(self::CONTENT['x'], $box->x);
        self::assertLessThanOrEqual(self::CONTENT['y'], $box->y);
        self::assertGreaterThanOrEqual(self::CONTENT['x'] + self::CONTENT['w'], $box->x + $box->width);
        self::assertGreaterThanOrEqual(self::CONTENT['y'] + self::CONTENT['h'], $box->y + $box->height);

        // And it removed most of the margin (well under the full 200x150 canvas).
        self::assertLessThan(
            $raster->width() * $raster->height() * 0.8,
            $box->area(),
            'Expected the near-white margin to be substantially cropped.',
        );
    }

    /**
     * Decode a fixture with ext-gd into an InMemoryRaster, mirroring
     * GdImageLoader's documented GD-alpha (0..127) -> RGBA (0..255) conversion.
     */
    private function decode(string $fixture): InMemoryRaster
    {
        $path = __DIR__ . '/../Fixtures/real/' . $fixture;
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            self::fail("Missing fixture: {$fixture}");
        }

        $img = imagecreatefromstring($bytes);
        if ($img === false) {
            self::fail("Undecodable fixture: {$fixture}");
        }
        imagepalettetotruecolor($img);
        imagesavealpha($img, true);

        $width = imagesx($img);
        $height = imagesy($img);
        $hasAlpha = false;
        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = (int) imagecolorat($img, $x, $y);
                $gdAlpha = ($rgba >> 24) & 0x7F;
                $alpha = (int) round((127 - $gdAlpha) * 255 / 127);
                $hasAlpha = $hasAlpha || $alpha < 255;
                $pixels[] = new ColorRGBA(($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $alpha);
            }
        }

        return new InMemoryRaster($width, $height, $pixels, $hasAlpha);
    }
}
