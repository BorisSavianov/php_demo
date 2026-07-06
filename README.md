# image-color-analyzer

A reusable, dependency-light PHP library that loads a **PNG or JPEG** image from a file
handle, stream, or path, crops the surrounding **near-white background**, clusters the
remaining colors, and reports each **principal print color** with its **coverage
percentage**. The output is designed to be consumed by another part of a system (for
example, a print/pre-press pipeline).

```json
[
  { "color": "#FF0000", "coverage_percent": 42.5 },
  { "color": "#0000FF", "coverage_percent": 31.2 },
  { "color": "#00FF00", "coverage_percent": 26.3 }
]
```

## Requirements

- **PHP ≥ 8.2** with **`ext-gd`** (developed against 8.4; CI on 8.2 / 8.3 / 8.4 / 8.5).
- Optional **`ext-imagick`** — reserved for CMYK/ICC-aware normalization behind the same
  loader interface.

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

// From a file handle:
$handle = fopen('/path/to/image.jpg', 'rb');
echo $analyzer->analyzeAsJson($handle);
fclose($handle);

// Return the same JSON together with a cropped, lossless PNG:
$result = $analyzer->processPath('/path/to/image.jpg');
echo $result->json;

// Raw bytes can be streamed by an HTTP layer or saved directly:
$pngBytes = $result->croppedImage->bytes;
$result->croppedImage->saveTo('/path/to/cropped.png');
// Pass overwrite: true to replace an existing destination explicitly.
```

`analyze()` accepts an `ImageSource`, a stream resource, a GD image, or **raw image bytes**
(a plain string is treated as bytes, never as a path); use `analyzePath()` /
`analyzePathAsJson()` for filesystem paths. Both return a
`list<array{color: string, coverage_percent: float}>` sorted by coverage descending; the
`*AsJson` variants return the same as pretty JSON. Runnable scripts are in
[`examples/`](examples).

`process()` and `processPath()` run the same pipeline once and return a
`ProcessedImageResult`: the exact legacy JSON string, lossless cropped PNG bytes, output
dimensions, and the source-coordinate crop box. The library does not create files unless
`EncodedImage::saveTo()` is called; parent directories must already exist, and existing
files are protected unless `overwrite: true` is supplied. Explicit replacements are written
to a sibling temporary file and atomically renamed into place.

## How it works

The library is a one-directional pipeline of small components behind stable interfaces:

```
source ─▶ ImageLoader ─▶ Raster ─▶ WhiteBackgroundCropper ─▶ Raster
                                                              │
                                                              ▼
   ColorCoverage[] ◀─ CoverageCalculator ◀─ ClusterResult ◀─ KMeansClusterer
```

1. **Loader (GD)** decodes PNG/JPEG from a resource, stream, or path (format is sniffed from
   magic bytes, not the extension). Palette and grayscale images are normalized to
   truecolor, and GD's 7-bit alpha is expanded to 0–255. CMYK JPEGs — which GD cannot read
   faithfully — are rejected with a clear `UnsupportedImageException`.
2. **White-background cropper** scans inward from each of the four edges and stops at the
   first row/column carrying real content, returning the smallest rectangle around it.
   "Near-white" is judged in CIELAB (`L* ≥ lightnessMin` and chroma `≤ chromaMax`), so
   slightly off-white scans still crop. Because scanning is border-inward, it **never
   removes legitimate white inside the artwork**.
3. **Clusterer** bins the cropped raster into a weighted color histogram (skipping
   transparent pixels), projects each bin to CIELAB, groups them with **k-means++ (seeded,
   deterministic)**, picks `k` automatically via a two-view weighted silhouette, and merges
   near-duplicate / tiny clusters. Candidate groupings must show structure across multiple
   bins, while represented pixel support keeps substantial single-bin accent colors visible.
4. **Coverage calculator** turns cluster weights into percentages using **largest-remainder**
   rounding, so the displayed values sum to exactly `100.0`.
5. **PNG encoder (opt-in)** encodes the same cropped raster returned by the cropper when a
   `process*()` method is used. Legacy `analyze*()` calls do not perform this extra work.

For the full picture, read the [architecture overview](docs/architecture.md).

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

Detailed tuning guidance lives with each stage:
[cropper options](docs/modules/white-background-cropper.md#configuration--tuning) and
[clustering options](docs/modules/color-clustering-and-coverage.md#important-defaults-clusteroptions).

## Output format

Each entry is `{ "color": "#RRGGBB", "coverage_percent": float }`. Colors are uppercase hex;
`analyze()` also exposes the RGB triplet via the `ColorCoverage` DTO. Percentages are rounded
to one decimal and sum to exactly `100.0`. Transparent pixels are excluded from both the
numerator and the denominator, so a fully transparent image yields `[]`.

The `process*()` methods preserve this JSON byte-for-byte in `ProcessedImageResult::$json`
and return the cropped bitmap as canonical PNG regardless of whether the input was PNG or
JPEG. PNG is used to preserve exact crop pixels and alpha. If the cropper finds no removable
border, the complete input raster is encoded and `wasCropped` is `false`.

## Guarantees & limitations

- **Deterministic:** identical input and `seed` produce identical centroids, weights, and
  ordering — no global RNG state is touched.
- **Resolution independent:** clustering runs on the binned histogram, so a multi-megapixel
  scan costs about the same as a thumbnail (covered by a performance regression test).
- **Formats:** 8-bit PNG and JPEG. CMYK JPEG throws `UnsupportedImageException` (use
  Imagick); 16-bit / ICC-aware handling is out of scope for the GD driver.
- **Lossy input:** JPEG compression can introduce faint edge colors; binning and the
  merge/low-coverage passes fold most of them away, but a large, sharp color boundary may
  leave a small (~1%) artifact color.
- **Memory:** the default `GdRaster` reads pixels lazily from GD's native bitmap and represents
  crops as lightweight views, avoiding per-pixel PHP object arrays and crop duplication. The
  bitmap still scales with image dimensions, and the `maxPixels` guard remains the hard limit.
  Calling `process*()` additionally materializes the PNG output; legacy analysis retains its
  existing memory behavior.

## Documentation

The full knowledge base lives in [`docs/`](docs) — start with the
[documentation index](docs/README.md):

- [Architecture overview](docs/architecture.md) and [Glossary](docs/glossary.md)
- [Frozen contracts](docs/contracts.md)
- Module guides: [Image Loading](docs/modules/image-loading.md) ·
  [White Background Cropper](docs/modules/white-background-cropper.md) ·
  [Clustering & Coverage](docs/modules/color-clustering-and-coverage.md)
- Decisions: [ADR-001 color space](docs/ADR-001-color-space.md) ·
  [ADR-002 GD vs Imagick](docs/ADR-002-gd-vs-imagick.md) ·
  [ADR-003 clustering](docs/ADR-003-clustering.md) ·
  [ADR-004 cropped PNG output](docs/ADR-004-cropped-image-output.md)
- [Testing guide](docs/testing.md)

## Development

```bash
composer cs      # coding standards (PSR-12, php-cs-fixer)
composer stan    # static analysis (PHPStan level 8)
composer test    # unit + integration tests (PHPUnit)
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the branching, review, and contract-change
workflow.

## Project layout

```
src/
  Contracts/              # frozen interfaces + DTOs
  Options/                # CropOptions, ClusterOptions, AnalyzerOptions
  Exception/              # typed exception hierarchy
  ImageLoader/            # GD loader, lazy GdRaster, source handling, InMemoryRaster
  ImageEncoder/           # lossless PNG encoding for processed results
  Color/                  # sRGB <-> Lab <-> HSV conversions, ΔE
  WhiteBackgroundCropper/ # near-white border-inward crop
  ColorClusterer/         # histogram + k-means++ + k selection
  CoverageCalculator/     # coverage percentages, largest-remainder
  PublicAPI/              # facade + default factory
tests/  examples/  docs/
```

## License

MIT.
