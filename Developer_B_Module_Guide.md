# Developer B Module Guide
## White Background Cropper

Read time: under 3 minutes.

Developer B owns `src/WhiteBackgroundCropper/WhiteBackgroundCropper.php` and its unit/integration coverage. This stage trims the near-white or transparent border around real artwork before clustering, so coverage percentages use the artwork as the denominator instead of the whole scanned page.

### Pipeline Role

```text
Raster from loader -> WhiteBackgroundCropper -> CropResult.raster -> clusterer
```

The cropper depends on A's `ColorConverter` and `CropOptions`. It does not call C directly; the facade unwraps `CropResult->raster` and sends it to the clusterer.

### Core Algorithm

The algorithm is a border-inward crop. It never removes arbitrary white pixels from the middle of the image. Instead, it finds the smallest axis-aligned rectangle that contains all non-background pixels.

One row-major pass over `Raster::pixels()` builds:

- `rowContent[y]` and `colContent[x]`: content-pixel counts per row/column.
- raw min/max content extents: used when content is too small for the noise guard.
- a per-call memo of background decisions by packed RGB.

Background means:

```text
alpha < alphaThreshold
OR
L* >= lightnessMin AND sqrt(a*^2 + b*^2) <= chromaMax
```

The Lab predicate matters because scanner casts, JPEG halos, and anti-aliasing often produce off-white values that are not exactly `#FFFFFF`.

### Noise Guard And Fallback

`lineContentFraction` sets a per-row/per-column content floor. A lone dust pixel in the margin should not stop a crop, so an edge only counts once the line has enough content.

The guard is not allowed to erase real tiny content. If no row or column clears the floor but content exists, the cropper falls back to the raw min/max extent. This preserves single pixels and hairlines.

### `CropResult` Semantics

- All-white or all-transparent input returns the original raster and `wasCropped=false`.
- If content already touches every edge, the original raster is returned and `wasCropped=false`.
- A real trim returns `image->crop($box)` and `wasCropped=true`.
- `BoundingBox` coordinates always refer to the original image.

### Tuning

Defaults live in `CropOptions`:

- `lightnessMin=95.0`: lower it to treat dimmer paper as background.
- `chromaMax=5.0`: raise it for yellowed/off-white scans; lower it for clean exports.
- `lineContentFraction=0.002`: raise it to ignore more speckle; lower it for very fine content.
- `alphaThreshold=8`: raise it to treat faint transparent pixels as background.

Avoid adding an RGB fast path such as "all channels >= 245". Some tinted near-whites pass that cube while exceeding the default Lab chroma threshold, which would silently over-crop.

### Performance And Safety

Time is `O(width * height)`. Extra memory is `O(width + height)` plus a memo capped at 65,536 colors. The memo is reset at the start of every `crop()` call, so a factory-built analyzer is safe for serial reuse. Do not share the same cropper instance across concurrent calls in a long-running multithreaded host because the memo is mutable during a call.

### Tests To Protect

The important unit tests cover symmetric/asymmetric borders, interior white preservation, near-white tolerance, genuine gray content, all-white/all-transparent inputs, no-margin inputs, transparent margins, sparse noise, and raw-extent fallback. The two load-bearing tests are:

- `testKeepsInteriorWhite`
- `testRawExtentFallbackRescuesContentBelowNoiseFloor`

Real-image integration coverage checks PNG/JPEG fixtures decoded through the GD loader.

Cross-references: `Developer_B_Plan.md`, `docs/ADR-001-color-space.md`, `docs/contracts.md`, `README.md`.
