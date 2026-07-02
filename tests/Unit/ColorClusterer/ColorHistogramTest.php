<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use ImageColorAnalyzer\ColorClusterer\ColorHistogram;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\Tests\Support\SyntheticImageFactory;
use PHPUnit\Framework\TestCase;

final class ColorHistogramTest extends TestCase
{
    public function testCountsEveryOpaquePixelInTotal(): void
    {
        $raster = SyntheticImageFactory::bands(10, 10, [
            ['color' => new ColorRGBA(255, 0, 0), 'fraction' => 0.5],
            ['color' => new ColorRGBA(0, 255, 0), 'fraction' => 0.3],
            ['color' => new ColorRGBA(0, 0, 255), 'fraction' => 0.2],
        ]);

        $histogram = (new ColorHistogram())->build($raster);

        self::assertSame(100, $histogram['total']);
        self::assertCount(3, $histogram['colors']);
        self::assertSame(100, array_sum($histogram['weights']));
        self::assertSame($histogram['total'], array_sum($histogram['weights']));

        // Exact per-color weights (50/30/20 rows of a 10-wide image).
        self::assertSame([50, 30, 20], $this->weightByHex($histogram, ['#FF0000', '#00FF00', '#0000FF']));
    }

    public function testSkipsTransparentPixels(): void
    {
        $pixels = [
            new ColorRGBA(255, 0, 0),
            new ColorRGBA(255, 0, 0),
            new ColorRGBA(0, 0, 0, 0),   // fully transparent
            new ColorRGBA(0, 0, 0, 4),   // below default alpha threshold (8)
        ];
        $raster = new InMemoryRaster(2, 2, $pixels, true);

        $histogram = (new ColorHistogram())->build($raster);

        self::assertSame(2, $histogram['total']);
        self::assertSame([[255, 0, 0]], $histogram['colors']);
        self::assertSame([2], $histogram['weights']);
    }

    public function testRepresentativeColorIsWeightedAverageOfContributingPixels(): void
    {
        // 250 and 254 both quantize to bin 31 at 5 bits/channel; representative is their mean.
        $pixels = [
            new ColorRGBA(250, 0, 0),
            new ColorRGBA(254, 0, 0),
        ];
        $raster = new InMemoryRaster(2, 1, $pixels);

        $histogram = (new ColorHistogram())->build($raster, 5);

        self::assertSame([[252, 0, 0]], $histogram['colors']);
        self::assertSame([2], $histogram['weights']);
    }

    public function testFewerBitsCollapseMoreColorsIntoOneBin(): void
    {
        $pixels = [
            new ColorRGBA(200, 0, 0),
            new ColorRGBA(255, 0, 0),
        ];
        $raster = new InMemoryRaster(2, 1, $pixels);

        // 1 bit/channel: both reds map to the top half -> a single bin.
        $coarse = (new ColorHistogram())->build($raster, 1);
        self::assertCount(1, $coarse['colors']);

        // 8 bits/channel: no quantization -> two distinct bins.
        $fine = (new ColorHistogram())->build($raster, 8);
        self::assertCount(2, $fine['colors']);
    }

    public function testFullyTransparentImageHasZeroTotal(): void
    {
        $raster = SyntheticImageFactory::solid(4, 4, new ColorRGBA(0, 0, 0, 0));

        $histogram = (new ColorHistogram())->build($raster);

        self::assertSame(0, $histogram['total']);
        self::assertSame([], $histogram['colors']);
        self::assertSame([], $histogram['weights']);
    }

    /**
     * @param array{colors: list<array{0:int,1:int,2:int}>, weights: list<int>, total: int} $histogram
     * @param list<string>                                                                   $hexes
     *
     * @return list<int>
     */
    private function weightByHex(array $histogram, array $hexes): array
    {
        $byHex = [];
        foreach ($histogram['colors'] as $i => [$r, $g, $b]) {
            $byHex[(new ColorRGBA($r, $g, $b))->toHex()] = $histogram['weights'][$i];
        }

        $result = [];
        foreach ($hexes as $hex) {
            $result[] = $byHex[$hex] ?? 0;
        }

        return $result;
    }
}
