<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\Exception\NotImplementedException;

/**
 * OWNER: Developer C.
 *
 * Chooses the number of clusters k. Primary criterion: silhouette score over
 * the weighted histogram for k in 2..kMax; within-cluster SSE (elbow) is
 * computed alongside for diagnostics.
 *
 * TODO(C): implement select().
 */
final class KSelector
{
    public function __construct(private readonly ColorConverter $converter)
    {
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $labPoints
     * @param list<int>                            $weights
     */
    public function select(array $labPoints, array $weights, int $kMax): int
    {
        throw new NotImplementedException('KSelector::select() pending — Developer C.');
    }
}
