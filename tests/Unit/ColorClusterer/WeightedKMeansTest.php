<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use ImageColorAnalyzer\ColorClusterer\WeightedKMeans;
use PHPUnit\Framework\TestCase;

final class WeightedKMeansTest extends TestCase
{
    /**
     * Two tight groups far apart in Lab; k=2 must split them cleanly.
     */
    public function testSeparatesTwoObviousGroups(): void
    {
        $points = [
            [10.0, 0.0, 0.0], [12.0, 1.0, -1.0], [11.0, -1.0, 0.0],
            [90.0, 0.0, 0.0], [88.0, 1.0, 1.0], [91.0, -1.0, 0.0],
        ];
        $weights = [1, 1, 1, 1, 1, 1];

        $result = (new WeightedKMeans())->run($points, $weights, 2, 1);

        $assignments = $result['assignments'];
        // First three share a label; last three share the other label.
        self::assertSame($assignments[0], $assignments[1]);
        self::assertSame($assignments[0], $assignments[2]);
        self::assertSame($assignments[3], $assignments[4]);
        self::assertSame($assignments[3], $assignments[5]);
        self::assertNotSame($assignments[0], $assignments[3]);
    }

    public function testIsDeterministicForFixedSeed(): void
    {
        $points = [[10.0, 5.0, -3.0], [80.0, -20.0, 40.0], [50.0, 0.0, 0.0], [12.0, 6.0, -2.0]];
        $weights = [4, 2, 7, 3];

        $first = (new WeightedKMeans())->run($points, $weights, 3, 42);
        $second = (new WeightedKMeans())->run($points, $weights, 3, 42);

        self::assertSame($first, $second);
    }

    public function testDifferentSeedsStayWithinValidLabelRange(): void
    {
        $points = [[10.0, 5.0, -3.0], [80.0, -20.0, 40.0], [50.0, 0.0, 0.0], [12.0, 6.0, -2.0]];
        $weights = [1, 1, 1, 1];

        foreach ([1, 2, 7, 99] as $seed) {
            $result = (new WeightedKMeans())->run($points, $weights, 2, $seed);
            self::assertCount(2, $result['centroids']);
            foreach ($result['assignments'] as $label) {
                self::assertGreaterThanOrEqual(0, $label);
                self::assertLessThan(2, $label);
            }
        }
    }

    public function testSingleClusterCentroidIsWeightedMean(): void
    {
        $points = [[0.0, 0.0, 0.0], [100.0, 0.0, 0.0]];
        $weights = [3, 1]; // weighted mean L = (0*3 + 100*1)/4 = 25

        $result = (new WeightedKMeans())->run($points, $weights, 1, 1);

        self::assertSame([0, 0], $result['assignments']);
        self::assertEqualsWithDelta(25.0, $result['centroids'][0][0], 1e-9);
    }

    public function testKClampedToPointCount(): void
    {
        $points = [[0.0, 0.0, 0.0], [50.0, 0.0, 0.0]];
        $weights = [1, 1];

        $result = (new WeightedKMeans())->run($points, $weights, 8, 1);

        self::assertCount(2, $result['centroids']);
    }

    public function testWcssIsZeroWhenEachPointIsItsOwnCentroid(): void
    {
        $points = [[0.0, 0.0, 0.0], [50.0, 0.0, 0.0], [90.0, 0.0, 0.0]];
        $weights = [1, 1, 1];
        $kmeans = new WeightedKMeans();

        $result = $kmeans->run($points, $weights, 3, 1);

        self::assertEqualsWithDelta(0.0, $kmeans->wcss($points, $weights, $result['centroids'], $result['assignments']), 1e-9);
    }

    public function testWcssShrinksAsKGrows(): void
    {
        $points = [[0.0, 0.0, 0.0], [10.0, 0.0, 0.0], [80.0, 0.0, 0.0], [90.0, 0.0, 0.0]];
        $weights = [1, 1, 1, 1];
        $kmeans = new WeightedKMeans();

        $one = $kmeans->run($points, $weights, 1, 1);
        $two = $kmeans->run($points, $weights, 2, 1);

        $wcssOne = $kmeans->wcss($points, $weights, $one['centroids'], $one['assignments']);
        $wcssTwo = $kmeans->wcss($points, $weights, $two['centroids'], $two['assignments']);

        self::assertGreaterThan($wcssTwo, $wcssOne);
    }
}
