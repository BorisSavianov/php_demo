<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Contracts\ClustererInterface;
use ImageColorAnalyzer\Contracts\ClusterResult;
use ImageColorAnalyzer\Contracts\Raster;
use ImageColorAnalyzer\Exception\NotImplementedException;
use ImageColorAnalyzer\Options\ClusterOptions;

/**
 * OWNER: Developer C.
 *
 * k-means (Lloyd) with k-means++ seeded initialization, run in CIELAB over the
 * weighted histogram. k is fixed (options->fixedK) or chosen by {@see KSelector}.
 * A post-pass merges clusters within mergeDeltaE or below minClusterCoverage.
 *
 * TODO(C): implement cluster(); guarantee determinism for a fixed seed.
 */
final class KMeansClusterer implements ClustererInterface
{
    public function __construct(
        private readonly ColorConverter $converter,
        private readonly ColorHistogram $histogram,
        private readonly KSelector $kSelector,
    ) {
    }

    public function cluster(Raster $image, ClusterOptions $options): ClusterResult
    {
        throw new NotImplementedException('KMeansClusterer::cluster() pending — Developer C.');
    }
}
