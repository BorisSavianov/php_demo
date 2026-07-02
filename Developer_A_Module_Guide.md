# Developer A Module Guide
## Platform, Image I/O, and Color Foundations

Read time: under 3 minutes.

Developer A owns the foundation layer: contracts, options, exceptions, image loading, color conversion, shared test support, CI/tooling, and the facade skeleton. The key rule is that downstream modules only see stable interfaces and DTOs, never GD internals or format-specific behavior.

### Owned Surface

- `src/Contracts/`: `Raster`, `ImageSource`, stage interfaces, and result DTOs.
- `src/Options/`: `AnalyzerOptions`, `CropOptions`, `ClusterOptions`.
- `src/Exception/`: typed library failures via `ImageAnalyzerException`.
- `src/ImageLoader/`: source resolution, GD decoding, optional Imagick adapter, `InMemoryRaster`.
- `src/Color/ColorConverter.php`: sRGB, XYZ, Lab, HSV, and delta-E helpers.
- `src/PublicAPI/`: `ImageColorAnalyzer` and `AnalyzerFactory` wiring.
- Tooling: Composer scripts, PHPStan, php-cs-fixer, PHPUnit, GitHub Actions.

Contract or option changes are coordination points. Follow `CONTRIBUTING.md`: update docs/ADRs and get all affected owners to review.

### Pipeline Role

```text
source -> SourceResolver -> GdImageLoader -> Raster
       -> WhiteBackgroundCropper -> KMeansClusterer
       -> PercentageCoverageCalculator -> facade JSON
```

The loader normalizes every accepted source to a `Raster` of immutable `ColorRGBA` pixels. Supported default formats are PNG and JPEG. Format is sniffed from bytes, not file extension. Raw string input means image bytes; filesystem reads go through `analyzePath()` or `FileImageSource::fromPath()`.

### Loader Facts To Keep Current

- GD is the default dependency (`ext-gd` is required).
- Imagick is optional and exists for CMYK/ICC-aware decoding paths.
- CMYK JPEGs are rejected by the GD loader with `UnsupportedImageException`.
- Oversized images are rejected before rasterization via the `maxPixels` guard, default `64_000_000`.
- Palette images are normalized to truecolor with alpha preservation.
- GD alpha is converted from 0..127 inverted alpha to library alpha 0..255 with:
  `round((127 - gdAlpha) * 255 / 127)`.
- Current PHP frees GD images by garbage collection; do not document explicit `imagedestroy()` cleanup for `GdImageLoader`.

### Color Math

`ColorConverter` is shared by B and C. Near-white cropping and clustering both rely on Lab because Euclidean distance there roughly tracks perceived difference. C uses squared Lab distance in hot loops for speed; threshold comparisons use actual delta-E.

### Facade And Output

`ImageColorAnalyzer` composes the four stage interfaces. It exposes:

- `analyze($source, ?AnalyzerOptions)` for `ImageSource`, stream resource, raw bytes, or GD image.
- `analyzePath($path, ?AnalyzerOptions)` for files.
- `analyzeAsJson()` and `analyzePathAsJson()` for pretty JSON.

JSON uses `JSON_PRESERVE_ZERO_FRACTION`, so `coverage_percent` remains a float-shaped value such as `50.0`, not `50`.

### Tests And CI

Use Composer scripts:

```bash
composer cs
composer stan
composer test
```

CI currently runs PHP 8.2, 8.3, 8.4, and 8.5 with GD, plus an Imagick adapter job on PHP 8.4. A-owned test areas include contracts, image loader, source resolver, raster behavior, color conversion, and shared fakes/fixtures.

### Review Checklist

- Interfaces remain backward-compatible unless an ADR says otherwise.
- No GD or Imagick object crosses a public contract boundary.
- Invalid bytes throw `InvalidImageException`; valid-but-unsupported inputs throw `UnsupportedImageException`.
- String inputs are not treated as paths.
- `Raster` remains immutable from the consumer perspective.
- README, contracts docs, and ADRs match any changed behavior.

Cross-references: `docs/contracts.md`, `docs/ADR-001-color-space.md`, `docs/ADR-002-gd-vs-imagick.md`, `Developer_A_Plan.md`.
