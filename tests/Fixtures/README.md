# Test fixtures

## `real/`
Synthetic-but-realistic sample images used by `examples/` and as the
assignment's validation images. They are generated (not photographed) so the
expected output is known, and deliberately use a **slightly off-white**
background (`#FCFBF9`) to mimic a scan rather than a pure `#FFFFFF` canvas.

| File | Content | Expected principal colors |
|------|---------|---------------------------|
| `sample.png` | 40px near-white margin, then three vertical bands (50% red, 30% blue, 20% green) | `#C81E28` ~50%, `#1E46B4` ~30%, `#28A046` ~20% |
| `sample.jpg` | Same composition, JPEG (quality 95) | Same three colors; JPEG may add a small (~1%) near-white edge color |
| `sample_transparent.png` | A magenta disk on a fully transparent background | `#BE1E8C` 100% (transparent pixels ignored) |

The PNG bands are pure colors, so cropping + clustering reproduce the 50/30/20
split exactly. The JPEG variant demonstrates robustness to compression
artifacts.

## `generated/`
Reserved for fixtures materialized on the fly by
`tests/Support/SyntheticImageFactory`; unit tests build these in memory rather
than committing binaries.
