# Frozen Contracts (v1)

These interfaces and DTOs are the integration seams. They are **frozen**: changing
any signature requires an ADR and sign-off from all three developers (see
`CONTRIBUTING.md`). Build against these, not against each other's implementations.

## Data / value objects (`src/Contracts`)
- `ColorRGBA` — immutable 8-bit RGBA; `isTransparent()`, `toHex()`, `toRgbTriplet()`.
- `BoundingBox` — `x, y, width, height`; `area()`.
- `Raster` (interface) — `width()`, `height()`, `hasAlpha()`, `pixelAt()`, `pixels()`, `crop()`.
- `CropResult` — `raster`, `boundingBox`, `wasCropped`.
- `Cluster` — `centroid` (ColorRGBA), `lab` ([L,a,b]), `weight` (int).
- `ClusterResult` — `clusters` (list<Cluster>), `totalAnalyzedPixels`.
- `ColorCoverage` — `color` ("#RRGGBB"), `rgb`, `coveragePercent`; `toArray()`.
- `ImageFormat` (enum) — `PNG`, `JPEG`.

## Options (`src/Options`)
- `CropOptions` — `lightnessMin`, `chromaMax`, `lineContentFraction`, `alphaThreshold`.
- `ClusterOptions` — `fixedK`, `kMax`, `histogramBitsPerChannel`, `mergeDeltaE`, `minClusterCoverage`, `seed`, `alphaThreshold`.
- `AnalyzerOptions` — `crop`, `cluster`.

## Behavioral interfaces (`src/Contracts`)
- `ImageSource` — `stream(): resource`, `detectedFormat(): ImageFormat`.
- `ImageLoaderInterface` — `supports()`, `load(ImageSource): Raster`.  (Dev A)
- `CropperInterface` — `crop(Raster, CropOptions): CropResult`.  (Dev B)
- `ClustererInterface` — `cluster(Raster, ClusterOptions): ClusterResult`.  (Dev C)
- `CoverageCalculatorInterface` — `calculate(ClusterResult): list<ColorCoverage>`.  (Dev C)

## Data flow
`ImageSource → ImageLoader → Raster → Cropper → Raster → Clusterer → ClusterResult → CoverageCalculator → ColorCoverage[]`

The facade `PublicAPI\ImageColorAnalyzer` sequences these; B and C never call each
other directly.
