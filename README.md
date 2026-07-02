# image-color-analyzer

A reusable PHP library that loads a PNG or JPEG image from a file handle, stream,
or path, crops the surrounding near-white background, clusters the remaining
colors, and reports each principal print color with its coverage percentage.

> **Status: scaffold.** The frozen contracts, foundation (raster, color
> conversion, source handling), test harness, and CI are in place. The four
> algorithmic modules are stubbed and owned per developer — see below.

## Requirements
- PHP >= 8.2 with `ext-gd` (8.4+ recommended)
- Optional `ext-imagick` (enables the Imagick loader for CMYK/large images)

## Install
```
composer install
```

## Usage
```php
use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

$analyzer = AnalyzerFactory::createDefault();

// From a path:
$colors = $analyzer->analyze('/path/to/image.png');

// From a file handle (as the assignment requires):
$handle = fopen('/path/to/image.jpg', 'rb');
echo $analyzer->analyzeAsJson($handle);
fclose($handle);
```

Example output:
```json
[
  { "color": "#FF0000", "coverage_percent": 42.5 },
  { "color": "#0000FF", "coverage_percent": 31.2 },
  { "color": "#00FF00", "coverage_percent": 26.3 }
]
```

Tune behavior via `AnalyzerOptions` (crop tolerance, cluster count, seed, etc.).

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
$colors = $analyzer->analyze('/path/to/scan.jpg', $opts);
```

## Project layout
```
src/
  Contracts/              # frozen interfaces + DTOs (Dev A)
  Options/                # CropOptions, ClusterOptions, AnalyzerOptions (Dev A)
  Exception/              # exception hierarchy (Dev A)
  ImageLoader/            # GD loader, source handling, InMemoryRaster (Dev A)
  Color/                  # sRGB<->Lab<->HSV conversions (Dev A)
  WhiteBackgroundCropper/ # near-white border-inward crop (Dev B)
  ColorClusterer/         # histogram + k-means++ + k selection (Dev C)
  CoverageCalculator/     # coverage percentages, largest-remainder (Dev C)
  PublicAPI/              # facade + default factory (joint)
tests/  examples/  docs/
```

## Ownership
- **Developer A** — contracts, options, exceptions, image loading, color space, test support.
- **Developer B** — `WhiteBackgroundCropper` (implement `crop()`).
- **Developer C** — `ColorClusterer` + `CoverageCalculator` + examples/docs.

Each stub throws `NotImplementedException` and has a `TODO(owner)` with the intended
algorithm; matching tests are marked incomplete. See `docs/` for the frozen
contracts and the architecture decision records, and `CONTRIBUTING.md` for the
Git workflow.

## Development
```
composer cs && composer stan && composer test
```
