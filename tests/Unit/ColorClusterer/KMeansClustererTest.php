<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ColorClusterer;

use PHPUnit\Framework\TestCase;

final class KMeansClustererTest extends TestCase
{
    public function testGroupsThreeDistinctColors(): void
    {
        // TODO(C): SyntheticImageFactory::bands(...) of red/green/blue
        //          -> expect ~3 clusters with centroids near the inputs.
        self::markTestIncomplete('KMeansClusterer::cluster() pending — Developer C.');
    }

    public function testIsDeterministicForFixedSeed(): void
    {
        // TODO(C): identical input + seed -> identical centroids and weights.
        self::markTestIncomplete('KMeansClusterer::cluster() pending — Developer C.');
    }

    public function testIgnoresTransparentPixels(): void
    {
        // TODO(C): transparent pixels excluded from totalAnalyzedPixels.
        self::markTestIncomplete('KMeansClusterer::cluster() pending — Developer C.');
    }
}
