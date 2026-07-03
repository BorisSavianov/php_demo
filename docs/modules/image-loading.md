# Image Loading & Color Foundations

> **Module scope:** contracts, options, exceptions, image decoding, color conversion, the
> public facade wiring, and project tooling.
> **Originally owned by:** Developer A. *(Ownership is historical; the frozen interfaces are
> the real boundary.)*

## Purpose

This is the foundation layer every other module stands on. It defines the stable interfaces
and DTOs that decouple the pipeline, turns any supported input into a normalized
[`Raster`](../contracts.md), and provides the color math (`ColorConverter`) that both the
cropper and the clusterer depend on. The guiding rule: **downstream code sees only stable
interfaces and immutable values — never GD internals or format-specific behavior.**

## Overview

The layer covers five concerns:

- **Contracts & options** (`src/Contracts`, `src/Options`) — the frozen seams described in
  [contracts.md](../contracts.md).
- **Exceptions** (`src/Exception`) — a typed hierarchy under the `ImageAnalyzerException`
  marker interface.
- **Source resolution & loading** (`src/ImageLoader`) — `SourceResolver`, `FileImageSource`,
  `GdImageLoader`, the optional `ImagickImageLoader`, and `InMemoryRaster`.
- **Color conversion** (`src/Color/ColorConverter.php`) — sRGB ↔ XYZ ↔ CIELAB ↔ HSV and ΔE.
- **Facade & factory** (`src/PublicAPI`) — `ImageColorAnalyzer` and `AnalyzerFactory`.

## Pipeline role

```text
source ─▶ SourceResolver ─▶ GdImageLoader ─▶ Raster ─▶ (cropper ─▶ clusterer ─▶ coverage) ─▶ facade JSON
```

The loader normalizes every accepted source into a `Raster` of immutable `ColorRGBA`
pixels. From that point on, no other module knows or cares which decoder produced it.

## Core components

### Source resolution

`SourceResolver` accepts and normalizes each input kind:

- a **filesystem path** (only via `analyzePath()` / `resolvePath()`),
- a **stream resource** from `fopen()` or a `php://` wrapper,
- **raw image bytes** (a plain string is always bytes, **never** a path),
- a **GD image** resource,
- an already-built `ImageSource`.

Format (PNG vs JPEG) is detected from **magic bytes**, not the file extension, so a
mislabeled `.png` that is really a JPEG still loads correctly.

### Loading and normalization (`GdImageLoader`)

`GdImageLoader::load()` decodes the bytes and hands back an `InMemoryRaster`. Key behaviors
to keep accurate when modifying it:

- **GD is the required default decoder** (`ext-gd`); Imagick is an optional adapter behind
  the same interface (see [ADR-002](../ADR-002-gd-vs-imagick.md)).
- **Palette and grayscale images are normalized to truecolor** with an explicit alpha
  channel before the raster is built.
- **Alpha is expanded from GD's inverted 7-bit scale to 0–255** with
  `round((127 - gdAlpha) * 255 / 127)` — so GD's `0 = opaque … 127 = transparent` becomes
  the library's `255 = opaque … 0 = transparent`.
- **CMYK JPEGs are rejected** with `UnsupportedImageException`: GD cannot decode them
  reliably, so the loader detects the 4-channel case (via `getimagesizefromstring`) and
  refuses rather than returning wrong colors.
- **Oversized images are rejected before rasterization** by the `maxPixels` guard (default
  `64_000_000`), which raises `UnsupportedImageException` *before* any per-pixel array is
  allocated — the memory-safety backstop.
- **No explicit `imagedestroy()` is needed.** Since PHP 8.0, GD images are freed by the
  garbage collector; `imagedestroy()` is a deprecated no-op in 8.5. Do not reintroduce it.

### The `Raster` and its storage

`Raster` is an **interface** (`width()`, `height()`, `hasAlpha()`, `pixelAt()`, `pixels()`,
`crop()`); storage is an implementation detail. The default `InMemoryRaster` stores one
`ColorRGBA` per pixel in a row-major list — simple, immutable, and ideal for tests and
typical images, but **memory-heavy for very large photos**. If a future use case needs to
process huge images without downscaling, a leaner `Raster` (for example, a packed-integer
buffer) can be introduced behind the same interface without touching any other module.

### Color conversion (`ColorConverter`)

`ColorConverter` is the shared math used by both the cropper and the clusterer. It is pure
and deterministic, and it operates in **CIELAB (D65)** because Euclidean distance there
approximates perceived difference (see [ADR-001](../ADR-001-color-space.md)).

- **Conversion path:** sRGB (0–255) → linearized RGB → XYZ (D65) → CIELAB. HSV conversions
  and a `labToRgb` inverse are also provided.
- **Distance:** `deltaE()` implements `CIE76` (plain Euclidean distance in Lab); a weighted
  `deltaE94()` is available for graphic-arts use.
- **Performance note:** the clusterer uses *squared* Lab distance in its hot loops (no
  `sqrt`) because that preserves nearest-centroid ordering; threshold comparisons that need
  a real ΔE value call `deltaE()`.

### Facade and output (`ImageColorAnalyzer`, `AnalyzerFactory`)

`ImageColorAnalyzer` composes the four stage interfaces via constructor injection and
exposes:

- `analyze($source, ?AnalyzerOptions)` — for an `ImageSource`, stream resource, raw bytes,
  or GD image.
- `analyzePath($path, ?AnalyzerOptions)` — for filesystem paths.
- `analyzeAsJson()` / `analyzePathAsJson()` — the same results as pretty JSON.

`AnalyzerFactory::createDefault()` returns a fully wired, GD-backed analyzer — the
recommended way to obtain one. JSON encoding uses `JSON_PRESERVE_ZERO_FRACTION`, so
`coverage_percent` stays float-shaped (`50.0`, not `50`).

## Error handling

Failures are typed and all implement `ImageAnalyzerException`:

- **Invalid input** (undecodable bytes, unreadable metadata) → `InvalidImageException`.
- **Valid but unsupported** input (CMYK JPEG, non-PNG/JPEG, over `maxPixels`) →
  `UnsupportedImageException`.

GD's native warnings are suppressed during decoding and translated into these exceptions, so
callers get clean, catchable errors instead of PHP warnings.

## Configuration

This layer has no options of its own beyond the constructor argument `GdImageLoader($maxPixels)`.
It carries `CropOptions` and `ClusterOptions` (bundled in `AnalyzerOptions`) through the
facade to the stages that own them.

## Performance & security considerations

- **Memory** is the dominant cost, driven by `InMemoryRaster`. The `maxPixels` guard caps
  it; downscale enormous images before analysis.
- **Attack surface** is deliberately small: GD is bundled and has a far smaller CVE history
  than ImageMagick, one reason it is the default (see [ADR-002](../ADR-002-gd-vs-imagick.md)).
- **String inputs are never treated as paths**, which avoids a class of accidental
  file-access bugs when handling untrusted input.

## Tests to protect

Run the standard checks:

```bash
composer cs      # coding standards (PSR-12)
composer stan    # static analysis (PHPStan level 8)
composer test    # unit + integration tests
```

CI runs PHP 8.2, 8.3, 8.4, and 8.5 with GD, plus a separate Imagick-adapter job on 8.4. The
tests owned by this layer cover the contracts and DTOs, source resolution for every input
kind, GD decoding and error paths, palette/alpha normalization, raster behavior and
immutability, and `ColorConverter` accuracy against reference values.

## Review checklist

- Interfaces stay backward-compatible unless an [ADR](../contracts.md) says otherwise.
- No GD or Imagick object ever crosses a public contract boundary.
- Invalid bytes throw `InvalidImageException`; valid-but-unsupported inputs throw
  `UnsupportedImageException`.
- String inputs are treated as bytes, not paths.
- `Raster` remains immutable from the consumer's perspective.
- README, [contracts.md](../contracts.md), and the ADRs still match any changed behavior.

## Related documents

[Architecture](../architecture.md) · [Frozen contracts](../contracts.md) ·
[ADR-001 Color space](../ADR-001-color-space.md) · [ADR-002 GD vs Imagick](../ADR-002-gd-vs-imagick.md) ·
[Glossary](../glossary.md) · [README](../../README.md)
