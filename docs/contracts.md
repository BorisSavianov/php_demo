# Frozen Contracts (v1)

## Purpose

These interfaces and DTOs are the **integration seams** of the library — the stable surface
that every stage builds against. They are deliberately **frozen**: changing any signature
requires an [ADR](.) and sign-off from the affected owners (see [`CONTRIBUTING.md`](../CONTRIBUTING.md)).
Build against the contracts here, not against each other's implementations.

Freezing these seams is what allowed the pipeline stages to be developed and tested
independently: with the shapes fixed, each module could rely on fakes and synthetic inputs
long before its neighbors existed. For the wider picture of how these types connect, see the
[Architecture overview](architecture.md); for term definitions, see the [Glossary](glossary.md).

## Data & value objects (`src/Contracts`)

| Type | Shape | Notable members |
|---|---|---|
| `ColorRGBA` | immutable 8-bit RGBA (`r`, `g`, `b`, `a`, each 0–255) | `isTransparent()`, `toHex()` → `#RRGGBB`, `toRgbTriplet()` → `[r,g,b]` |
| `BoundingBox` | `x`, `y`, `width`, `height` (original-image coordinates) | `area()` |
| `Raster` *(interface)* | immutable, row-major pixel grid | `width()`, `height()`, `hasAlpha()`, `pixelAt()`, `pixels()`, `crop()` |
| `CropResult` | cropper output | `raster`, `boundingBox`, `wasCropped` |
| `Cluster` | one principal color pre-coverage | `centroid` (`ColorRGBA`), `lab` (`[L,a,b]`), `weight` (int) |
| `ClusterResult` | clusterer output | `clusters` (`list<Cluster>`), `totalAnalyzedPixels` (transparent already excluded) |
| `ColorCoverage` | one result item | `color` (`#RRGGBB`), `rgb`, `coveragePercent`; `toArray()` → `{color, coverage_percent}` |
| `ImageFormat` *(enum)* | detected format | `PNG`, `JPEG` |

## Options (`src/Options`)

| Type | Fields |
|---|---|
| `CropOptions` | `lightnessMin`, `chromaMax`, `lineContentFraction`, `alphaThreshold` |
| `ClusterOptions` | `fixedK`, `kMax`, `histogramBitsPerChannel`, `mergeDeltaE`, `minClusterCoverage`, `seed`, `alphaThreshold` |
| `AnalyzerOptions` | `crop` (`CropOptions`), `cluster` (`ClusterOptions`) |

Default values and tuning guidance are documented in the module guides:
[cropper](modules/white-background-cropper.md) and
[clustering](modules/color-clustering-and-coverage.md).

## Behavioral interfaces (`src/Contracts`)

| Interface | Method | Owner |
|---|---|---|
| `ImageSource` | `stream(): resource`, `detectedFormat(): ImageFormat` | Loading |
| `ImageLoaderInterface` | `supports(ImageSource): bool`, `load(ImageSource): Raster` | Loading |
| `CropperInterface` | `crop(Raster, CropOptions): CropResult` | Cropper |
| `ClustererInterface` | `cluster(Raster, ClusterOptions): ClusterResult` | Clustering |
| `CoverageCalculatorInterface` | `calculate(ClusterResult): list<ColorCoverage>` | Coverage |

## Data flow

```text
ImageSource ─▶ ImageLoader ─▶ Raster ─▶ Cropper ─▶ Raster ─▶ Clusterer ─▶ ClusterResult ─▶ CoverageCalculator ─▶ ColorCoverage[]
```

The `PublicAPI\ImageColorAnalyzer` facade sequences these stages. The cropper and clusterer
never call each other directly; the facade unwraps `CropResult->raster` and passes it on, so
the stages stay fully decoupled.

## Change policy

Any change under `src/Contracts` or `src/Options`:

1. requires an [ADR](.) documenting the reason and the migration,
2. requires review by the affected module owners, and
3. must land together with the documentation updates it implies (README, module guides, and
   any dependent ADR).

## Related documents

[Architecture](architecture.md) · [Glossary](glossary.md) ·
[Image Loading](modules/image-loading.md) · [White Background Cropper](modules/white-background-cropper.md) ·
[Clustering & Coverage](modules/color-clustering-and-coverage.md) · [CONTRIBUTING](../CONTRIBUTING.md)
