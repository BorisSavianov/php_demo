# Developer C Module Guide
## Color Clustering, Coverage, Examples, and User Docs

Read time: under 3 minutes.

Developer C owns the stages that produce the public answer: principal colors plus coverage percentages. The owned code is `src/ColorClusterer/`, `src/CoverageCalculator/`, clustering/coverage tests, examples, `docs/ADR-003-clustering.md`, and the user-facing README sections for output and tuning.

### Pipeline Role

```text
cropped Raster -> ColorHistogram -> KMeansClusterer
               -> PercentageCoverageCalculator -> ColorCoverage[]
```

The clusterer receives an already-decoded, already-cropped `Raster`. Transparent pixels are excluded from both numerator and denominator.

### Clustering Flow

1. `ColorHistogram` bins pixels before clustering. The default `histogramBitsPerChannel=5` creates at most 32^3 bins, so clustering cost depends on color diversity rather than image resolution.
2. Each bin stores a weighted average RGB representative plus a pixel weight.
3. RGB representatives are projected to Lab via A's `ColorConverter`.
4. `WeightedKMeans` runs deterministic weighted k-means++ and Lloyd iterations in Lab.
5. `KSelector` chooses k with weighted silhouette unless `fixedK` is supplied.
6. `KMeansClusterer` merges clusters within `mergeDeltaE` and folds clusters below `minClusterCoverage`.
7. Final clusters are sorted by weight descending, with hex order as the stable tie-break.

`WeightedKMeans` uses a local `Randomizer(new Mt19937($seed))`; it never touches global RNG. `nextUnitFloat()` is implemented without `Randomizer::nextFloat()` so the code remains compatible with PHP 8.2.

### Important Defaults

From `ClusterOptions`:

- `fixedK=null`: automatic k selection.
- `kMax=8`: cap for automatic principal colors.
- `histogramBitsPerChannel=5`: accuracy/performance balance.
- `mergeDeltaE=3.0`: fold visually near-identical clusters.
- `minClusterCoverage=0.01`: fold sub-1% speckle/fringe clusters.
- `seed=1`: deterministic k-means++.
- `alphaThreshold=8`: ignore nearly transparent pixels.

### Coverage Percentages

`PercentageCoverageCalculator` converts cluster weights to one-decimal percentages using the largest-remainder method in integer tenths. This prevents independent rounding from producing totals like `99.9` or `100.1`.

Invariant to preserve:

```text
sum(coverage_percent) == 100.0
```

For empty or fully transparent images, coverage returns `[]`.

The facade's JSON path uses `JSON_PRESERVE_ZERO_FRACTION`, so whole percentages remain float-shaped in output.

### Performance And Safety

The only full-image pass is histogram construction. After that, work happens on bounded bins. Silhouette scoring is capped to the 256 heaviest bins, and Lloyd iterations are capped at 100. There is no I/O, global state, eval, or output in the clustering/coverage code.

Output RGB is the weighted average of member bins' representative RGB, not a Lab inverse. This keeps colors in gamut and avoids a fragile inverse-conversion dependency.

### Tests To Protect

Core tests cover:

- three-color grouping and centroid proximity,
- automatic k and fixed k,
- determinism for a fixed seed,
- transparent-pixel exclusion,
- merge and low-coverage fold behavior,
- total weight conservation,
- stable ordering,
- exact coverage sum to `100.0`,
- end-to-end JSON shape including float `coverage_percent`.

When clustering behavior changes, update `docs/ADR-003-clustering.md`, README examples/tuning notes, and integration expectations in the same PR.

Cross-references: `Developer_C_Plan.md`, `docs/ADR-003-clustering.md`, `docs/ADR-001-color-space.md`, `docs/contracts.md`, `README.md`.
