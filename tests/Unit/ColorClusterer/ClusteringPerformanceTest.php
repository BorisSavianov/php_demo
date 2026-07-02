<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\ColorClusterer\ColorHistogram;
use ImageColorAnalyzer\ColorClusterer\KMeansClusterer;
use ImageColorAnalyzer\ColorClusterer\KSelector;
use ImageColorAnalyzer\Contracts\BoundingBox;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Options\ClusterOptions;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the core performance promise: because clustering runs on
 * the binned histogram rather than raw pixels, a multi-megapixel image must
 * analyze in about the same time as a thumbnail. The raster below yields pixels
 * lazily (reusing three immutable colors) so the fixture itself stays O(1) in
 * memory and the timing reflects the algorithm, not fixture construction.
 */
final class ClusteringPerformanceTest extends TestCase
{
    /**
     * Generous ceiling: the real cost for 3 unique colors is a fraction of a
     * second, but CI runs under xdebug's coverage mode (several times slower), so
     * the budget only needs to catch a catastrophic (e.g. per-pixel) regression.
     */
    private const TIME_BUDGET_SECONDS = 15.0;

    public function testHighResolutionImageAnalyzesWithinBudget(): void
    {
        $width = 1000;
        $height = 1000; // 1 megapixel

        $raster = $this->lazyBands($width, $height);
        $clusterer = $this->clusterer();

        $start = hrtime(true);
        $result = $clusterer->cluster($raster, new ClusterOptions());
        $elapsed = (hrtime(true) - $start) / 1e9;

        self::assertLessThan(self::TIME_BUDGET_SECONDS, $elapsed, sprintf('clustering took %.2fs', $elapsed));
        self::assertSame($width * $height, $result->totalAnalyzedPixels);
        self::assertCount(3, $result->clusters);
        self::assertSame('#FF0000', $result->clusters[0]->centroid->toHex());
    }

    private function clusterer(): KMeansClusterer
    {
        $converter = new ColorConverter();

        return new KMeansClusterer($converter, new ColorHistogram(), new KSelector($converter));
    }

    /**
     * Red for the top half, green for the next 30%, blue for the last 20%.
     */
    private function lazyBands(int $width, int $height): Raster
    {
        return new class ($width, $height) implements Raster {
            private ColorRGBA $red;
            private ColorRGBA $green;
            private ColorRGBA $blue;

            public function __construct(private readonly int $w, private readonly int $h)
            {
                $this->red = new ColorRGBA(255, 0, 0);
                $this->green = new ColorRGBA(0, 255, 0);
                $this->blue = new ColorRGBA(0, 0, 255);
            }

            public function width(): int
            {
                return $this->w;
            }

            public function height(): int
            {
                return $this->h;
            }

            public function hasAlpha(): bool
            {
                return false;
            }

            public function pixelAt(int $x, int $y): ColorRGBA
            {
                return $this->colorForRow($y);
            }

            public function pixels(): iterable
            {
                for ($y = 0; $y < $this->h; $y++) {
                    $color = $this->colorForRow($y);
                    for ($x = 0; $x < $this->w; $x++) {
                        yield $color;
                    }
                }
            }

            public function crop(BoundingBox $box): Raster
            {
                return $this;
            }

            private function colorForRow(int $y): ColorRGBA
            {
                if ($y < $this->h * 0.5) {
                    return $this->red;
                }

                return $y < $this->h * 0.8 ? $this->green : $this->blue;
            }
        };
    }
}
