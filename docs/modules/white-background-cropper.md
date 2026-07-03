# White Background Cropper

> **Module scope:** `src/WhiteBackgroundCropper/WhiteBackgroundCropper.php` and its tests.
> **Originally owned by:** Developer B. *(Ownership is historical; the frozen interfaces are
> the real boundary.)*

## Purpose

This stage removes the near-white or transparent border surrounding the real artwork
**before** clustering. Cropping first is what makes coverage percentages meaningful: the
denominator becomes the artwork itself, not the whole scanned page, so a logo centered on a
large white sheet reports the ink colors — not "95% white."

## Overview

The cropper consumes a [`Raster`](../contracts.md) from the loader and a `CropOptions`, and
returns a `CropResult` (a possibly-cropped raster, the content `BoundingBox`, and a
`wasCropped` flag). It depends on `ColorConverter` for CIELAB conversion but never calls the
clusterer directly — the facade unwraps `CropResult->raster` and forwards it.

```text
Raster from loader ─▶ WhiteBackgroundCropper ─▶ CropResult.raster ─▶ clusterer
```

## Core algorithm

The crop is a **border-inward scan**. Rather than deleting white pixels wherever they
appear, it finds the smallest axis-aligned rectangle that contains every non-background
pixel and trims only from the four outer edges toward the center. This structurally
guarantees that **white inside the artwork is never removed** — the single most important
property of the module.

A single row-major pass over `Raster::pixels()` builds:

- `rowContent[y]` and `colContent[x]` — the count of content pixels in each row and column,
- the raw min/max content extent — used as a fallback when the noise guard is too strict,
- a per-call memo of background decisions keyed by packed RGB.

### The background predicate

A pixel is treated as background when it is transparent **or** near-white in CIELAB:

```text
background := alpha < alphaThreshold
           OR ( L* >= lightnessMin AND sqrt(a*² + b*²) <= chromaMax )
```

The CIELAB test matters because scanner color casts, JPEG halos, and anti-aliasing routinely
produce off-white values that are *not* exactly `#FFFFFF`. Judging "whiteness" perceptually
(high lightness, low [chroma](../glossary.md)) handles them; a naive RGB test would not.

## Noise guard and fallback

`lineContentFraction` sets a per-row/per-column content floor. A single dust speck in the
margin should not stop a crop, so an edge is only pulled in to a line once that line's
fraction of content pixels crosses the floor.

The guard is **not allowed to erase genuine small content.** If no row or column clears the
floor yet content pixels exist, the cropper falls back to the raw min/max extent. This
rescues legitimately tiny artwork — a single pixel, a hairline — that the floor would
otherwise discard. The two load-bearing tests below encode exactly this tension.

## `CropResult` semantics

- **All-white or all-transparent input** → the original raster is returned with
  `wasCropped = false`.
- **Content already touching every edge** (no margin) → the original raster is returned with
  `wasCropped = false`.
- **A real trim** → `raster->crop($box)` is returned with `wasCropped = true`.
- **`BoundingBox` coordinates always refer to the original image**, so callers can map the
  result back onto the source.

## Configuration & tuning

Defaults live in `CropOptions`:

| Option | Default | Raise it to… | Lower it to… |
|---|---|---|---|
| `lightnessMin` | `95.0` | trim only very bright borders | accept dimmer off-white/grey paper as background |
| `chromaMax` | `5.0` | tolerate tinted/yellowed scans | trim only truly neutral white (clean exports) |
| `lineContentFraction` | `0.002` | ignore heavier speckle/dust in the margin | react to fainter content |
| `alphaThreshold` | `8` | treat more semi-transparent pixels as background | keep faint pixels as content |

Practical guidance:

- **Clean digital exports** (pure `#FFFFFF` margin): defaults are ideal; drop `chromaMax`
  toward `2–3` to trim only exact white.
- **Scanned or photographed art** (off-white, warm cast, JPEG halos): raise `chromaMax` to
  `~8–10` and, for dim paper, lower `lightnessMin` to `~88–92`.

> **Do not add an RGB fast-path** such as "treat a pixel as white if all channels ≥ 245."
> Some tinted near-whites pass that cube while exceeding the default Lab chroma threshold,
> which would silently over-crop. The CIELAB predicate is the intended behavior.

## Performance & safety

- **Time:** `O(width × height)` — a single pass.
- **Extra memory:** `O(width + height)` for the per-line tallies, plus a background memo
  capped at 65,536 colors (`MEMO_CAP = 1 << 16`). The key is a 24-bit packed RGB, so the map
  is inherently bounded; colors beyond the cap are simply recomputed, still correctly.
- **Reuse:** the memo is reset at the start of every `crop()` call, so a factory-built
  analyzer is safe to reuse serially. Because the memo is mutated during a call, **do not
  share one cropper instance across concurrent calls** in a long-running multithreaded host.
- **Deterministic:** no randomness, no global state.

## Tests to protect

The unit suite covers symmetric and asymmetric borders, interior-white preservation,
near-white tolerance, genuine gray content, all-white and all-transparent inputs, no-margin
inputs, transparent margins, sparse noise, and the raw-extent fallback. Two tests are
load-bearing and must keep passing:

- `testKeepsInteriorWhite` — proves white inside the artwork survives.
- `testRawExtentFallbackRescuesContentBelowNoiseFloor` — proves tiny real content is not
  erased by the noise guard.

Real-image integration coverage (`WhiteBackgroundCropperRealImageTest`) exercises PNG and
JPEG fixtures decoded through the GD loader, so the module is validated on true alpha and
real JPEG anti-aliasing, not only synthetic rasters. See the
[fixture inventory](../../tests/Fixtures/real/README.md) and the [testing guide](../testing.md).

## Related documents

[Architecture](../architecture.md) · [Frozen contracts](../contracts.md) ·
[ADR-001 Color space](../ADR-001-color-space.md) · [Glossary](../glossary.md) ·
[Testing guide](../testing.md) · [README](../../README.md)
