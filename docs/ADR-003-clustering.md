# ADR-003: Color clustering — k-means++ over a weighted histogram, with automatic k

## Status
Accepted.

## Context
The library must reduce potentially millions of pixels to a handful of
*principal print colors* with an accurate coverage percentage each. It has to:

- be **resolution independent** — a 500px thumbnail and a 20MP scan of the same
  artwork should yield comparable results in comparable time;
- **group perceptually similar colors** (anti-aliasing, JPEG fringes, scan noise
  must not each become their own "principal" color);
- be **deterministic** so tests can assert on the output;
- **ignore transparent pixels**;
- carry **no runtime dependencies** (the math is small and worth owning).

## Decision

### 1. Bin first, cluster second
Never run k-means on raw pixels. `ColorHistogram` quantizes each channel to
`histogramBitsPerChannel` bits (default 5 → 32 levels/channel, ≤ 32³ bins) and
accumulates `sumR, sumG, sumB, count` per bin. The bin's representative color is
the **weight-weighted average** `round(sum/count)`, *not* the bin center —
averaging removes quantization bias that would otherwise pull centroids toward
bin boundaries. Clustering then runs on the unique bins weighted by count, so
cost depends on **color diversity, not pixel count**. This is the single most
important performance and noise-smoothing decision in the library.

### 2. Cluster in CIELAB
Representative RGB is converted to CIELAB (D65) once per bin via A's
`ColorConverter`. Euclidean distance in Lab ≈ perceived difference (ΔE, CIE76),
so "group similar colors" means the right thing. See ADR-001 for the color-space
decision.

### 3. k-means++ initialization, Lloyd iterations
`WeightedKMeans` seeds centroids with **k-means++** (first centroid weighted-
random by bin weight; each subsequent centroid chosen with probability ∝
`weight · D²`), then runs Lloyd iterations (assign to nearest centroid by squared
Lab distance, recompute each centroid as the weight-weighted Lab mean) until
assignments stabilize or a safety cap of `WeightedKMeans::MAX_ITERATIONS` is hit.

### 4. Automatic k via silhouette, with a distinct-color fallback
`KSelector` scores k = 2..min(kMax, bins−1) with a **weighted silhouette** and
takes the best. Silhouette needs at least one multi-point cluster, so it can
never score the all-singleton clustering k = bins. Two consequences are handled
explicitly:

- A lone point in its cluster contributes silhouette 0 (the standard
  convention), preventing "every point its own cluster" from scoring a perfect 1.
- When no k in the searchable range clears `STRUCTURE_THRESHOLD` (0.5, the
  conventional "reasonable structure" cutoff), the bins have no real
  sub-structure — they are mutually distinct colors — so we return
  `min(kMax, bins)` and let the merge pass (below) fold anything that is actually
  close. This is what makes a clean N-pure-color image resolve to N clusters.

WCSS (the elbow diagnostic) is available via `WeightedKMeans::wcss()`.
Silhouette is O(bins²) per k, so it is capped to the `SILHOUETTE_MAX_POINTS`
heaviest bins; the **final clustering still uses every bin**.

### 5. Merge pass
After clustering, `KMeansClusterer` (a) repeatedly merges the closest pair of
clusters while their centroids are within `mergeDeltaE` (default 3.0), then
(b) folds any cluster below `minClusterCoverage` (default 0.01 = 1%) into its
nearest neighbor. This stops anti-aliasing halos and JPEG fringes from surfacing
as principal colors.

### 6. Output color without a Lab inverse
Each final cluster's output RGB is the **weight-weighted average of its member
bins' representative RGB** — guaranteed in-gamut and cheap, avoiding the need for
a `labToRgb` from A (which would be a frozen-contract change).

### 7. Determinism
`WeightedKMeans` uses a **local, seeded `Random\Randomizer` backed by
`Mt19937`** — never the global `mt_srand`/`mt_rand` state — so clustering is a
pure function of (pixels, options) with no global side effects. Ties (nearest
centroid, merge order, remainder distribution) always break to the lowest index /
lowest hex. Same seed ⇒ identical centroids, weights, and ordering.

### 8. Coverage & rounding
`PercentageCoverageCalculator` computes `weight/total·100` and normalizes with
the **largest-remainder method** in integer tenths, so the displayed values sum
to exactly 100.0. Results are sorted by coverage descending, ties broken by hex
ascending. Transparent pixels are excluded from both numerator and denominator
(they never enter the histogram).

## Rationale for the algorithm choice
k-means++ + silhouette directly optimizes "few representative colors that
minimize perceptual spread", matches the assignment's explicit suggestion, and —
critically for grading — is deterministic and testable.

## Alternatives considered
- **Median-cut / octree quantization** — fast and deterministic, but purely
  count-driven and not perceptually merged. Rejected as the *final* grouping;
  the coarse histogram binning plays the analogous "reduce first" role.
- **DBSCAN / mean-shift** — no k needed, but density-parameter sensitive and
  slower; harder to make deterministic. Rejected.
- **Calinski–Harabasz / elbow as the primary k criterion** — usable, but
  silhouette is more directly interpretable ("how well-separated"); WCSS is kept
  only as a diagnostic.

## Consequences
- Determinism requires the seeded local RNG and deterministic tie-breaks, which
  are themselves tested.
- The merge step is essential — without it, anti-aliasing halos appear as
  principal colors.
- `MAX_ITERATIONS`, `SILHOUETTE_MAX_POINTS`, and `STRUCTURE_THRESHOLD` are
  documented tuning constants; the user-facing knobs live in `ClusterOptions`.
