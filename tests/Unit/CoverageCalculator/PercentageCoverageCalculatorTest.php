<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\CoverageCalculator;

use ImageColorAnalyzer\Contracts\Cluster;
use ImageColorAnalyzer\Contracts\ClusterResult;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\CoverageCalculator\PercentageCoverageCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentageCoverageCalculatorTest extends TestCase
{
    private PercentageCoverageCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PercentageCoverageCalculator();
    }

    public function testMapsWeightsToCleanPercentages(): void
    {
        $result = $this->calculator->calculate($this->clusters([
            ['#FF0000', 5000],
            ['#00FF00', 3000],
            ['#0000FF', 2000],
        ]));

        self::assertSame('#FF0000', $result[0]->color);
        self::assertSame([255, 0, 0], $result[0]->rgb);
        self::assertEqualsWithDelta(50.0, $result[0]->coveragePercent, 1e-9);
        self::assertEqualsWithDelta(30.0, $result[1]->coveragePercent, 1e-9);
        self::assertEqualsWithDelta(20.0, $result[2]->coveragePercent, 1e-9);
    }

    /**
     * @param list<int> $weights
     */
    #[DataProvider('weightSets')]
    public function testPercentagesAlwaysSumToOneHundred(array $weights): void
    {
        $spec = [];
        foreach ($weights as $i => $weight) {
            $spec[] = [sprintf('#%06X', $i * 0x111111), $weight];
        }

        $result = $this->calculator->calculate($this->clusters($spec));

        $tenths = 0;
        $sum = 0.0;
        foreach ($result as $coverage) {
            $tenths += (int) round($coverage->coveragePercent * 10);
            $sum += $coverage->coveragePercent;
        }

        self::assertSame(1000, $tenths, 'displayed percentages must sum to exactly 100.0');
        self::assertSame(100.0, round($sum, 1));
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function weightSets(): array
    {
        return [
            'equal thirds' => [[1, 1, 1]],
            'primes' => [[7, 11, 13, 29]],
            'lopsided' => [[9990, 7, 3]],
            'many small' => [[1, 1, 1, 1, 1, 1, 1]],
            'single' => [[42]],
        ];
    }

    public function testLargestRemainderDistribution(): void
    {
        // 1/3 each -> 333 tenths each, 1 leftover tenth to the lowest hex.
        $result = $this->calculator->calculate($this->clusters([
            ['#0000FF', 1],
            ['#00FF00', 1],
            ['#FF0000', 1],
        ]));

        self::assertSame(['#0000FF', '#00FF00', '#FF0000'], array_map(static fn ($c) => $c->color, $result));
        self::assertEqualsWithDelta(33.4, $result[0]->coveragePercent, 1e-9);
        self::assertEqualsWithDelta(33.3, $result[1]->coveragePercent, 1e-9);
        self::assertEqualsWithDelta(33.3, $result[2]->coveragePercent, 1e-9);
    }

    public function testSortedDescendingByCoverage(): void
    {
        $result = $this->calculator->calculate($this->clusters([
            ['#00FF00', 2000],
            ['#FF0000', 5000],
            ['#0000FF', 3000],
        ]));

        self::assertSame(['#FF0000', '#0000FF', '#00FF00'], array_map(static fn ($c) => $c->color, $result));
    }

    public function testEqualCoverageTiesBreakByHexAscending(): void
    {
        $result = $this->calculator->calculate($this->clusters([
            ['#FF0000', 50],
            ['#0000FF', 50],
        ]));

        self::assertSame(['#0000FF', '#FF0000'], array_map(static fn ($c) => $c->color, $result));
        self::assertEqualsWithDelta(50.0, $result[0]->coveragePercent, 1e-9);
        self::assertEqualsWithDelta(50.0, $result[1]->coveragePercent, 1e-9);
    }

    public function testTotalZeroReturnsEmptyList(): void
    {
        self::assertSame([], $this->calculator->calculate(new ClusterResult([], 0)));
    }

    public function testNoClustersReturnsEmptyList(): void
    {
        self::assertSame([], $this->calculator->calculate(new ClusterResult([], 100)));
    }

    public function testToArrayMatchesAssignmentShape(): void
    {
        $result = $this->calculator->calculate($this->clusters([['#FF0000', 1]]));

        self::assertSame(['color' => '#FF0000', 'coverage_percent' => 100.0], $result[0]->toArray());
    }

    /**
     * @param list<array{0:string,1:int}> $spec hex + weight per cluster
     */
    private function clusters(array $spec): ClusterResult
    {
        $clusters = [];
        $total = 0;
        foreach ($spec as [$hex, $weight]) {
            $clusters[] = new Cluster($this->color($hex), [0.0, 0.0, 0.0], $weight);
            $total += $weight;
        }

        return new ClusterResult($clusters, $total);
    }

    private function color(string $hex): ColorRGBA
    {
        /** @var array{0:int,1:int,2:int} $rgb */
        $rgb = sscanf($hex, '#%02x%02x%02x');

        return new ColorRGBA($rgb[0], $rgb[1], $rgb[2]);
    }
}
