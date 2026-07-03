# Test fixtures

Sample images used by the tests in [`tests/`](..) and the scripts in [`examples/`](../../examples).
For how these are exercised, see the [testing guide](../../docs/testing.md).

Fixtures are split into two directories:

- **[`real/`](real)** — committed image files with known, expected output.
- **`generated/`** — a placeholder for fixtures materialized in memory at test time.

## `real/`

Synthetic-but-realistic sample images used by `examples/` and as the project's validation
images. They are generated (not photographed) so the expected output is known, and they
deliberately use a **slightly off-white** background (`#FCFBF9`) to mimic a scan rather than a
pure `#FFFFFF` canvas.

| File | Content | Expected principal colors |
|------|---------|---------------------------|
| `sample.png` | 40 px near-white margin, then three vertical bands (50% red, 30% blue, 20% green) | `#C81E28` ~50%, `#1E46B4` ~30%, `#28A046` ~20% |
| `sample.jpg` | Same composition, JPEG (quality 95) | Same three colors; JPEG may add a small (~1%) near-white edge color |
| `sample_transparent.png` | A magenta disk on a fully transparent background | `#BE1E8C` 100% (transparent pixels ignored) |
| `colorful.jpeg` | A multi-color photograph-style image | Eight principal colors summing to `100.0` (see the [example run](../../docs/testing.md#example-run)) |
| `weighted-single-bin-accent.png` | A 16×16 icon whose black accent compresses to one 13-pixel histogram bin | Blue `75.3%`, yellow `18.3%`, black `6.4%` |

The PNG bands are pure colors, so cropping + clustering reproduce the 50/30/20 split exactly.
The JPEG variant demonstrates robustness to compression artifacts.

The weighted-single-bin fixture is the end-to-end regression for automatic `k` selection
through both path and file-handle APIs. The bordered fixtures used specifically by the
cropper's real-image integration test (`logo_white_border.png`, `transparent_border.png`,
`scan_offwhite_border.jpg`) are documented separately in [`real/README.md`](real/README.md).

## `generated/`

Reserved for fixtures materialized on the fly by `tests/Support/SyntheticImageFactory`. Unit
tests build these in memory rather than committing binaries, which keeps the repository small
and the ground-truth composition exact.
