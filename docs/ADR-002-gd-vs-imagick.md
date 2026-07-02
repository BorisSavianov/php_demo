# ADR-002: GD as the default image driver, Imagick as an optional adapter

## Status
Accepted.

## Context
The library must decode PNG/JPEG and read pixels. PHP offers ext-gd (bundled)
and ext-imagick (PECL + ImageMagick system library).

## Decision
Ship **GD** as the default loader behind `ImageLoaderInterface`; provide an
optional `ImagickImageLoader` for CMYK/ICC-aware or very large images.

## Rationale
GD covers everything the assignment needs (8-bit PNG/JPEG decode, alpha, pixel
access), is available out of the box, keeps CI trivial, and has a far smaller
security/ops surface than ImageMagick. The interface lets Imagick slot in with
zero downstream changes when advanced formats are required.

## Consequences
- GD reads CMYK JPEGs poorly: the loader detects this and routes to Imagick if
  present, else throws `UnsupportedImageException`.
- Very large images are handled by histogram binning / optional downscale rather
  than Imagick's streaming, in the default path.
