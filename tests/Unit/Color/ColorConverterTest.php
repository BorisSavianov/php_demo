<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\Color;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use PHPUnit\Framework\TestCase;

final class ColorConverterTest extends TestCase
{
    private ColorConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new ColorConverter();
    }

    public function testWhiteMapsToLightnessHundred(): void
    {
        [$l, $a, $b] = $this->converter->rgbToLab(new ColorRGBA(255, 255, 255));

        self::assertEqualsWithDelta(100.0, $l, 0.5);
        self::assertEqualsWithDelta(0.0, $a, 0.5);
        self::assertEqualsWithDelta(0.0, $b, 0.5);
    }

    public function testBlackMapsToLightnessZero(): void
    {
        [$l] = $this->converter->rgbToLab(new ColorRGBA(0, 0, 0));

        self::assertEqualsWithDelta(0.0, $l, 0.5);
    }

    public function testPrimaryLabReferenceValues(): void
    {
        $this->assertLabEquals([53.2408, 80.0925, 67.2032], new ColorRGBA(255, 0, 0));
        $this->assertLabEquals([87.7347, -86.1827, 83.1793], new ColorRGBA(0, 255, 0));
        $this->assertLabEquals([32.2970, 79.1875, -107.8602], new ColorRGBA(0, 0, 255));
    }

    public function testDeltaEBetweenBlackAndWhiteIsHundred(): void
    {
        $white = $this->converter->rgbToLab(new ColorRGBA(255, 255, 255));
        $black = $this->converter->rgbToLab(new ColorRGBA(0, 0, 0));

        self::assertEqualsWithDelta(100.0, $this->converter->deltaE($white, $black), 0.5);
        self::assertSame(0.0, $this->converter->deltaE($white, $white));
    }

    public function testHsvHuePrimaries(): void
    {
        self::assertEqualsWithDelta(0.0, $this->converter->rgbToHsv(new ColorRGBA(255, 0, 0))[0], 0.1);
        self::assertEqualsWithDelta(120.0, $this->converter->rgbToHsv(new ColorRGBA(0, 255, 0))[0], 0.1);
        self::assertEqualsWithDelta(240.0, $this->converter->rgbToHsv(new ColorRGBA(0, 0, 255))[0], 0.1);
    }

    public function testLabRoundTripKeepsRgbWithinOneByte(): void
    {
        $original = new ColorRGBA(12, 128, 240, 99);
        $roundTrip = $this->converter->labToRgb($this->converter->rgbToLab($original), $original->a);

        self::assertEqualsWithDelta($original->r, $roundTrip->r, 1);
        self::assertEqualsWithDelta($original->g, $roundTrip->g, 1);
        self::assertEqualsWithDelta($original->b, $roundTrip->b, 1);
        self::assertSame($original->a, $roundTrip->a);
    }

    public function testHsvRoundTrip(): void
    {
        $original = new ColorRGBA(25, 200, 90, 123);
        [$h, $s, $v] = $this->converter->rgbToHsv($original);
        $roundTrip = $this->converter->hsvToRgb($h, $s, $v, $original->a);

        self::assertEqualsWithDelta($original->r, $roundTrip->r, 1);
        self::assertEqualsWithDelta($original->g, $roundTrip->g, 1);
        self::assertEqualsWithDelta($original->b, $roundTrip->b, 1);
        self::assertSame($original->a, $roundTrip->a);
    }

    public function testDeltaE94ZeroForSameColorAndPositiveForDifferentColors(): void
    {
        $white = $this->converter->rgbToLab(new ColorRGBA(255, 255, 255));
        $gray = $this->converter->rgbToLab(new ColorRGBA(200, 200, 200));

        self::assertSame(0.0, $this->converter->deltaE94($white, $white));
        self::assertGreaterThan(0.0, $this->converter->deltaE94($white, $gray));
    }

    /**
     * @param array{0:float,1:float,2:float} $expected
     */
    private function assertLabEquals(array $expected, ColorRGBA $color): void
    {
        $actual = $this->converter->rgbToLab($color);

        self::assertEqualsWithDelta($expected[0], $actual[0], 0.05);
        self::assertEqualsWithDelta($expected[1], $actual[1], 0.05);
        self::assertEqualsWithDelta($expected[2], $actual[2], 0.05);
    }
}
