# ADR-001: Use CIELAB as the analysis color space

## Status

Accepted.

## Context

The library must do two things that depend on comparing colors: **group similar colors**
(clustering) and **detect the near-white background** (cropping). Both need a notion of
"distance" between colors that matches human perception, because the output is judged by
people and used for print. Three candidate color spaces were considered: sRGB, HSV, and
CIELAB.

## Decision

Perform all analysis — near-white detection and clustering — in **CIELAB (D65)**. sRGB
remains only the *transport* format: the format of input pixels and of output hex colors.

## Rationale

| Space | Why it was (not) chosen |
|---|---|
| **sRGB** | Device space, trivially available, but **perceptually non-uniform**: equal Euclidean distances do not correspond to equal perceived differences, so k-means would group colors in ways a human or a printer operator would disagree with. Rejected for analysis. |
| **HSV** | Separates hue from brightness, but is **cylindrical** (hue wraps at 360°) and still non-uniform in lightness, so Euclidean distance across H/S/V is ill-defined. Awkward for k-means. Rejected. |
| **CIELAB (D65)** | Designed so that **Euclidean distance ≈ perceived difference (ΔE)**. This makes "group similar colors" mean the right thing, makes the near-white threshold intuitive (high `L*`, low chroma `sqrt(a*² + b*²)`), and is device-independent — appropriate for a print-oriented tool. **Selected.** |

## Consequences

- An sRGB → linearized RGB → XYZ (D65) → CIELAB conversion is required, implemented in
  `Color\ColorConverter` and unit-tested against reference values. An HSV conversion and a
  `labToRgb` inverse are provided as well.
- Distance is measured with **ΔE (CIE76)** — plain Euclidean distance in Lab — which keeps
  the clustering hot loop cheap (it can compare *squared* distances and skip the `sqrt`). A
  weighted `CIE94` variant exists for graphic-arts use but is not used by the core pipeline.
- Clustering math runs on Lab triplets, costing slightly more CPU than raw RGB. This is
  absorbed by [histogram binning](ADR-003-clustering.md), which converts each bin to Lab
  only once.

## Related documents

[ADR-003 Clustering](ADR-003-clustering.md) · [White Background Cropper](modules/white-background-cropper.md) ·
[Image Loading & Color Foundations](modules/image-loading.md) · [Glossary](glossary.md)
