# Testing Guide

## Purpose

This guide covers both halves of how the library is verified:

1. the **automated test strategy** — the layers of tests that run in CI and guard the
   library's guarantees, and
2. a **manual real-image walkthrough** — how to run the analyzer against bundled and
   your-own images to sanity-check behavior by eye.

For what the tests are protecting *conceptually*, see the [Architecture overview](architecture.md);
each module guide also lists its own "tests to protect."

## Test strategy

The suite is layered so that each layer catches a different class of regression.

| Layer | Location | What it guards |
|---|---|---|
| **Unit** | `tests/Unit/` | Each component in isolation: loader format/alpha/error paths, `ColorConverter` accuracy against reference values, cropper bounding boxes and edge cases, clusterer determinism and `k` selection, coverage rounding. |
| **Integration** | `tests/Integration/` | The whole pipeline through `ImageColorAnalyzer::analyze()` on synthetic and real images, asserting the documented JSON shape and that percentages sum to `100.0`. |
| **Real-image** | `tests/Integration/WhiteBackgroundCropperRealImageTest.php` + fixtures | Behavior on genuinely decoded pixels (true alpha, real JPEG anti-aliasing), not just in-memory rasters. |
| **Performance regression** | `tests/Unit/ColorClusterer/ClusteringPerformanceTest.php` | Resolution independence — that clustering cost tracks color diversity, not pixel count. |

Supporting helpers live in `tests/Support/`: `SyntheticImageFactory` builds images with
*exactly known* composition (so coverage can be asserted against ground truth), and the fakes
(`FakeImageLoader`, `PassthroughCropper`) let a stage be tested without its neighbors.

### Running the automated suite

```bash
composer install
composer cs      # coding standards (PSR-12, php-cs-fixer)
composer stan    # static analysis (PHPStan level 8)
composer test    # unit + integration tests (PHPUnit)
```

`composer test -- --testsuite unit` runs only the unit suite (the same subset CI uses for the
Imagick-adapter job). CI runs `cs`, `stan`, and `test` across PHP 8.2, 8.3, 8.4, and 8.5, plus
a separate Imagick job on 8.4. See [`CONTRIBUTING.md`](../CONTRIBUTING.md).

## Manual real-image walkthrough

Use this to eyeball the analyzer on real files.

### 1. Install dependencies

```bash
composer install
```

### 2. Verify the GD extension is enabled

```bash
php -m | grep -i gd        # macOS / Linux
php -m | findstr /i gd     # Windows
```

### 3. Run against the bundled real-image fixtures

```bash
php examples/analyze_from_path.php tests/Fixtures/real/sample.png
php examples/analyze_from_handle.php tests/Fixtures/real/sample.jpg
```

### 4. Try the edge-case fixtures (loader / cropper paths)

```bash
php examples/analyze_from_path.php tests/Fixtures/real/sample_transparent.png
php examples/analyze_from_path.php tests/Fixtures/real/transparent_border.png
php examples/analyze_from_path.php tests/Fixtures/real/logo_white_border.png
php examples/analyze_from_path.php tests/Fixtures/real/scan_offwhite_border.jpg
```

These fixtures and their expected results are catalogued in
[`tests/Fixtures/README.md`](../tests/Fixtures/README.md) and
[`tests/Fixtures/real/README.md`](../tests/Fixtures/real/README.md).

### 5. Test with your own image (path API)

```bash
php examples/analyze_from_path.php /path/to/your/image.jpg
```

### 6. Test the file-handle API path

```bash
php examples/analyze_from_handle.php /path/to/your/image.png
```

### 7. Sanity-check the output

Confirm the JSON lists plausible colors, that the `coverage_percent` values sum to `100`, and
that **no PHP warnings or errors** are printed to stderr.

### 8. Test malformed / non-image input (error handling)

Pass any non-image file and confirm it raises a clean `InvalidImageException` rather than a
PHP warning or fatal — proof that the [error handling](architecture.md#error-handling)
contract holds.

## Example run

```bash
php examples/analyze_from_path.php tests/Fixtures/real/colorful.jpeg
```

Output:

```json
[
    { "color": "#3671AB", "coverage_percent": 24.8 },
    { "color": "#BF5E51", "coverage_percent": 19.1 },
    { "color": "#EEF1F2", "coverage_percent": 15.0 },
    { "color": "#343230", "coverage_percent": 10.3 },
    { "color": "#7C9D58", "coverage_percent": 9.4 },
    { "color": "#E8C749", "coverage_percent": 8.6 },
    { "color": "#51815B", "coverage_percent": 7.7 },
    { "color": "#B85C87", "coverage_percent": 5.1 }
]
```

The coverage percentages sum to `100`.

## Related documents

[Architecture](architecture.md) · [White Background Cropper](modules/white-background-cropper.md) ·
[Clustering & Coverage](modules/color-clustering-and-coverage.md) ·
[Fixture inventory](../tests/Fixtures/README.md) · [CONTRIBUTING](../CONTRIBUTING.md)
