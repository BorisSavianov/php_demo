# Color Clustering & Coverage

> **Module scope:** `src/ColorClusterer/`, `src/CoverageCalculator/`, the example scripts,
> and [ADR-003](../ADR-003-clustering.md).
> **Originally owned by:** Developer C. *(Ownership is historical; the frozen interfaces are
> the real boundary.)*

## Purpose

This module produces the library's public answer: the **principal print colors** of the
cropped artwork, each with a **coverage percentage**. It receives an already-decoded,
already-cropped [`Raster`](../contracts.md) and turns it into a sorted list of
`ColorCoverage` values whose percentages sum to exactly `100.0`.

## Overview

Two stages, connected inside the clusterer:

```text
cropped Raster ─▶ ColorHistogram ─▶ KMeansClusterer ─▶ PercentageCoverageCalculator ─▶ ColorCoverage[]
```

Transparent pixels are excluded from **both** the numerator and the denominator, so they
never distort the result. The design rationale lives in [ADR-003](../ADR-003-clustering.md);
this guide is the operational reference.

## Clustering flow

1. **Bin first.** `ColorHistogram` reduces the raster to a weighted color histogram. With
   the default `histogramBitsPerChannel = 5`, each channel is quantized to 32 levels (≤ 32³
   bins), so clustering cost depends on **color diversity, not resolution**. Each bin's
   representative color is the weighted average of its pixels — not the bin center — which
   removes quantization bias.
2. **Project to CIELAB.** Every bin's representative RGB is converted to Lab once, via
   `ColorConverter`.
3. **Cluster.** `WeightedKMeans` runs deterministic weighted k-means++ seeding followed by
   Lloyd iterations, all in Lab. Distances in the hot loop are *squared* Euclidean (no
   `sqrt`), which preserves nearest-centroid ordering.
4. **Choose `k`.** If `fixedK` is `null`, `KSelector` evaluates candidate `k` values with
   two weighted [silhouette](../glossary.md) views and picks the best eligible candidate;
   otherwise the supplied `k` is used (clamped to the number of bins).
5. **Merge.** `KMeansClusterer` merges clusters within `mergeDeltaE` of each other, then
   folds any cluster below `minClusterCoverage` into its nearest surviving neighbor.
6. **Finalize.** Surviving clusters are sorted by weight descending (ties broken by hex
   ascending) and each cluster's output RGB is set to the weighted average of its member
   bins' representative RGB, clamped to the 0–255 gamut.

### `k` selection details

`KSelector` searches `k = 2 … min(kMax, bins − 1)` and computes two scores for each
candidate:

- A **bin-structure score** uses the standard convention that a cluster containing one
  histogram bin has silhouette `0`. A candidate must clear `STRUCTURE_THRESHOLD` (`0.5`)
  on this score. This prevents smooth gradients and anti-aliasing bins from winning merely
  because each bin represents repeated pixels.
- A **represented-pixel score** conceptually expands each bin by its pixel weight. Eligible
  candidates are ranked by this score, so a cluster containing one heavily weighted bin can
  preserve a materially present accent color instead of being penalized as one observation.

If no candidate clears the structure threshold, the bins have no strong sub-structure —
they are already mutually distinct colors — so the selector returns `min(kMax, bins)` and
lets the merge pass fold anything genuinely close. This is what makes a clean N-pure-color
image resolve to exactly N principal colors. Both scores share the same distance pass, so
the complexity remains O(bins²) per `k`. Scoring is capped to the
`SILHOUETTE_MAX_POINTS` (256) heaviest bins; the **final clustering still uses every bin.**

### Determinism

`WeightedKMeans` uses a **local `Randomizer(new Mt19937($seed))`** — never the global
`mt_rand` state — so clustering is a pure function of `(pixels, options)`. The unit-float
helper is implemented with `getInt()` rather than `Randomizer::nextFloat()` so the code
runs on PHP 8.2 (the project floor). Ties always break to the lowest index, then the lowest
hex.

## Important defaults (`ClusterOptions`)

| Option | Default | Meaning |
|---|---|---|
| `fixedK` | `null` | automatic `k` selection; set an int to force a specific `k` |
| `kMax` | `8` | upper bound on the number of principal colors chosen automatically |
| `histogramBitsPerChannel` | `5` | binning resolution (higher = finer, slower) |
| `mergeDeltaE` | `3.0` | merge clusters closer than this ΔE (CIE76) |
| `minClusterCoverage` | `0.01` | fold clusters below this share (1%) into a neighbor |
| `seed` | `1` | RNG seed; identical input + seed ⇒ identical output |
| `alphaThreshold` | `8` | alpha below this is ignored by the analysis |

## Coverage percentages

`PercentageCoverageCalculator` converts each cluster's weight to `weight / total × 100`,
rounded to one decimal using the **[largest-remainder method](../glossary.md)** in integer
tenths of a percent. This is why independent rounding can never produce a total like `99.9`
or `100.1`:

```text
sum(coverage_percent) == 100.0
```

Results are sorted by coverage descending, ties broken by hex ascending. For an empty or
fully transparent image, coverage returns `[]`. The facade's JSON path uses
`JSON_PRESERVE_ZERO_FRACTION`, so whole percentages stay float-shaped (`25.0`, not `25`).

## Output color, not a Lab inverse

Each principal color is reported as the weighted average of its member bins' **RGB**
representatives, clamped to gamut — not by inverting the cluster's Lab centroid back to RGB.
Averaging RGB is guaranteed in-gamut and keeps the reported color independent of any
Lab → RGB inverse, so the output stays faithful to the pixels that actually contributed to
the cluster.

## Performance & safety

- The **only full-image pass** is histogram construction; everything after it works on
  bounded bins.
- Silhouette scoring is capped to the 256 heaviest bins, and Lloyd iterations are capped at
  `MAX_ITERATIONS = 100`.
- There is no I/O, global state, `eval`, or output anywhere in the clustering or coverage
  code — it is pure and deterministic.

## Tests to protect

Core tests cover:

- three-color grouping and centroid proximity,
- automatic `k` and fixed `k`,
- preservation of a materially weighted single-bin accent without over-selecting bins,
- determinism for a fixed seed,
- transparent-pixel exclusion,
- merge and low-coverage fold behavior,
- total weight conservation,
- stable ordering,
- exact coverage sum to `100.0`,
- resolution independence (a performance regression test proving clustering cost tracks
  color diversity, not pixel count),
- end-to-end JSON shape, including float-shaped `coverage_percent`.

When clustering behavior changes, update [ADR-003](../ADR-003-clustering.md), the README
output/tuning notes, and the integration expectations **in the same change** so the docs
never drift from the code.

## Related documents

[Architecture](../architecture.md) · [ADR-003 Clustering](../ADR-003-clustering.md) ·
[ADR-001 Color space](../ADR-001-color-space.md) · [Frozen contracts](../contracts.md) ·
[Glossary](../glossary.md) · [Testing guide](../testing.md) · [README](../../README.md)
