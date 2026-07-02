<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\ColorClusterer\KSelector;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use PHPUnit\Framework\TestCase;

final class KSelectorTest extends TestCase
{
    private ColorConverter $converter;
    private KSelector $selector;

    protected function setUp(): void
    {
        $this->converter = new ColorConverter();
        $this->selector = new KSelector($this->converter);
    }

    public function testPicksTwoForTwoObviousClusters(): void
    {
        [$points, $weights] = $this->colorGroups([
            new ColorRGBA(200, 30, 30),
            new ColorRGBA(30, 30, 200),
        ]);

        self::assertSame(2, $this->selector->select($points, $weights, 6, 1));
    }

    public function testPicksFiveForFiveWellSeparatedColors(): void
    {
        [$points, $weights] = $this->colorGroups([
            new ColorRGBA(210, 30, 30),   // red
            new ColorRGBA(30, 200, 30),   // green
            new ColorRGBA(30, 30, 210),   // blue
            new ColorRGBA(225, 225, 30),  // yellow
            new ColorRGBA(35, 35, 35),    // near-black
        ]);

        self::assertSame(5, $this->selector->select($points, $weights, 8, 1));
    }

    public function testReturnsOneForSinglePoint(): void
    {
        $point = [$this->converter->rgbToLab(new ColorRGBA(10, 20, 30))];

        self::assertSame(1, $this->selector->select($point, [5], 8, 1));
    }

    public function testReturnsTwoForTwoUniqueColors(): void
    {
        $points = [
            $this->converter->rgbToLab(new ColorRGBA(0, 0, 0)),
            $this->converter->rgbToLab(new ColorRGBA(255, 255, 255)),
        ];

        self::assertSame(2, $this->selector->select($points, [3, 7], 8, 1));
    }

    public function testNeverExceedsKMax(): void
    {
        [$points, $weights] = $this->colorGroups([
            new ColorRGBA(210, 30, 30),
            new ColorRGBA(30, 200, 30),
            new ColorRGBA(30, 30, 210),
            new ColorRGBA(225, 225, 30),
            new ColorRGBA(35, 35, 35),
        ]);

        $k = $this->selector->select($points, $weights, 3, 1);

        self::assertGreaterThanOrEqual(2, $k);
        self::assertLessThanOrEqual(3, $k);
    }

    public function testIsDeterministic(): void
    {
        [$points, $weights] = $this->colorGroups([
            new ColorRGBA(210, 30, 30),
            new ColorRGBA(30, 30, 210),
            new ColorRGBA(30, 200, 30),
        ]);

        self::assertSame(
            $this->selector->select($points, $weights, 8, 7),
            $this->selector->select($points, $weights, 8, 7),
        );
    }

    /**
     * Builds tight three-point clusters (base color plus small Lab offsets) for
     * each supplied color, so the natural cluster count equals count($colors).
     *
     * @param list<ColorRGBA> $colors
     *
     * @return array{0: list<array{0:float,1:float,2:float}>, 1: list<int>}
     */
    private function colorGroups(array $colors): array
    {
        $offsets = [[0.0, 0.0, 0.0], [1.0, 0.5, -0.5], [-1.0, -0.5, 0.5]];
        $points = [];
        $weights = [];
        foreach ($colors as $color) {
            $lab = $this->converter->rgbToLab($color);
            foreach ($offsets as $offset) {
                $points[] = [$lab[0] + $offset[0], $lab[1] + $offset[1], $lab[2] + $offset[2]];
                $weights[] = 10;
            }
        }

        return [$points, $weights];
    }
}
