<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\WhiteBackgroundCropper;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\CropResult;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\Options\CropOptions;
use ImageColorAnalyzer\Tests\Support\SyntheticImageFactory;
use ImageColorAnalyzer\WhiteBackgroundCropper\WhiteBackgroundCropper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OWNER: Developer B. Ground-truth crop boxes are asserted directly (deterministic),
 * with a corner pixel-level check that the cropped raster starts at real content.
 */
final class WhiteBackgroundCropperTest extends TestCase
{
    private const RED = [255, 0, 0];
    private const WHITE = [255, 255, 255];

    // -- Symmetric / asymmetric margins ------------------------------------

    public function testCropsSymmetricWhiteBorder(): void
    {
        $image = SyntheticImageFactory::contentOnBorder(100, 100, 20, $this->color(self::RED));

        $result = $this->crop($image);

        $this->assertBox(20, 20, 60, 60, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        self::assertSame(60, $result->raster->width());
        self::assertSame(60, $result->raster->height());
        // Corner of the cropped raster must be the content colour.
        self::assertSame('#FF0000', $result->raster->pixelAt(0, 0)->toHex());
    }

    public function testCropsAsymmetricMargins(): void
    {
        // Margins: top 10, bottom 8, left 15, right 5 on a 60x50 canvas.
        $white = $this->color(self::WHITE);
        $red = $this->color(self::RED);
        $image = $this->build(60, 50, static function (int $x, int $y) use ($white, $red): ColorRGBA {
            $inside = $x >= 15 && $x <= 54 && $y >= 10 && $y <= 41;

            return $inside ? $red : $white;
        });

        $result = $this->crop($image);

        $this->assertBox(15, 10, 40, 32, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        self::assertSame('#FF0000', $result->raster->pixelAt(0, 0)->toHex());
    }

    // -- Interior white must never be removed ------------------------------

    public function testKeepsInteriorWhite(): void
    {
        // Red block on a 20px white margin, with a white sub-square inside the block.
        $white = $this->color(self::WHITE);
        $red = $this->color(self::RED);
        $image = $this->build(100, 100, static function (int $x, int $y) use ($white, $red): ColorRGBA {
            $inBlock = $x >= 20 && $x <= 79 && $y >= 20 && $y <= 79;
            if (!$inBlock) {
                return $white;
            }
            $inHole = $x >= 40 && $x <= 59 && $y >= 40 && $y <= 59;

            return $inHole ? $white : $red;
        });

        $result = $this->crop($image);

        // The box tracks the red extent; the interior white sits inside it, untouched.
        $this->assertBox(20, 20, 60, 60, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        // Original (50,50) -> cropped (30,30) is the preserved interior white.
        self::assertSame('#FFFFFF', $result->raster->pixelAt(30, 30)->toHex());
    }

    // -- Near-white tolerance ----------------------------------------------

    /**
     * @return iterable<string, array{0: array{int,int,int}}>
     */
    public static function nearWhiteBorders(): iterable
    {
        yield 'flat 250 grey' => [[250, 250, 250]];
        yield 'slightly tinted scan' => [[248, 249, 250]];
    }

    /**
     * @param array{int,int,int} $border
     */
    #[DataProvider('nearWhiteBorders')]
    public function testCropsNearWhiteBorderWithinTolerance(array $border): void
    {
        $image = SyntheticImageFactory::contentOnBorder(
            80,
            80,
            15,
            $this->color(self::RED),
            $this->color($border),
        );

        $result = $this->crop($image);

        $this->assertBox(15, 15, 50, 50, $result->boundingBox);
        self::assertTrue($result->wasCropped);
    }

    public function testKeepsGenuineLightGrayContent(): void
    {
        // L* ~ 80.6 -> below the default lightnessMin (95): this is content, not background.
        $image = SyntheticImageFactory::contentOnBorder(60, 60, 15, $this->color([200, 200, 200]));

        $result = $this->crop($image);

        $this->assertBox(15, 15, 30, 30, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        self::assertSame('#C8C8C8', $result->raster->pixelAt(0, 0)->toHex());
    }

    // -- Degenerate inputs --------------------------------------------------

    public function testAllWhiteImageIsNotCropped(): void
    {
        $image = SyntheticImageFactory::solid(40, 40, $this->color(self::WHITE));

        $result = $this->crop($image);

        self::assertFalse($result->wasCropped);
        $this->assertBox(0, 0, 40, 40, $result->boundingBox);
        self::assertSame($image, $result->raster, 'Uncropped result should hand back the original raster.');
    }

    public function testFullyTransparentImageIsNotCropped(): void
    {
        $image = SyntheticImageFactory::solid(30, 30, new ColorRGBA(120, 30, 30, 0));

        $result = $this->crop($image);

        self::assertFalse($result->wasCropped);
        self::assertSame($image, $result->raster);
    }

    public function testNoMarginImageIsNotCropped(): void
    {
        $image = SyntheticImageFactory::solid(30, 30, $this->color(self::RED));

        $result = $this->crop($image);

        self::assertFalse($result->wasCropped);
        $this->assertBox(0, 0, 30, 30, $result->boundingBox);
        self::assertSame($image, $result->raster);
    }

    // -- Small / thin content: raw-extent fallback -------------------------

    public function testSinglePixelContentIsPreserved(): void
    {
        $white = $this->color(self::WHITE);
        $red = $this->color(self::RED);
        $image = $this->build(100, 100, static function (int $x, int $y) use ($red, $white): ColorRGBA {
            return $x === 50 && $y === 50 ? $red : $white;
        });

        $result = $this->crop($image);

        $this->assertBox(50, 50, 1, 1, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        self::assertSame('#FF0000', $result->raster->pixelAt(0, 0)->toHex());
    }

    public function testRawExtentFallbackRescuesContentBelowNoiseFloor(): void
    {
        // A single content pixel with an aggressive guard: every scan line falls
        // below the floor, so the raw-extent fallback must still keep the pixel.
        $white = $this->color(self::WHITE);
        $red = $this->color(self::RED);
        $image = $this->build(20, 20, static function (int $x, int $y) use ($red, $white): ColorRGBA {
            return $x === 10 && $y === 10 ? $red : $white;
        });

        $result = $this->crop($image, new CropOptions(lineContentFraction: 0.5));

        $this->assertBox(10, 10, 1, 1, $result->boundingBox);
        self::assertTrue($result->wasCropped);
    }

    // -- Noise guard --------------------------------------------------------

    public function testIgnoresSparseNoiseInMargin(): void
    {
        // 600px canvas => default 0.002 floor requires >=2 content px per line, so
        // single stray specks (each on a distinct margin row & column) are ignored.
        $white = $this->color(self::WHITE);
        $red = $this->color(self::RED);
        $black = $this->color([0, 0, 0]);
        $specks = ['10,10' => true, '30,560' => true, '560,30' => true, '80,540' => true, '540,80' => true];

        $image = $this->build(600, 600, static function (int $x, int $y) use ($white, $red, $black, $specks): ColorRGBA {
            if (isset($specks["$x,$y"])) {
                return $black;
            }
            $inBlock = $x >= 200 && $x <= 399 && $y >= 200 && $y <= 399;

            return $inBlock ? $red : $white;
        });

        $result = $this->crop($image);

        // Box matches the real content block, not the scattered noise.
        $this->assertBox(200, 200, 200, 200, $result->boundingBox);
        self::assertTrue($result->wasCropped);
    }

    // -- Transparent margins treated as background -------------------------

    public function testCropsTransparentMargin(): void
    {
        $image = SyntheticImageFactory::contentOnBorder(
            100,
            100,
            20,
            $this->color(self::RED),
            new ColorRGBA(0, 0, 0, 0), // fully transparent border
        );

        $result = $this->crop($image);

        $this->assertBox(20, 20, 60, 60, $result->boundingBox);
        self::assertTrue($result->wasCropped);
        self::assertSame('#FF0000', $result->raster->pixelAt(0, 0)->toHex());
    }

    // -- Tolerance is a real, tunable knob ---------------------------------

    public function testChromaToleranceControlsWhetherTintedBorderIsCropped(): void
    {
        // (245,255,245): L*~99, chroma ~6.2 — content at the default chromaMax (5),
        // background once chromaMax is relaxed past its chroma.
        $tinted = $this->color([245, 255, 245]);
        $image = SyntheticImageFactory::contentOnBorder(60, 60, 15, $this->color(self::RED), $tinted);

        $strict = $this->crop($image); // default chromaMax = 5.0
        self::assertFalse($strict->wasCropped, 'Tinted border exceeds default chroma tolerance -> treated as content.');

        $relaxed = $this->crop($image, new CropOptions(chromaMax: 7.0));
        $this->assertBox(15, 15, 30, 30, $relaxed->boundingBox);
        self::assertTrue($relaxed->wasCropped);
    }

    public function testLightnessToleranceControlsWhetherGrayBorderIsCropped(): void
    {
        $gray = $this->color([200, 200, 200]); // L* ~ 80.6
        $image = SyntheticImageFactory::contentOnBorder(60, 60, 15, $this->color(self::RED), $gray);

        $strict = $this->crop($image); // default lightnessMin = 95.0
        self::assertFalse($strict->wasCropped, 'Grey border below default lightness -> content.');

        $relaxed = $this->crop($image, new CropOptions(lightnessMin: 70.0));
        $this->assertBox(15, 15, 30, 30, $relaxed->boundingBox);
        self::assertTrue($relaxed->wasCropped);
    }

    // -- Property -----------------------------------------------------------

    public function testCroppedRasterNeverExceedsOriginal(): void
    {
        $cases = [
            SyntheticImageFactory::contentOnBorder(100, 100, 20, $this->color(self::RED)),
            SyntheticImageFactory::solid(30, 30, $this->color(self::WHITE)),
            SyntheticImageFactory::solid(25, 25, $this->color(self::RED)),
        ];

        foreach ($cases as $image) {
            $result = $this->crop($image);
            self::assertLessThanOrEqual($image->width(), $result->raster->width());
            self::assertLessThanOrEqual($image->height(), $result->raster->height());
        }
    }

    // -- Helpers ------------------------------------------------------------

    private function crop(Raster $image, ?CropOptions $options = null): CropResult
    {
        return (new WhiteBackgroundCropper(new ColorConverter()))->crop($image, $options ?? new CropOptions());
    }

    /**
     * @param array{int,int,int} $rgb
     */
    private function color(array $rgb): ColorRGBA
    {
        return new ColorRGBA($rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param callable(int, int): ColorRGBA $colorAt
     */
    private function build(int $width, int $height, callable $colorAt): InMemoryRaster
    {
        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels[] = $colorAt($x, $y);
            }
        }

        return new InMemoryRaster($width, $height, $pixels);
    }

    private function assertBox(int $x, int $y, int $width, int $height, BoundingBox $box): void
    {
        self::assertSame([$x, $y, $width, $height], [$box->x, $box->y, $box->width, $box->height]);
    }
}
