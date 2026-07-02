# ADR-003: k-means++ over a weighted histogram, with automatic k

## Status
Accepted.

## Context
We must reduce potentially millions of pixels to a few principal colors, handle
varying resolutions, and stay deterministic for tests.

## Decision
1. Bin pixels into a coarse RGB **histogram** (default 5 bits/channel), skipping
   transparent pixels; each bin carries a pixel count.
2. Run **k-means (Lloyd) with k-means++** seeding in CIELAB over the weighted
   unique colors (not raw pixels), with a fixed RNG seed.
3. Choose **k** automatically via **silhouette score** for k in 2..kMax (elbow/
   WCSS computed for diagnostics), or honor a fixed k.
4. **Merge** clusters within `mergeDeltaE` or below `minClusterCoverage`.

## Rationale
- Histogram binning makes cost depend on color diversity, not image size
  (resolution independence) and smooths compression/anti-aliasing noise.
- k-means++ gives well-separated, reproducible seeds; Lab distance matches
  perception; silhouette picks a defensible k automatically.
- Considered and rejected as defaults: median-cut/octree (fast but not
  perceptually merged — reused as the binning step); DBSCAN/mean-shift
  (parameter-sensitive, slower).

## Consequences
- Determinism requires a seeded RNG and deterministic tie-breaks.
- The merge step is essential to stop anti-aliasing halos from appearing as
  principal colors.
