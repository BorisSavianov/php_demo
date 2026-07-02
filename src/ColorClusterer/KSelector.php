<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ColorClusterer;

use ImageColorAnalyzer\Color\ColorConverter;

/**
 * OWNER: Developer C.
 *
 * Chooses the number of clusters k. Primary criterion: a weighted silhouette
 * score over the histogram bins for k in 2..kMax; the higher the score, the
 * better-separated the clusters. Within-cluster sum of squares (the elbow/WCSS
 * curve) is available via {@see WeightedKMeans::wcss()} for diagnostics.
 *
 * Silhouette is O(bins^2) per candidate k, so the scored set is capped to the
 * heaviest {@see SILHOUETTE_MAX_POINTS} bins (the rest contribute negligible
 * coverage); the final clustering in {@see KMeansClusterer} still uses every bin.
 */
final class KSelector
{
    /** Upper bound on bins fed to the O(n^2) silhouette to keep k-selection cheap. */
    public const SILHOUETTE_MAX_POINTS = 256;

    /**
     * Minimum silhouette score to accept a sub-grouping as "real structure".
     * The conventional reading of silhouette values: > 0.7 strong, 0.5–0.7
     * reasonable, < 0.5 weak. Below this, the bins are treated as mutually
     * distinct colors (k = bin count, capped by kMax) and trimmed by the merge pass.
     */
    public const STRUCTURE_THRESHOLD = 0.5;

    private readonly WeightedKMeans $kmeans;

    public function __construct(
        private readonly ColorConverter $converter,
        ?WeightedKMeans $kmeans = null,
    ) {
        $this->kmeans = $kmeans ?? new WeightedKMeans();
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $labPoints one Lab triplet per histogram bin
     * @param list<int>                            $weights   pixel count per bin (same index)
     * @param int                                  $kMax      inclusive upper bound for the search
     * @param int                                  $seed      RNG seed forwarded to k-means++
     *
     * @return int chosen cluster count, in 1..min($kMax, count($labPoints))
     */
    public function select(array $labPoints, array $weights, int $kMax, int $seed = 1): int
    {
        $n = count($labPoints);
        if ($n <= 2) {
            return max(1, $n);
        }

        [$points, $pointWeights] = $this->capByWeight($labPoints, $weights, self::SILHOUETTE_MAX_POINTS);
        $m = count($points);

        // Silhouette needs at least one multi-point cluster, so it can only score
        // k up to m - 1; the all-singleton clustering (k = m) is handled below.
        $searchUpper = min($kMax, $m - 1);

        $bestK = 0;
        $bestScore = -INF;
        for ($k = 2; $k <= $searchUpper; $k++) {
            $result = $this->kmeans->run($points, $pointWeights, $k, $seed);
            $score = $this->weightedSilhouette($points, $pointWeights, $result['assignments'], $k);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestK = $k;
            }
        }

        // A clearly good grouping wins outright. Otherwise the bins have no strong
        // sub-structure (e.g. a handful of mutually distinct print colors), so treat
        // each heavy bin as its own color and let the merge pass fold the rest.
        if ($bestK > 0 && $bestScore >= self::STRUCTURE_THRESHOLD) {
            return $bestK;
        }

        return max(1, min($kMax, $m));
    }

    /**
     * Keeps the $limit heaviest bins (ties broken by lowest index), preserving
     * their original relative order so k-means++ sampling stays deterministic.
     *
     * @param list<array{0:float,1:float,2:float}> $labPoints
     * @param list<int>                            $weights
     *
     * @return array{0: list<array{0:float,1:float,2:float}>, 1: list<int>}
     */
    private function capByWeight(array $labPoints, array $weights, int $limit): array
    {
        $n = count($labPoints);
        if ($n <= $limit) {
            return [$labPoints, $weights];
        }

        $indices = range(0, $n - 1);
        usort($indices, static function (int $a, int $b) use ($weights): int {
            return $weights[$b] <=> $weights[$a] ?: $a <=> $b;
        });
        $kept = array_slice($indices, 0, $limit);
        sort($kept);

        $points = [];
        $pointWeights = [];
        foreach ($kept as $index) {
            $points[] = $labPoints[$index];
            $pointWeights[] = $weights[$index];
        }

        return [$points, $pointWeights];
    }

    /**
     * Weighted mean silhouette across all points, in [-1, 1].
     *
     * @param list<array{0:float,1:float,2:float}> $points
     * @param list<int>                            $weights
     * @param list<int>                            $assignments
     */
    private function weightedSilhouette(array $points, array $weights, array $assignments, int $k): float
    {
        $n = count($points);

        // Total weight and point count per cluster. The count lets us apply the
        // silhouette convention that a lone point in its cluster scores 0 (not 1,
        // which a naive a=0 would produce and would wrongly favor k = n).
        $weightInCluster = array_fill(0, $k, 0);
        $countInCluster = array_fill(0, $k, 0);
        foreach ($assignments as $i => $cluster) {
            $weightInCluster[$cluster] += $weights[$i];
            $countInCluster[$cluster]++;
        }

        $totalWeight = 0;
        $weightedScore = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $own = $assignments[$i];
            $totalWeight += $weights[$i];

            // A point alone in its cluster contributes 0 by convention.
            if ($countInCluster[$own] <= 1) {
                continue;
            }

            /** @var list<float> $distanceToCluster */
            $distanceToCluster = array_fill(0, $k, 0.0);
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }
                $distanceToCluster[$assignments[$j]] += $weights[$j] * $this->converter->deltaE($points[$i], $points[$j]);
            }

            $ownWeight = $weightInCluster[$own] - $weights[$i];
            $a = $ownWeight > 0 ? $distanceToCluster[$own] / $ownWeight : 0.0;

            $b = INF;
            for ($c = 0; $c < $k; $c++) {
                if ($c === $own || $weightInCluster[$c] === 0) {
                    continue;
                }
                $mean = $distanceToCluster[$c] / $weightInCluster[$c];
                if ($mean < $b) {
                    $b = $mean;
                }
            }

            $s = 0.0;
            if ($b !== INF) {
                $max = max($a, $b);
                $s = $max > 0.0 ? ($b - $a) / $max : 0.0;
            }

            $weightedScore += $weights[$i] * $s;
        }

        return $totalWeight > 0 ? $weightedScore / $totalWeight : 0.0;
    }
}
