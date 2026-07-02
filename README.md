# image-color-analyzer

A reusable, dependency-light PHP library that loads a **PNG or JPEG** image from
a file handle, stream, or path, crops the surrounding **near-white background**,
clusters the remaining colors, and reports each **principal print color** with
its **coverage percentage**. Output is designed to be consumed by another part
of a system (e.g. a print/pre-press pipeline).

```json
[
  { "color": "#FF0000", "coverage_percent": 42.5 },
  { "color": "#0000FF", "coverage_percent": 31.2 },
  { "color": "#00FF00", "coverage_percent": 26.3 }
]
```

## Requirements
- **PHP ≥ 8.2** with **`ext-gd`** (developed against 8.4; CI on 8.2 / 8.3 / 8.4 / 8.5)
- Optional **`ext-imagick`** — reserved for a CMYK/large-image adapter behind the
  same loader interface

## Install
```bash
composer install
```

## Quick start
```php
use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

$analyzer = AnalyzerFactory::createDefault();

// From a path:
$colors = $analyzer->analyzePath('/path/to/image.png');

// From a file handle (as the assignment requires):
$handle = fopen('/path/to/image.jpg', 'rb');
echo $analyzer->analyzeAsJson($handle);
fclose($handle);
```

`analyze()` accepts an `ImageSource`, a stream resource, a GD image, or **raw
image bytes** (a plain string is treated as bytes, never as a path); use
`analyzePath()` / `analyzePathAsJson()` for filesystem paths. Both return a
`list<array{color: string, coverage_percent: float}>` sorted by coverage
descending; the `*AsJson` variants return the same as pretty JSON. Runnable
scripts are in [`examples/`](examples).

## How it works
The library is a one-directional pipeline of small components behind stable
interfaces:

```
source ─▶ ImageLoader ─▶ Raster ─▶ WhiteBackgroundCropper ─▶ Raster
                                                              │
                                                              ▼
   ColorCoverage[] ◀─ CoverageCalculator ◀─ ClusterResult ◀─ KMeansClusterer
```

1. **Loader (GD)** decodes PNG/JPEG from a resource, stream, or path (format is
   sniffed from magic bytes, not the extension). Palette and grayscale images are
   normalized to truecolor and GD's 7-bit alpha is expanded to 0–255. CMYK JPEGs
   — which GD cannot read faithfully — are rejected with a clear
   `UnsupportedImageException`.
2. **White-background cropper** scans inward from each of the four edges and stops
   at the first row/column carrying real content, returning the smallest
   rectangle around it. "Near-white" is judged in CIELAB (`L* ≥ lightnessMin` and
   chroma `≤ chromaMax`), so slightly off-white scans/compression still crop. A
   per-line content-fraction guard keeps a few stray specks from defeating the
   crop, and because scanning is border-inward it **never removes legitimate white
   inside the artwork**.
3. **Clusterer** bins the cropped raster into a weighted color histogram
   (skipping transparent pixels), projects each bin to CIELAB, groups them with
   **k-means++ (seeded, deterministic)**, picks `k` automatically via a weighted
   silhouette, and merges near-duplicate / tiny clusters.
4. **Coverage calculator** turns cluster weights into percentages using
   **largest-remainder** rounding so the displayed values sum to exactly `100.0`.

See the architecture decision records in [`docs/`](docs):
[ADR-001 color space](docs/ADR-001-color-space.md),
[ADR-002 GD vs Imagick](docs/ADR-002-gd-vs-imagick.md),
[ADR-003 clustering](docs/ADR-003-clustering.md), and the frozen
[contracts](docs/contracts.md).

## Configuration
Pass an `AnalyzerOptions` to tune any stage:

```php
use ImageColorAnalyzer\Options\{AnalyzerOptions, CropOptions, ClusterOptions};

$options = new AnalyzerOptions(
    crop: new CropOptions(
        lightnessMin: 95.0,        // CIELAB L* at/above which a pixel may be "white"
        chromaMax: 5.0,            // max CIELAB chroma for "white"
        lineContentFraction: 0.002,// fraction of a line that must be content (noise guard)
        alphaThreshold: 8,         // alpha below this counts as background/transparent
    ),
    cluster: new ClusterOptions(
        fixedK: null,              // null => choose k automatically; or force a k
        kMax: 8,                   // upper bound for automatic k
        histogramBitsPerChannel: 5,// binning resolution (higher = finer, slower)
        mergeDeltaE: 3.0,          // merge clusters closer than this (CIE76 ΔE)
        minClusterCoverage: 0.01,  // fold clusters below this share into a neighbor
        seed: 1,                   // RNG seed; identical input + seed => identical output
        alphaThreshold: 8,         // alpha below this is ignored by the analysis
    ),
);

$colors = $analyzer->analyze($handle, $options);
```

## Output format
Each entry is `{ "color": "#RRGGBB", "coverage_percent": float }`. Colors are
uppercase hex; `analyze()` also exposes the RGB triplet via the
`ColorCoverage` DTO. Percentages are rounded to one decimal and sum to exactly
`100.0`. Transparent pixels are excluded from both the numerator and the
denominator, so a fully transparent image yields `[]`.

## Guarantees & limitations
- **Deterministic:** identical input and `seed` produce identical centroids,
  weights, and ordering — no global RNG state is touched.
- **Resolution independent:** clustering runs on the binned histogram, so a
  multi-megapixel scan costs about the same as a thumbnail (covered by a
  performance regression test).
- **Formats:** 8-bit PNG and JPEG. CMYK JPEG throws `UnsupportedImageException`
  (use Imagick); 16-bit/ICC-aware handling is out of scope for the GD driver.
- **Lossy input:** JPEG compression can introduce faint edge colors; binning and
  the merge/low-coverage passes fold most of them away, but a large, sharp
  color boundary may leave a small (~1%) artifact color.
- **Memory:** the default `InMemoryRaster` holds decoded pixels in PHP memory;
  extremely large images should be downscaled before analysis.

## Development
```bash
composer cs      # coding standards (PSR-12, php-cs-fixer)
composer stan    # static analysis (PHPStan level 8)
composer test    # unit + integration tests (PHPUnit)
```

### White-background cropping (tuning `CropOptions`)

Before clustering, the surrounding near-white background is trimmed by a
**border-inward scan**: it only ever moves the four edges toward the centre, so
white *inside* the artwork is never removed. "Near-white" is judged in CIELAB —
a pixel is background when it is transparent (`alpha < alphaThreshold`) or

```
L* >= lightnessMin  AND  chroma = sqrt(a*^2 + b*^2) <= chromaMax
```

| Option | Default | Raise it to… | Lower it to… |
|---|---|---|---|
| `lightnessMin` | `95.0` | trim only very bright borders | accept dimmer off-white/grey paper as background |
| `chromaMax` | `5.0` | tolerate tinted/yellowed scans | trim only truly neutral white (clean exports) |
| `lineContentFraction` | `0.002` | ignore heavier speckle/dust in the margin | react to fainter content |
| `alphaThreshold` | `8` | treat more semi-transparent pixels as background | keep faint pixels as content |

Guidance:
- **Clean digital exports** (pure `#FFFFFF` margin): defaults are ideal; drop
  `chromaMax` toward `2–3` if you want only exact white trimmed.
- **Scanned / photographed art** (off-white, warm cast, JPEG halos): raise
  `chromaMax` to `~8–10` and, for dim paper, lower `lightnessMin` to `~88–92`.
- A per-line noise guard (`lineContentFraction`) ignores stray specks in the
  margin, while a raw-extent fallback guarantees genuinely small content (a
  single pixel, a thin line) is preserved even below that floor.

```php
use ImageColorAnalyzer\Options\AnalyzerOptions;
use ImageColorAnalyzer\Options\CropOptions;

$opts = new AnalyzerOptions(crop: new CropOptions(lightnessMin: 90.0, chromaMax: 9.0));
$colors = $analyzer->analyzePath('/path/to/scan.jpg', $opts);
```

## Project layout
```
src/
  Contracts/              # frozen interfaces + DTOs
  Options/                # CropOptions, ClusterOptions, AnalyzerOptions
  Exception/              # typed exception hierarchy
  ImageLoader/            # GD loader, source handling, InMemoryRaster
  Color/                  # sRGB <-> Lab <-> HSV conversions, ΔE
  WhiteBackgroundCropper/ # near-white border-inward crop
  ColorClusterer/         # histogram + k-means++ + k selection
  CoverageCalculator/     # coverage percentages, largest-remainder
  PublicAPI/              # facade + default factory
tests/  examples/  docs/
```

## License
MIT.
