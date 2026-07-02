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
}
