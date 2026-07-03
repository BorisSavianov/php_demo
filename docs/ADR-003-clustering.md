# ADR-003: Color clustering — k-means++ over a weighted histogram, with automatic k

## Status

Accepted.

## Context

The library must reduce potentially millions of pixels to a handful of **principal print
colors**, each with an accurate coverage percentage. To be useful and gradable, it has to:

- be **resolution independent** — a 500 px thumbnail and a 20 MP scan of the same artwork
  should yield comparable results in comparable time;
- **group perceptually similar colors** so that anti-aliasing, JPEG fringes, and scan noise
  do not each become their own "principal" color;
- be **deterministic**, so tests can assert on the output;
- **ignore transparent pixels**; and
- carry **no runtime dependencies** — the math is small and worth owning.

## Decision

### 1. Bin first, cluster second

Never run k-means on raw pixels. `ColorHistogram` quantizes each channel to
`histogramBitsPerChannel` bits (default 5 → 32 levels/channel, ≤ 32³ bins) and accumulates
`sumR, sumG, sumB, count` per bin. The bin's representative color is the **weight-weighted
average** `round(sum/count)`, *not* the bin center — averaging removes the quantization bias
that would otherwise pull centroids toward bin boundaries. Clustering then runs on the unique
bins weighted by count, so cost depends on **color diversity, not pixel count**. This is the
single most important performance and noise-smoothing decision in the library.

### 2. Cluster in CIELAB

Each bin's representative RGB is converted to CIELAB (D65) once, via `ColorConverter`.
Euclidean distance in Lab ≈ perceived difference (ΔE, CIE76), so "group similar colors"
means the right thing. See [ADR-001](ADR-001-color-space.md) for the color-space decision.

### 3. k-means++ initialization, Lloyd iterations

`WeightedKMeans` seeds centroids with **k-means++** (the first centroid is weighted-random by
bin weight; each subsequent centroid is chosen with probability ∝ `weight · D²`), then runs
Lloyd iterations (assign each bin to the nearest centroid by squared Lab distance, recompute
each centroid as the weight-weighted Lab mean) until assignments stabilize or the safety cap
`WeightedKMeans::MAX_ITERATIONS` (100) is reached.

### 4. Automatic k via silhouette, with a distinct-color fallback

`KSelector` scores `k = 2 … min(kMax, bins − 1)` with a **weighted silhouette** and takes the
best. Silhouette needs at least one multi-point cluster, so it can never score the
all-singleton clustering `k = bins`. Two consequences are handled explicitly:

- A lone point in its cluster contributes a silhouette of 0 (the standard convention),
  preventing "every point its own cluster" from scoring a perfect 1.
- When no `k` in the searchable range clears `STRUCTURE_THRESHOLD` (0.5, the conventional
  "reasonable structure" cutoff), the bins have no real sub-structure — they are mutually
  distinct colors — so `KSelector` returns `min(kMax, bins)` and lets the merge pass (below)
  fold anything that is actually close. This is what makes a clean N-pure-color image resolve
  to N clusters.

WCSS (the elbow diagnostic) is available via `WeightedKMeans::wcss()`. Silhouette is O(bins²)
per `k`, so it is capped to the `SILHOUETTE_MAX_POINTS` (256) heaviest bins; the **final
clustering still uses every bin.**

### 5. Merge pass

After clustering, `KMeansClusterer` (a) repeatedly merges the closest pair of clusters while
their centroids are within `mergeDeltaE` (default 3.0), then (b) folds any cluster below
`minClusterCoverage` (default 0.01 = 1%) into its nearest neighbor. This stops anti-aliasing
halos and JPEG fringes from surfacing as principal colors.

### 6. Output color, independent of a Lab inverse

Each final cluster's output RGB is the **weight-weighted average of its member bins'
representative RGB**, clamped to gamut. This keeps the reported color faithful to the pixels
that actually contributed to the cluster and guaranteed in-gamut, without depending on a
`labToRgb` inverse of the Lab centroid (which can drift or land out of gamut). `ColorConverter`
does provide a `labToRgb`, but the clusterer intentionally does not use it for output.

### 7. Determinism

`WeightedKMeans` uses a **local, seeded `Random\Randomizer` backed by `Mt19937`** — never the
global `mt_srand`/`mt_rand` state — so clustering is a pure function of `(pixels, options)`
with no global side effects. The unit-float helper is built from `getInt()` rather than
`Randomizer::nextFloat()` to stay compatible with the PHP 8.2 floor. Ties (nearest centroid,
merge order, remainder distribution) always break to the lowest index / lowest hex. Same seed
⇒ identical centroids, weights, and ordering.

### 8. Coverage & rounding

`PercentageCoverageCalculator` computes `weight / total × 100` and normalizes with the
**largest-remainder method** in integer tenths, so the displayed values sum to exactly 100.0.
Results are sorted by coverage descending, ties broken by hex ascending. Transparent pixels
are excluded from both numerator and denominator (they never enter the histogram).

## Rationale for the algorithm choice

k-means++ + silhouette directly optimizes "few representative colors that minimize perceptual
spread," and — critically for a testable library — is deterministic.

## Alternatives considered

- **Median-cut / octree quantization** — fast and deterministic, but purely count-driven and
  not perceptually merged. Rejected as the *final* grouping; the coarse histogram binning
  plays the analogous "reduce first" role.
- **DBSCAN / mean-shift** — no `k` needed, but density-parameter sensitive, slower, and
  harder to make deterministic. Rejected.
- **Calinski–Harabasz / elbow as the primary k criterion** — usable, but silhouette is more
  directly interpretable ("how well-separated"); WCSS is kept only as a diagnostic.

## Consequences

- Determinism requires the seeded local RNG and deterministic tie-breaks, which are
  themselves tested.
- The merge step is essential — without it, anti-aliasing halos appear as principal colors.
- `MAX_ITERATIONS`, `SILHOUETTE_MAX_POINTS`, and `STRUCTURE_THRESHOLD` are documented tuning
  constants; the user-facing knobs live in `ClusterOptions`.

## Related documents

[Color Clustering & Coverage](modules/color-clustering-and-coverage.md) ·
[ADR-001 Color space](ADR-001-color-space.md) · [Architecture](architecture.md) ·
[Glossary](glossary.md)
