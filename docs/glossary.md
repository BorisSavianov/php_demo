# Glossary

A single, authoritative definition for every domain and algorithm term used across
the `image-color-analyzer` documentation. When a term is used elsewhere in the docs,
it carries the meaning defined here. Terms are grouped by theme and alphabetized
within each group.

Related reading: [Architecture](architecture.md) · [Frozen contracts](contracts.md) ·
[ADR-001 Color space](ADR-001-color-space.md) · [ADR-003 Clustering](ADR-003-clustering.md).

---

## Color science

### CIELAB (L\*a\*b\*)
A perceptually uniform color space in which Euclidean distance between two colors
approximates the difference a human eye perceives. It has three axes: **L\*** (lightness,
0 = black to 100 = white), **a\*** (green–red), and **b\*** (blue–yellow). The library
performs all analysis — near-white detection and clustering — in CIELAB. See
[ADR-001](ADR-001-color-space.md).

### Chroma
The colorfulness of a pixel, computed in CIELAB as `sqrt(a*² + b*²)`. Low chroma means
the color is close to neutral gray/white. The [White Background Cropper](modules/white-background-cropper.md)
treats a pixel as background when its lightness is high **and** its chroma is low.

### D65
The "daylight 6500 K" reference white point used throughout the CIELAB conversions.
It fixes what counts as neutral white, making results device-independent.

### ΔE (Delta-E, CIE76)
The perceptual distance between two colors, measured as the straight-line (Euclidean)
distance between their CIELAB coordinates. The `CIE76` formula is used because it *is*
plain Euclidean distance in Lab, which keeps clustering fast and its threshold intuitive
(roughly, ΔE ≈ 1 is a just-noticeable difference; ΔE ≈ 2–3 is very close). A weighted
`CIE94` variant (`ColorConverter::deltaE94()`) is also implemented for graphic-arts use,
but the clustering and merge logic use `CIE76`.

### HSV
A cylindrical color model (Hue, Saturation, Value). The library can convert to and from
HSV via `ColorConverter`, but does **not** use it for analysis: hue wraps at 360° and the
model is perceptually non-uniform, so Euclidean distance in HSV is ill-defined. See
[ADR-001](ADR-001-color-space.md) for why CIELAB was chosen instead.

### sRGB
The standard 8-bit-per-channel RGB color space of typical PNG/JPEG images. It is the
library's **transport format** — the format of input pixels and of output hex colors —
but not its analysis space. Conversion path: sRGB → linearized RGB → XYZ (D65) → CIELAB.

### XYZ
A device-independent tristimulus color space that sits between linearized sRGB and CIELAB
in the conversion pipeline. An implementation detail of `ColorConverter`; it does not
appear in any public result.

---

## Image model

### Alpha threshold
The opacity level (`alphaThreshold`, default `8` on a 0–255 scale) below which a pixel is
treated as transparent and therefore excluded from analysis. Fully and nearly transparent
pixels count toward neither the numerator nor the denominator of coverage.

### Bounding box
An axis-aligned rectangle (`x`, `y`, `width`, `height`) expressed in the **original**
image's coordinate system. The cropper returns one to describe the content region it
found. Represented by the `BoundingBox` DTO.

### ColorRGBA
The library's immutable 8-bit color value object: red, green, blue, and alpha channels,
each 0–255. Provides `isTransparent()`, `toHex()` (`#RRGGBB`), and `toRgbTriplet()`.

### Magic bytes
The first few bytes of a file that identify its format (e.g. the PNG signature, the JPEG
`FF D8` marker). The loader detects PNG vs JPEG from magic bytes, **never** from the file
extension, so a mislabeled file is still handled correctly.

### Palette (indexed) vs truecolor
A **palette** image stores pixels as indices into a small color table; a **truecolor**
image stores full RGB(A) per pixel. GD can produce either, so the loader normalizes every
decoded image to truecolor with an explicit alpha channel before building a raster.

### Raster
The library's central data structure: an immutable, row-major grid of `ColorRGBA` pixels
behind the `Raster` interface (`width()`, `height()`, `hasAlpha()`, `pixelAt()`,
`pixels()`, `crop()`). Every stage after loading consumes and/or produces a `Raster`. The
default `GdRaster` reads pixels lazily from a private native bitmap and represents crops as
views; `InMemoryRaster` materializes a pixel list for synthetic or custom use cases.

---

## Clustering & coverage

### Centroid
The representative point of a cluster. During iteration the centroid is the weight-weighted
mean of its members **in CIELAB**; the final reported color is the weight-weighted mean of
its members' **RGB** representatives, clamped to the valid 0–255 gamut.

### Cluster
A group of similar colors treated as one principal color. Represented by the `Cluster` DTO:
a `centroid` (`ColorRGBA`), its CIELAB triplet, and an integer `weight`.

### Coverage percentage
The share of analyzed pixels belonging to a cluster, expressed as a percentage rounded to
one decimal place. Reported per principal color; the values always sum to exactly `100.0`
(see *Largest-remainder method*). Transparent pixels are excluded from the total.

### Histogram binning
The "reduce first" step: each color channel is quantized to `histogramBitsPerChannel` bits
(default 5 → 32 levels/channel, at most 32³ bins). Pixels falling in the same bin are
accumulated into a single weighted color. Clustering then runs on the bins, so its cost
depends on **color diversity, not pixel count** — the basis of the library's resolution
independence. Each bin's representative color is the weighted average of its pixels, not
the bin center, which removes quantization bias.

### k-means / k-means++
**k-means** partitions points into `k` clusters by alternately assigning each point to its
nearest centroid and recomputing centroids (Lloyd iterations). **k-means++** is the seeding
strategy used here: the first centroid is chosen weighted-random by bin weight, and each
subsequent centroid is chosen with probability proportional to `weight · D²` (its squared
distance to the nearest already-chosen centroid). This yields well-separated, reproducible
seeds. See [ADR-003](ADR-003-clustering.md).

### Largest-remainder method
The rounding scheme that makes coverage percentages sum to exactly `100.0`. Work is done in
integer tenths of a percent (1000 tenths total): each cluster gets its floored share, then
the leftover tenths are handed to the clusters with the largest fractional remainders
(ties broken by larger weight, then lower hex). This avoids "99.9%" or "100.1%" artifacts
that independent rounding would produce.

### Lloyd iteration
One round of the k-means loop: reassign every point to its nearest centroid, then move each
centroid to the weighted mean of its assigned points. Iterations stop when assignments stop
changing or a safety cap (`WeightedKMeans::MAX_ITERATIONS`, 100) is reached.

### Merge pass
The post-clustering cleanup that keeps anti-aliasing halos and JPEG fringes from surfacing
as principal colors. It (1) repeatedly merges the closest pair of clusters while they are
within `mergeDeltaE` (default `3.0`) of each other, then (2) folds any cluster below
`minClusterCoverage` (default `0.01` = 1%) into its nearest surviving neighbor.

### Principal print color
A dominant color of the artwork after the near-white background is removed and similar
colors are merged — one of the entries in the library's output. "Print" reflects the
tool's pre-press use case, where these are the inks a design would require.

### Silhouette score
The metric used to choose `k` automatically. For each point it compares how close the point
is to its own cluster versus the nearest other cluster, yielding a value in `[-1, 1]`
(higher is better-separated). The library computes a **weighted** silhouette over the
histogram bins and picks the `k` with the best score, provided it clears
`STRUCTURE_THRESHOLD` (0.5). Because silhouette is O(bins²) per candidate `k`, it is scored
on at most the `SILHOUETTE_MAX_POINTS` (256) heaviest bins; the final clustering still uses
every bin.

### WCSS (Within-Cluster Sum of Squares)
The total squared distance from each point to its cluster centroid — the classic "elbow"
diagnostic. Available via `WeightedKMeans::wcss()` for analysis, but the silhouette score,
not WCSS, is the primary criterion for choosing `k`.

---

## Determinism & configuration

### Determinism
The guarantee that identical input plus identical options (crucially, the same `seed`)
always produce identical output — same colors, same weights, same ordering. Achieved with a
local seeded random generator (never global RNG state) and deterministic tie-breaking
(lowest index, then lowest hex). Determinism is what makes the algorithm testable.

### Seed
The integer (`ClusterOptions::seed`, default `1`) that initializes the local random number
generator used by k-means++. Same seed ⇒ same result; changing it explores different but
equally valid clusterings.

### `maxPixels` guard
A ceiling on total image size (`GdImageLoader`, default `64_000_000` pixels). Images above
it are rejected with `UnsupportedImageException` before normalization and analysis begin.

---

## Project & process

### ADR (Architecture Decision Record)
A short document capturing one significant design decision — its context, the choice made,
the rationale, and the consequences. The project's ADRs live in [`docs/`](.) and are the
canonical record of *why* the system is built the way it is.

### Facade
The single public entry point, `ImageColorAnalyzer`, that wires the pipeline stages together
and exposes the legacy `analyze*()` methods plus the additive `process*()` cropped-image
methods. Callers interact only with the facade (and `AnalyzerFactory`), never with
individual stages.

### Frozen contract
An interface or DTO in `src/Contracts` or `src/Options` that downstream code builds against
and that may not change without an ADR and sign-off. Freezing these seams is what let the
modules be developed independently. See [contracts.md](contracts.md).

### CMYK
A four-channel (cyan, magenta, yellow, black) color model used in professional printing. GD
cannot decode CMYK JPEGs reliably, so the loader detects them and either routes to the
optional Imagick loader or raises `UnsupportedImageException`. See
[ADR-002](ADR-002-gd-vs-imagick.md).
