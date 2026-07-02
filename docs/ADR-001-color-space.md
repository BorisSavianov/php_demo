# ADR-001: Use CIELAB as the analysis color space

## Status
Accepted.

## Context
We must group "similar" colors and detect near-white backgrounds. Candidate
spaces: sRGB, HSV, CIELAB.

## Decision
Perform white detection and clustering in **CIELAB (D65)**. RGB remains the
transport format for input pixels and output hex.

## Rationale
- **sRGB** is perceptually non-uniform: equal Euclidean distances do not match
  equal perceived differences, so k-means groups colors in ways a human/printer
  would disagree with.
- **HSV** is cylindrical (hue wraps) and non-uniform in lightness; Euclidean
  distance is ill-defined.
- **CIELAB** is designed so Euclidean distance ≈ perceived difference (ΔE),
  making "similar colors" meaningful, the near-white threshold intuitive
  (high L*, low chroma), and the result device-independent — appropriate for a
  print-oriented tool.

## Consequences
- Need an sRGB→linear→XYZ→Lab conversion (`Color\ColorConverter`), unit-tested
  against reference values.
- Clustering math runs on Lab triplets; slightly more CPU than raw RGB, absorbed
  by histogram binning.
