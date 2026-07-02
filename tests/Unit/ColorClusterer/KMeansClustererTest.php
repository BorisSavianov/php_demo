<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\ColorClusterer\ColorHistogram;
use ImageColorAnalyzer\ColorClusterer\KMeansClusterer;
use ImageColorAnalyzer\ColorClusterer\KSelector;
use ImageColorAnalyzer\Contracts\ClusterResult;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\Options\ClusterOptions;
use ImageColorAnalyzer\Tests\Support\SyntheticImageFactory;
use PHPUnit\Framework\TestCase;

final class KMeansClustererTest extends TestCase
{
    private KMeansClusterer $clusterer;

    protected function setUp(): void
    {
        $converter = new ColorConverter();
        $this->clusterer = new KMeansClusterer($converter, new ColorHistogram(), new KSelector($converter));
    }

    public function testGroupsThreeDistinctColors(): void
    {
        $raster = $this->bands();

        $result = $this->clusterer->cluster($raster, new ClusterOptions());

        self::assertCount(3, $result->clusters);
        self::assertSame(10000, $result->totalAnalyzedPixels);
        self::assertSame(
            ['#FF0000' => 5000, '#00FF00' => 3000, '#0000FF' => 2000],
            $this->weightByHex($result),
        );
    }

    public function testAutomaticKFindsThreeWithoutFixedK(): void
    {
        $result = $this->clusterer->cluster($this->bands(), new ClusterOptions(fixedK: null));

        self::assertCount(3, $result->clusters);
    }

    public function testIsDeterministicForFixedSeed(): void
    {
        $raster = $this->bands();
        $options = new ClusterOptions(seed: 7);

        $first = $this->clusterer->cluster($raster, $options);
        $second = $this->clusterer->cluster($raster, $options);

        self::assertEquals($first, $second);
        self::assertSame($this->serializeClusters($first), $this->serializeClusters($second));
    }

    public function testClusterWeightsSumToTotalAnalyzedPixels(): void
    {
        $result = $this->clusterer->cluster($this->bands(), new ClusterOptions());

        $sum = 0;
        foreach ($result->clusters as $cluster) {
            $sum += $cluster->weight;
        }
        self::assertSame($result->totalAnalyzedPixels, $sum);
    }

    public function testIgnoresTransparentPixels(): void
    {
        // 60 opaque red pixels + 40 fully transparent -> only the red counts.
        $pixels = [];
        for ($i = 0; $i < 100; $i++) {
            $pixels[] = $i < 60 ? new ColorRGBA(255, 0, 0) : new ColorRGBA(0, 0, 0, 0);
        }
        $raster = new InMemoryRaster(10, 10, $pixels, true);

        $result = $this->clusterer->cluster($raster, new ClusterOptions());

        self::assertSame(60, $result->totalAnalyzedPixels);
        self::assertCount(1, $result->clusters);
        self::assertSame('#FF0000', $result->clusters[0]->centroid->toHex());
        self::assertSame(60, $result->clusters[0]->weight);
    }

    public function testHonorsFixedK(): void
    {
        $raster = SyntheticImageFactory::bands(50, 50, [
            ['color' => new ColorRGBA(255, 0, 0), 'fraction' => 0.2],
            ['color' => new ColorRGBA(0, 255, 0), 'fraction' => 0.2],
            ['color' => new ColorRGBA(0, 0, 255), 'fraction' => 0.2],
            ['color' => new ColorRGBA(255, 255, 0), 'fraction' => 0.2],
            ['color' => new ColorRGBA(0, 0, 0), 'fraction' => 0.2],
        ]);

        $result = $this->clusterer->cluster($raster, new ClusterOptions(fixedK: 2));

        self::assertCount(2, $result->clusters);
    }

    public function testMergesClustersWithinDeltaE(): void
    {
        // Two very different colors, but a huge merge threshold collapses everything.
        $raster = SyntheticImageFactory::bands(20, 20, [
            ['color' => new ColorRGBA(255, 0, 0), 'fraction' => 0.5],
            ['color' => new ColorRGBA(0, 0, 255), 'fraction' => 0.5],
        ]);

        $result = $this->clusterer->cluster($raster, new ClusterOptions(fixedK: 2, mergeDeltaE: 500.0));

        self::assertCount(1, $result->clusters);
        self::assertSame(400, $result->clusters[0]->weight);
    }

    public function testFoldsLowCoverageSpeckleIntoNeighbor(): void
    {
        // 9990 red + 10 blue (0.1% < default 1% floor) -> blue folds away.
        $pixels = array_fill(0, 10000, new ColorRGBA(255, 0, 0));
        for ($i = 0; $i < 10; $i++) {
            $pixels[$i] = new ColorRGBA(0, 0, 255);
        }
        $raster = new InMemoryRaster(100, 100, $pixels);

        $result = $this->clusterer->cluster($raster, new ClusterOptions());

        self::assertCount(1, $result->clusters);
        self::assertSame(10000, $result->clusters[0]->weight);
    }

    public function testKeepsDistinctColorsWhenMergeDisabled(): void
    {
        $raster = SyntheticImageFactory::bands(20, 20, [
            ['color' => new ColorRGBA(255, 0, 0), 'fraction' => 0.5],
            ['color' => new ColorRGBA(0, 0, 255), 'fraction' => 0.5],
        ]);

        $result = $this->clusterer->cluster($raster, new ClusterOptions(fixedK: 2, mergeDeltaE: 0.0, minClusterCoverage: 0.0));

        self::assertCount(2, $result->clusters);
    }

    public function testFullyTransparentImageYieldsNoClusters(): void
    {
        $raster = SyntheticImageFactory::solid(8, 8, new ColorRGBA(0, 0, 0, 0));

        $result = $this->clusterer->cluster($raster, new ClusterOptions());

        self::assertSame(0, $result->totalAnalyzedPixels);
        self::assertSame([], $result->clusters);
    }

    public function testMonochromeImageYieldsSingleCluster(): void
    {
        $raster = SyntheticImageFactory::solid(16, 16, new ColorRGBA(12, 34, 56));

        $result = $this->clusterer->cluster($raster, new ClusterOptions());

        self::assertCount(1, $result->clusters);
        self::assertSame(256, $result->clusters[0]->weight);
        self::assertSame('#0C2238', $result->clusters[0]->centroid->toHex());
    }

    public function testClustersAreSortedByWeightDescending(): void
    {
        $result = $this->clusterer->cluster($this->bands(), new ClusterOptions());

        $weights = array_map(static fn ($c) => $c->weight, $result->clusters);
        $sorted = $weights;
        rsort($sorted);
        self::assertSame($sorted, $weights);
    }

    private function bands(): Raster
    {
        return SyntheticImageFactory::bands(100, 100, [
            ['color' => new ColorRGBA(255, 0, 0), 'fraction' => 0.5],
            ['color' => new ColorRGBA(0, 255, 0), 'fraction' => 0.3],
            ['color' => new ColorRGBA(0, 0, 255), 'fraction' => 0.2],
        ]);
    }

    /**
     * @return array<string, int> centroid hex => weight
     */
    private function weightByHex(ClusterResult $result): array
    {
        $map = [];
        foreach ($result->clusters as $cluster) {
            $map[$cluster->centroid->toHex()] = $cluster->weight;
        }

        return $map;
    }

    /**
     * @return list<array{hex:string, lab:array{0:float,1:float,2:float}, weight:int}>
     */
    private function serializeClusters(ClusterResult $result): array
    {
        return array_map(
            static fn ($c) => ['hex' => $c->centroid->toHex(), 'lab' => $c->lab, 'weight' => $c->weight],
            $result->clusters,
        );
    }
}
