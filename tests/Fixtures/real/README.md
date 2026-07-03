# Real image fixtures — White Background Cropper

Bordered sample images used by
[`tests/Integration/WhiteBackgroundCropperRealImageTest.php`](../../Integration/WhiteBackgroundCropperRealImageTest.php)
to prove the [White Background Cropper](../../../docs/modules/white-background-cropper.md)
works on genuinely decoded pixels (true alpha, real JPEG anti-aliasing), not only on
synthetic in-memory rasters.

All three are a **200 × 150** canvas with the same content rectangle drawn with hard edges:

| Fixture | Border | Format | Content box | Notes |
|---|---|---|---|---|
| `logo_white_border.png` | pure white `#FFFFFF` | PNG (lossless) | `(40, 30, 120, 90)` | exact crop expected |
| `transparent_border.png` | fully transparent (alpha 0) | PNG (lossless) | `(40, 30, 120, 90)` | exact crop expected; alpha treated as background |
| `scan_offwhite_border.jpg` | off-white `#FAF9F7` | JPEG q85 (lossy) | `(40, 30, 120, 90)` | compression halos widen the crop by a few px, so the test asserts *contains-content + border-trimmed*, not an exact box (keeps CI robust across libjpeg builds) |

**Content:** a red block `#C82828` (x 40–99) beside a blue block `#2846B4` (x 100–159),
spanning y 30–119.

## Regenerating

Created with `ext-gd` (`imagecreatetruecolor`, `imagefilledrectangle`, `imagepng` /
`imagejpeg`). To reproduce, draw the two content blocks onto the relevant background over a
200 × 150 canvas and write the file:

- **Transparent PNG:** `imagealphablending($img, false)` + `imagesavealpha($img, true)` with
  a fully transparent fill (`imagecolorallocatealpha(..., 127)`).
- **JPEG:** write at quality 85.

For the other real-image fixtures (`sample.*`, `colorful.jpeg`), see
[`../README.md`](../README.md); for how all fixtures are used, see the
[testing guide](../../../docs/testing.md).
