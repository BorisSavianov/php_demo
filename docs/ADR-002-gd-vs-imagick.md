# ADR-002: GD as the default image driver, Imagick as an optional adapter

## Status

Accepted.

## Context

The library must decode PNG and JPEG images and read their pixels. PHP offers two image
extensions for this: **`ext-gd`**, which is bundled with virtually every PHP installation,
and **`ext-imagick`**, a PECL extension that binds to the separately installed ImageMagick
system library.

## Decision

Ship **GD as the default loader** behind `ImageLoaderInterface`, and provide an optional
`ImagickImageLoader` for CMYK/ICC-aware or very large images. Callers get the GD-backed
analyzer from `AnalyzerFactory::createDefault()`; nothing downstream knows which driver
produced the [`Raster`](contracts.md).

## Rationale

| Dimension | GD | Imagick |
|---|---|---|
| Availability | Bundled/enabled on nearly every PHP install | Separate PECL extension + ImageMagick system library |
| 8-bit PNG/JPEG decode | Fully sufficient | Fully sufficient |
| Per-pixel RGBA access | Native | Native |
| Advanced formats (CMYK, ICC, 16-bit, TIFF) | Not supported | Supported |
| Memory model | Full bitmap in PHP memory | Can stream / memory-map very large images |
| Security / ops surface | Small | Larger CVE history; needs `policy.xml` tuning |
| CI simplicity | Trivial | Requires a system package install |

GD covers everything the core use case needs — 8-bit PNG/JPEG decode, alpha, and pixel
access — is available out of the box, keeps CI trivial, and has a far smaller security and
operations footprint than ImageMagick. Because loading sits behind an interface, Imagick can
slot in with **zero downstream changes** whenever advanced formats are genuinely required.

## Consequences

- **CMYK JPEG is a known GD limitation.** GD decodes CMYK JPEGs unreliably, so `GdImageLoader`
  detects the 4-channel case and either routes to the Imagick adapter (if present) or raises
  `UnsupportedImageException` with clear guidance. This is a documented, intentional
  limitation rather than a silent wrong result.
- **Very large images** are handled in the default path by [histogram binning](ADR-003-clustering.md)
  and the `maxPixels` guard (rejecting oversized inputs before allocation), rather than by
  Imagick's streaming. Downscale enormous images before analysis if needed.
- CI runs the GD path across PHP 8.2–8.5 and exercises the Imagick adapter in a separate job
  on PHP 8.4, so both drivers stay verified.

## Related documents

[Image Loading & Color Foundations](modules/image-loading.md) · [Architecture](architecture.md) ·
[Frozen contracts](contracts.md) · [Glossary](glossary.md)
