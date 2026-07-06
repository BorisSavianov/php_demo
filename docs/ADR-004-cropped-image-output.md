# ADR-004: Additive processed result with canonical cropped PNG output

## Status

Accepted.

## Context

The pipeline already produces a cropped `Raster` before color clustering, but the public
facade discarded the `CropResult` after producing its JSON color list. Callers now need both
artifacts from one processing pass. The existing `analyze*()` methods and JSON-array schema
are released public contracts, and the library has no HTTP or persistent-storage layer.

The output therefore needs to be transportable without coupling the library to URLs,
temporary-file lifecycle, or application-specific storage.

## Decision

Add `process()` and `processPath()` beside the existing facade methods. They return a
`ProcessedImageResult` containing:

- the exact JSON string produced by the existing serializer;
- an `EncodedImage` containing canonical PNG bytes, dimensions, format, and media type; and
- the source-coordinate crop box and `wasCropped` flag.

The facade retains the existing `CropResult` and reuses its raster for both clustering and
PNG encoding. `PngEncoderInterface` keeps encoding injectable. The default `GdPngEncoder`
uses a native copy for `GdRaster` crop views and a pixel-copy fallback for custom `Raster`
implementations.

PNG is always returned, including for JPEG inputs, because it is lossless and preserves
alpha. The library returns bytes by default. `EncodedImage::saveTo()` is an explicit
convenience: it requires an existing parent directory, refuses to overwrite by default, and
only replaces a destination when `overwrite: true` is passed. Replacements are written to a
sibling temporary file and atomically renamed into place so a failed write leaves the prior
destination intact.

## Alternatives considered

- **Replace the existing JSON array with an envelope containing base64.** Rejected because
  it breaks v1 consumers and increases payload size.
- **Return a temporary-file path.** Rejected because it introduces filesystem permissions,
  cleanup, and ownership semantics into a pure library.
- **Expose `Raster` directly.** Rejected because callers would still need a driver-specific
  encoding step and the value is not directly transportable.
- **Make an option change the return type of `analyze*()`.** Rejected because union return
  types make the API harder to consume and reason about.

## Consequences

- Existing methods, return types, and JSON shapes remain unchanged.
- Only `process*()` pays the additional encoding and output-memory cost.
- Consumers can stream `EncodedImage::$bytes`, call `saveTo()`, or hand the bytes to their
  own storage layer.
- Encoding and saving failures are typed as `ImageEncodingException` and
  `ImageSaveException`, both under the existing `ImageAnalyzerException` marker.

## Related documents

[Architecture](architecture.md) · [Frozen contracts](contracts.md) ·
[Image Loading & Color Foundations](modules/image-loading.md) · [README](../README.md)
