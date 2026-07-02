# Implementation Plan — Image Color Analysis Library (PHP)

**Deliverable:** A reusable PHP library that loads a PNG/JPEG image from a file handle/stream/path, crops the surrounding near-white background, clusters the remaining colors, and returns each principal color with its coverage percentage.

**Team:** 3 developers working in parallel (Developer A, B, C).
**Duration:** 4 weeks (Week 4 is hardening/buffer; the library is feature-complete by end of Week 3).
**Prepared for:** internship track — production-quality library, not a one-off script.

---

# Architecture Overview

The library is a pure, dependency-light pipeline organized as a set of small components behind stable interfaces. Data flows in one direction:

```
                        ┌──────────────────────────────────────────────────────────┐
  source (resource /    │                    ImageColorAnalyzer (Public Facade)      │
  stream / path / GD    │                                                            │
  resource / bytes)     │   ImageSource ─▶ ImageLoader ─▶ Raster ─▶ Cropper ─▶ Raster│
        │               │        │            (A)          (A)       (B)         │   │
        ▼               │        └── format detect          │                    ▼   │
   ┌─────────┐          │                                    ▼           PixelExtractor│
   │ Input   │──────────▶                              (cropped Raster)      (C)      │
   └─────────┘          │                                                     │       │
                        │                          weighted color histogram ◀─┘       │
                        │                                    │                         │
                        │                                    ▼                         │
                        │                 ColorClusterer (Lab, k-means++) ─▶ Clusters  │
                        │                              (C)                       │     │
                        │                                                        ▼     │
                        │                          CoverageCalculator ─▶ ColorCoverage[]│
                        │                                (C)                     │     │
                        └────────────────────────────────────────────────────── ▼ ────┘
                                                                        JSON / array result
```

Design principles:

- **Interface-first.** Every component is defined by a PHP interface and communicates through immutable DTOs (`Raster`, `ColorRGBA`, `ClusterResult`, `ColorCoverage`). Components never reach into each other's internals. This is what makes the 3-way parallel split safe: once the contracts are frozen (end of Week 1), each developer builds against interfaces and stubs, not against unfinished code.
- **Driver abstraction for image I/O.** The GD implementation is the default; an Imagick adapter can be dropped in behind the same `ImageLoaderInterface` without touching downstream code.
- **Resolution independence.** Clustering never operates on raw pixels directly. Pixels are reduced to a *weighted color histogram* (binned colors + counts) first, so a 50 MP scan and a 500 px thumbnail cost roughly the same to cluster and produce comparable results.
- **Deterministic.** Seeded k-means++ initialization and deterministic tie-breaking make output stable across runs — essential for meaningful tests.
- **Pure library.** No global state, no `echo`, no `die`, no CLI assumptions. Everything is injectable and unit-testable.

---

# Technology Decisions

### PHP version
- **Minimum: PHP 8.3.** Require `"php": ">=8.3"` in `composer.json`.
- **Develop against 8.4; CI matrix: 8.3, 8.4, 8.5.**

Rationale: as of mid-2026, PHP 8.5.7 is the current stable release and 8.4 is the conservative production default; 8.2 has effectively reached the end of its active life. 8.3 is a safe floor that is universally available on modern hosts while still giving us typed class constants, `json_validate()`, readonly-friendly features, and `#[\Override]`. We use readonly DTOs, enums, first-class callable syntax, and constructor promotion throughout.

### Dependencies
Keep the runtime dependency surface at essentially zero — this is a library others embed.

- **Runtime:** `ext-gd` (required), `ext-imagick` (suggested, optional adapter). No third-party runtime packages; the math (k-means, color conversion) is small and worth owning for determinism and zero supply-chain risk.
- **Dev:** `phpunit/phpunit` (^11), `phpstan/phpstan` (level 8) + `phpstan/extension-installer`, `friendsofphp/php-cs-fixer` (PSR-12), optionally `infection/infection` for mutation testing on the clustering math.

### Image library: GD vs Imagick — **choose GD (default), Imagick as optional adapter**

| Dimension | GD | Imagick |
|---|---|---|
| Availability | Bundled/enabled on nearly every PHP install | Separate PECL ext + ImageMagick system lib |
| PNG/JPEG raster read | Fully sufficient | Fully sufficient |
| Per-pixel RGBA access | `imagecolorat()` + bit-shift alpha | `getImagePixelColor()` / pixel iterator |
| Memory model | Truecolor bitmap in PHP memory | Can memory-map / stream very large images |
| Advanced needs (CMYK, ICC, 16-bit, TIFF) | Not supported | Supported |
| Resampling quality | Good enough (`imagescale`) | Better |
| Security / ops surface | Small | Larger CVE history (ImageMagick), policy.xml tuning |
| CI simplicity | Trivial | Needs system package install |

**Decision:** The assignment requires reading 8-bit PNG/JPEG, detecting near-white borders, cropping, and sampling pixels. GD does all of this natively, is present out-of-the-box, keeps CI simple, and has a far smaller operational/security footprint. We therefore make **GD the default driver** and hide it behind `ImageLoaderInterface`. Because the loader is an interface, we ship an **optional `ImagickImageLoader`** for users who need CMYK/ICC-aware or very-large-image handling — no downstream code changes. The one GD limitation we call out explicitly is CMYK JPEGs (GD reads them poorly); the loader detects this and either routes to Imagick if available or throws a clear `UnsupportedImageException`.

### Color space: **CIELAB (L*a*b*) for both white detection and clustering**

We evaluated three:

- **RGB (sRGB):** device space, trivially available, but perceptually *non-uniform* — equal Euclidean distances do not correspond to equal perceived color differences, so k-means groups colors in a way that disagrees with how a human (or a printer operator) would group them. Rejected for clustering distance.
- **HSV:** decouples hue from brightness, better than RGB for some tasks, but it is cylindrical (hue wraps at 360°), still non-uniform in lightness, and Euclidean distance across H/S/V is ill-defined. Awkward for k-means. Rejected.
- **CIELAB (D65):** designed so that Euclidean distance ≈ perceived difference (ΔE). This makes "group similar colors" mean the right thing, makes the near-white threshold intuitive (`L*` high and chroma √(a*²+b*²) low), and is device-independent — appropriate for a print-oriented tool. **Selected.**

Conversion path we implement: sRGB (0–255) → linearized sRGB → XYZ (D65) → L*a*b*. All done in `ColorConverter` with unit tests against known reference values. RGB stays the *transport* format (input pixels, output hex); Lab is the *analysis* space. We optionally map final centroids back to nearest named/print color for reporting, but coverage math is done in Lab-driven clusters.

### Clustering algorithm: **k-means (Lloyd) with k-means++ init, on a weighted histogram, with automatic k**

- **Reduce first:** bin pixels into a coarse RGB histogram (e.g. 5 bits/channel → ≤ 32³ bins), each bin carrying a pixel count. Run clustering on the *unique binned colors weighted by count*, not on raw pixels. This makes cost depend on color diversity, not image size (resolution independence + big speedup), and naturally smooths compression/anti-aliasing noise.
- **k-means++ initialization** for well-separated, reproducible seeds (seeded RNG for determinism). Lloyd iterations in Lab space until centroids stabilize or max-iters reached.
- **Automatic number of clusters:** search `k = 2..Kmax` (Kmax default 8). Select `k` by **silhouette score** computed on the weighted set (cheap because it runs on binned colors, not raw pixels); the elbow/WCSS curve is computed too and logged for diagnostics. After selection, **merge** any clusters whose centroids are within a ΔE threshold or whose coverage is below a floor (e.g. < 1–2%) into the nearest neighbor, so anti-aliasing halos don't show up as "principal" colors.

Alternatives considered and why not the default: **median-cut / octree quantization** (fast, deterministic, but purely count-driven and not perceptually merged — good as a fallback and as the *initial binning* step, not the final grouping); **DBSCAN / mean-shift** (no k needed, but density-parameter sensitive and slower). We justify k-means++ + silhouette because it directly optimizes "few representative colors that minimize perceptual spread," matches the assignment's explicit suggestion, and — critically for grading — is deterministic and testable.

### Coverage math
Coverage% for a cluster = (sum of pixel counts assigned to that cluster) ÷ (total analyzed pixels) × 100, where transparent pixels (alpha below a threshold) are excluded from *both* numerator and denominator. Percentages are normalized and rounded with the **largest-remainder method** so the displayed values sum to exactly 100.0.

---

# Repository Structure

Packaged as a Composer library (`vendor/image-color-analyzer`), PSR-4 autoloaded, PSR-12 formatted. Directory ownership is disjoint by design so the three developers rarely touch the same files.

```
image-color-analyzer/
├── composer.json                 # (A) name, PSR-4, php>=8.3, ext-gd, dev deps
├── phpunit.xml.dist              # (A)
├── phpstan.neon.dist             # (A) level 8
├── .php-cs-fixer.dist.php        # (A) PSR-12
├── .github/
│   └── workflows/ci.yml          # (A) matrix 8.3/8.4/8.5 + imagick job
├── CODEOWNERS                    # (A) maps dirs -> reviewers
├── README.md                     # (all) usage; C owns final assembly
├── src/
│   ├── Contracts/                # (A) OWNER — all interfaces + DTOs (FROZEN wk1)
│   │   ├── ImageSource.php
│   │   ├── ImageLoaderInterface.php
│   │   ├── CropperInterface.php
│   │   ├── ClustererInterface.php
│   │   ├── CoverageCalculatorInterface.php
│   │   ├── Raster.php            # immutable pixel buffer DTO
│   │   ├── ColorRGBA.php         # value object
│   │   ├── ClusterResult.php
│   │   └── ColorCoverage.php     # result item DTO
│   ├── ImageLoader/              # (A)
│   │   ├── GdImageLoader.php
│   │   ├── ImagickImageLoader.php
│   │   └── SourceResolver.php    # resource/stream/path/bytes -> normalized input
│   ├── Color/                    # (A)
│   │   └── ColorConverter.php    # RGB<->Lab<->HSV, ΔE
│   ├── WhiteBackgroundCropper/   # (B)
│   │   ├── WhiteBackgroundCropper.php
│   │   └── CropOptions.php
│   ├── ColorClusterer/           # (C)
│   │   ├── KMeansClusterer.php
│   │   ├── ColorHistogram.php
│   │   ├── KSelector.php         # silhouette/elbow
│   │   └── ClusterOptions.php
│   ├── CoverageCalculator/       # (C)
│   │   └── PercentageCoverageCalculator.php
│   └── PublicAPI/                # (A skeleton wk1; wired jointly wk3)
│       ├── ImageColorAnalyzer.php   # facade: analyze()
│       └── AnalyzerOptions.php
├── tests/
│   ├── Unit/
│   │   ├── ImageLoader/          # (A)
│   │   ├── Color/                # (A)
│   │   ├── WhiteBackgroundCropper/  # (B)
│   │   ├── ColorClusterer/       # (C)
│   │   └── CoverageCalculator/   # (C)
│   ├── Integration/              # (all) end-to-end through facade
│   ├── Fixtures/
│   │   ├── generated/            # synthetic images w/ known proportions
│   │   └── real/                 # sample PNG/JPEG scans
│   └── Support/
│       ├── SyntheticImageFactory.php  # (A) builds fixtures w/ exact color %
│       └── Fakes/                # (A) FakeLoader/FakeCropper stubs for parallel work
├── examples/
│   ├── analyze_from_path.php     # (C)
│   └── analyze_from_handle.php   # (C)
└── docs/
    ├── ADR-001-color-space.md    # (A/C)
    ├── ADR-002-gd-vs-imagick.md  # (A)
    ├── ADR-003-clustering.md     # (C)
    └── contracts.md              # (A) the frozen interface spec
```

---

# Team Responsibilities

Each developer owns whole directories, their own tests, and their own fixtures. Cross-cutting artifacts (contracts, CI, facade skeleton) are front-loaded to Developer A in Week 1 so B and C are never blocked.

## Developer A — Platform, I/O, and Color Foundations

**Owns:** `src/Contracts/`, `src/ImageLoader/`, `src/Color/`, project scaffolding, CI, test support/fakes, the `PublicAPI` skeleton.

**Deliverables**
- `composer.json`, PSR-4 autoload, PHPStan/CS-Fixer/PHPUnit config, `.github/workflows/ci.yml`, `CODEOWNERS`.
- **The frozen contracts** — all interfaces + DTOs in `src/Contracts/` (see next section). This is A's most important early deliverable; nothing parallel can start safely until it lands.
- `SourceResolver` — accepts a PHP stream resource, a GD image resource, a file path, or raw bytes, sniffs format via magic bytes (not extension), and normalizes to a decodable input. Handles `fopen` handles and `php://` streams.
- `GdImageLoader` implementing `ImageLoaderInterface::load(ImageSource): Raster` — decodes PNG/JPEG, normalizes palette/indexed PNGs to truecolor+alpha, exposes width/height and pixel access, preserves the alpha channel.
- `ImagickImageLoader` — optional adapter behind the same interface (feature-flagged; used when the ext is present or for CMYK).
- `ColorConverter` — sRGB↔linear↔XYZ↔Lab (D65) and RGB↔HSV, plus ΔE (CIE76/CIE94) distance. Pure, static-analyzable, heavily unit-tested.
- `SyntheticImageFactory` + `Fakes/` in `tests/Support/` so B and C can test against deterministic inputs immediately.

**Tests A owns:** loader format/alpha/edge-case tests (indexed PNG, grayscale JPEG, corrupt file → clear exception), color conversion accuracy tests against published reference values, source-resolution tests for each input type.

## Developer B — White Background Cropper

**Owns:** `src/WhiteBackgroundCropper/`, its tests and cropping fixtures.

**Deliverables**
- `WhiteBackgroundCropper implements CropperInterface` — `crop(Raster $in, CropOptions $opts): CropResult`.
- **Border-inward scanning** (not global white removal): scan rows from top and bottom, columns from left and right, stopping each edge at the first line that contains a non-near-white, non-transparent pixel. Return the smallest bounding rectangle. This structurally prevents deleting legitimate white *inside* the artwork.
- **Configurable near-white detection** via `CropOptions`: tolerance expressed as Lab lightness/chroma thresholds (default e.g. L* ≥ 95 and chroma ≤ 5) with an RGB fast-path; a per-line "fraction of pixels that must be content" guard so a few stray specks/noise pixels don't defeat cropping.
- Handle: fully white image (return sensible empty/whole-image result with a flag), transparent borders treated as background, 1-px content, off-center content.
- `CropResult` DTO: cropped `Raster` + original bounding box (x, y, w, h) + `wasCropped` flag.

**Interfaces consumed:** `Raster`, `ColorRGBA`, `ColorConverter` from A. Until A ships them, B codes against the frozen interfaces and A's `SyntheticImageFactory`/fakes.

**Tests B owns:** synthetic images with known white margins (assert exact resulting bbox), near-white/anti-aliased borders, noisy scanned-paper background, transparent-margin PNG, all-white image, no-margin image, tolerance-sweep tests.

## Developer C — Clustering, Coverage, Result & Docs/Examples

**Owns:** `src/ColorClusterer/`, `src/CoverageCalculator/`, `examples/`, final `README.md` assembly, ADR-003.

**Deliverables**
- `ColorHistogram` — bins a `Raster` into weighted color bins, skipping transparent pixels; returns unique colors + counts + total analyzed pixel count.
- `KMeansClusterer implements ClustererInterface` — k-means++ seeded init, Lloyd iterations in Lab, on the weighted histogram; returns `ClusterResult` (centroids as `ColorRGBA` + Lab, per-cluster weight).
- `KSelector` — automatic k via silhouette (primary) with elbow/WCSS diagnostics; Kmax + low-coverage/ΔE merge step.
- `PercentageCoverageCalculator implements CoverageCalculatorInterface` — turns `ClusterResult` + total into `ColorCoverage[]` (hex, rgb, coverage_percent), largest-remainder normalization to sum = 100.0, sorted descending.
- JSON serialization matching the assignment's example output shape.
- `examples/` scripts (from path and from file handle) and the finished `README.md`.

**Interfaces consumed:** `Raster`, `ColorRGBA`, `ColorConverter`, `ClusterResult`, `ColorCoverage` from A.

**Tests C owns:** clustering determinism (same seed → same centroids), synthetic 3-color image → asserts ~correct percentages and sum = 100, transparency-ignored test, high-resolution performance test (binning keeps it fast), k-selection tests (2 obvious clusters vs 5), merge-of-anti-aliasing test, JSON schema test.

---

# Interfaces and Contracts Between Components

These are authored and **frozen by Developer A at the end of Week 1**. They are the seams that let three people work without stepping on each other. Signatures (conceptual — real files carry full typing, `readonly`, and docblocks):

```php
namespace ImageColorAnalyzer\Contracts;

// ---- Input abstraction (Dev A) ----
interface ImageSource {
    /** @return resource a readable, seekable stream */
    public function stream();
    public function detectedFormat(): ImageFormat; // enum PNG|JPEG
}

// ---- Loader (Dev A) ----
interface ImageLoaderInterface {
    public function supports(ImageSource $source): bool;
    public function load(ImageSource $source): Raster;
}

// ---- Immutable pixel buffer (Dev A) — the shared currency ----
final class Raster {
    public function width(): int;
    public function height(): int;
    public function hasAlpha(): bool;
    public function pixelAt(int $x, int $y): ColorRGBA;   // r,g,b,a 0-255
    /** @return iterable<ColorRGBA> row-major, for fast scans */
    public function pixels(): iterable;
    public function crop(int $x, int $y, int $w, int $h): Raster;
}

final class ColorRGBA {
    public int $r, $g, $b, $a;
    public function isTransparent(int $alphaThreshold = 8): bool;
    public function toHex(): string; // "#RRGGBB"
}

// ---- Color space (Dev A) ----
final class ColorConverter {
    public function rgbToLab(ColorRGBA $c): array;   // [L,a,b]
    public function rgbToHsv(ColorRGBA $c): array;
    public function deltaE(array $lab1, array $lab2): float;
}

// ---- Cropper (Dev B) ----
final class CropOptions {
    public float $lightnessMin = 95.0;   // Lab L*
    public float $chromaMax    = 5.0;    // sqrt(a^2+b^2)
    public float $lineContentFraction = 0.002; // guard vs noise
    public int   $alphaThreshold = 8;
}
interface CropperInterface {
    public function crop(Raster $image, CropOptions $opts): CropResult;
}
final class CropResult {
    public Raster $raster;
    public array  $boundingBox; // [x,y,w,h]
    public bool   $wasCropped;
}

// ---- Clusterer (Dev C) ----
final class ClusterOptions {
    public ?int $fixedK = null;      // null => auto
    public int  $kMax = 8;
    public int  $histogramBitsPerChannel = 5;
    public float $mergeDeltaE = 3.0;
    public float $minClusterCoverage = 0.01;
    public int  $seed = 1;
}
interface ClustererInterface {
    public function cluster(Raster $image, ClusterOptions $opts): ClusterResult;
}
final class ClusterResult {
    /** @var Cluster[]  each: centroid ColorRGBA + Lab + weight(int) */
    public array $clusters;
    public int   $totalAnalyzedPixels;   // transparent already excluded
}

// ---- Coverage (Dev C) ----
interface CoverageCalculatorInterface {
    /** @return ColorCoverage[] sorted desc, percentages sum to 100.0 */
    public function calculate(ClusterResult $result): array;
}
final class ColorCoverage {
    public string $color;          // "#RRGGBB"
    public array  $rgb;            // [r,g,b]
    public float  $coveragePercent;
    public function toArray(): array; // {color, coverage_percent}
}

// ---- Public facade (Dev A skeleton; joint wiring) ----
final class ImageColorAnalyzer {
    public function __construct(
        ImageLoaderInterface $loader,
        CropperInterface $cropper,
        ClustererInterface $clusterer,
        CoverageCalculatorInterface $coverage,
    );
    /** @param mixed $source resource|string path|bytes|GD resource */
    public function analyze(mixed $source, ?AnalyzerOptions $opts = null): array; // ColorCoverage[] as arrays
    public function analyzeAsJson(mixed $source, ?AnalyzerOptions $opts = null): string;
}
```

**Explicit integration points (who hands what to whom):**

1. **A → B:** `Raster`, `ColorRGBA`, `ColorConverter`. B's cropper consumes a `Raster` and returns a `Raster` (via `CropResult`).
2. **A → C:** `Raster`, `ColorRGBA`, `ColorConverter`, and the `ClusterResult`/`ColorCoverage` DTO shapes.
3. **B → C (through the facade, not directly):** the cropped `Raster` produced by B becomes C's clusterer input. B and C never call each other; the facade sequences them, so they stay decoupled.
4. **C internal:** `ClusterResult` (clusterer) → `CoverageCalculator` → `ColorCoverage[]`.
5. **All → facade:** `ImageColorAnalyzer.analyze()` wires Loader→Cropper→Clusterer→Coverage. A owns the skeleton from Week 1 so B and C can write integration probes early; final wiring is a joint task in Week 3.

Because every hand-off is a frozen DTO, each developer can develop and test with A's `Fakes/` (e.g. a `FakeCropper` that returns its input, a `SyntheticImageFactory` producing a `Raster` with known content) before the real neighbors exist.

---

# Parallelization Strategy

**Task dependency graph** (→ means "must finish before"):

```
         ┌─────────────────────────────────────────────┐
         │  T0  Scaffolding + FROZEN Contracts (A)      │   ← the only hard gate
         └───────────────┬───────────────┬─────────────┘
                         │               │
       ┌─────────────────┘               └─────────────────┐
       ▼                                                    ▼
 ┌──────────────┐  ┌──────────────┐        ┌──────────────┐  ┌──────────────┐
 │ T1 ImageLoader│  │ T2 ColorConv.│        │ T3 Cropper   │  │ T4 Histogram │
 │      (A)      │  │      (A)      │        │     (B)      │  │ +Clusterer(C)│
 └──────┬───────┘  └──────┬───────┘        └──────┬───────┘  └──────┬───────┘
        │                 │  ┌───────────────────┘                 │
        │                 └──┤ (Cropper white-detect uses ColorConv)│
        │                    ▼                                      ▼
        │              (B integrates real T2)              ┌──────────────┐
        │                                                  │ T5 Coverage  │
        │                                                  │ +JSON  (C)   │
        │                                                  └──────┬───────┘
        └──────────────┬──────────────┬───────────────────────────┘
                       ▼              ▼
                ┌─────────────────────────────┐
                │ T6 PublicAPI wiring (joint)  │  needs T1,T3,T4,T5
                └───────────────┬─────────────┘
                                ▼
                ┌─────────────────────────────┐
                │ T7 Integration tests, example│  (joint)
                │ images, README, CI green     │
                └─────────────────────────────┘
```

**What runs in parallel:**
- **Serial gate:** T0 (contracts) must land first. Target: day 2–3 of Week 1. Nothing else is truly blocked before it, and the cost of getting it right is far lower than the cost of interface churn later.
- **Fully parallel after T0:** A does T1+T2, B does T3, C does T4 (then T5). Three independent streams, disjoint directories, each backed by fakes/synthetic fixtures.
- **Soft dependency:** B's white-detection ideally uses A's real `ColorConverter` (T2), but B is unblocked because the Lab thresholds can be validated against A's synthetic fixtures and a temporary RGB fast-path; B swaps in the real converter when T2 merges.
- **Join points:** T6 (facade wiring) needs all four modules; done jointly in a short pairing session. T7 (integration/docs/CI polish) is shared.

**Merge-conflict minimization:**
- Disjoint directory ownership (see structure) → developers almost never edit the same file.
- The only shared files (`composer.json`, `ci.yml`, `README.md`) are A/C-owned; others propose changes via PR rather than editing directly.
- Contracts are frozen and versioned; any change requires an ADR + all-three sign-off, which is rare by design.

---

# Milestones and Timeline

**Week 1 — Onboarding + Foundations gate**
- All: GitHub onboarding (clone, branch, commit conventions, PR flow), repo setup, agree on coding standards.
- A: scaffolding, CI skeleton, **freeze contracts (T0)** by day 3, ship `SyntheticImageFactory` + fakes, start `GdImageLoader`/`ColorConverter`.
- B: cropper design + test fixtures (synthetic margined images), skeleton `WhiteBackgroundCropper` against contracts + fakes.
- C: clustering design, ADR-003, `ColorHistogram` skeleton + histogram tests against synthetic rasters.
- **Milestone M1:** contracts frozen; `composer install` + empty CI green on all PHP versions; each dev has a red/green test skeleton.

**Week 2 — Core implementations in parallel**
- A: finish `GdImageLoader` (incl. indexed/grayscale/alpha normalization) + `ColorConverter` with reference-value tests; optional `ImagickImageLoader`.
- B: full border-inward cropping with configurable Lab tolerance, noise guard, edge cases; unit tests passing.
- C: k-means++ + Lloyd in Lab on weighted histogram, `KSelector` (silhouette + elbow), determinism tests.
- **Milestone M2:** T1, T2, T3, T4 merged to `main`, each ≥ 90% unit-tested and PHPStan-clean.

**Week 3 — Coverage, integration, real images**
- C: `PercentageCoverageCalculator` (largest-remainder sum-to-100), JSON output, examples.
- All: **wire the facade (T6)**, write integration tests through `analyze()`, add real sample PNG/JPEG scans, tune performance (histogram bits, subsampling), handle CMYK-JPEG routing.
- **Milestone M3:** end-to-end analysis works on real images from a file handle; integration suite green; percentages sum ≈ 100.

**Week 4 — Hardening + delivery (buffer)**
- All: edge-case hardening (huge images, tiny images, mono-color, fully transparent), finalize README + ADRs, mutation testing on clustering math, CI matrix fully green incl. Imagick job.
- **Milestone M4 (release):** tag `v1.0.0`, all acceptance criteria met, docs complete.

---

# Git Workflow

**Branch strategy — trunk-based with short-lived feature branches.**
- `main` is always releasable and protected (no direct pushes; CI must pass; ≥1 approval).
- Feature branches off `main`, short-lived (< ~2 days), named `type/scope-short-desc`, e.g. `feat/cropper-border-scan`, `feat/loader-gd`, `test/clusterer-determinism`, `chore/ci-matrix`.
- No long-running per-developer branches — reduces divergence and merge pain.

**Pull request process.**
- Small, single-purpose PRs (ideally one module concern each). PR template: what/why, linked task, test evidence, checklist (tests added, PHPStan clean, CS-Fixer clean, contracts unchanged or ADR linked).
- CI (lint + PHPStan + PHPUnit matrix) must be green before review.
- **Squash-merge** into `main` so history stays one clean commit per PR.
- Any change to `src/Contracts/` requires all three reviewers + an ADR — this keeps the parallel seams stable.

**Code review ownership (via `CODEOWNERS`).**
- `src/Contracts/`, `src/ImageLoader/`, `src/Color/`, CI → **A** (with cross-review by B/C on contract changes).
- `src/WhiteBackgroundCropper/` → reviewed by **A** (contract adherence) and **C**.
- `src/ColorClusterer/`, `src/CoverageCalculator/` → reviewed by **A** and **B**.
- Rule: **no self-merge**; each PR needs one non-author approval from the mapped owner. This guarantees every developer reads across module boundaries and keeps interface usage honest.

**Commit conventions — Conventional Commits.**
`feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `perf:`, `chore:`, `ci:` with an optional scope, e.g. `feat(clusterer): add silhouette-based k selection`. Imperative mood, ≤ 72-char subject, body explains *why*. Enables readable history and later automated changelog/versioning.

---

# Testing Strategy

**Layers**
- **Unit tests (per module, owned by that module's developer):** loaders (formats, alpha, corrupt input), color conversions (reference ΔE values), cropper (exact bbox on synthetic margins), clusterer (determinism, k selection), coverage (sum-to-100, ordering).
- **Golden/synthetic fixtures:** `SyntheticImageFactory` builds images with *exactly known* composition — e.g. a 1000×1000 image that is 50% `#FF0000`, 30% `#0000FF`, 20% `#00FF00` inside a 100 px white border. Because the ground truth is exact, we can assert the cropper's bbox and the coverage percentages within a tight tolerance. This is the backbone of objective grading.
- **Property tests:** for any valid image, Σ coverage = 100.0 ± ε; number of clusters ≤ Kmax; transparent pixels never counted.
- **Integration tests (joint):** drive the real `ImageColorAnalyzer::analyze()` from a `fopen` handle and from a path, on both synthetic and real PNG/JPEG samples, asserting the JSON shape from the assignment.
- **Performance guard:** a high-resolution fixture must analyze under a time budget, proving histogram binning delivers resolution independence.
- **Mutation testing (Infection)** on `ColorClusterer`/`CoverageCalculator` to ensure the math tests actually bite.

**Static analysis & style:** PHPStan level 8 and PHP-CS-Fixer (PSR-12) run in CI and block merge.

**CI (GitHub Actions):** matrix on PHP 8.3 / 8.4 / 8.5 with `ext-gd`; a separate job installs `ext-imagick` to exercise the Imagick adapter. Steps: `composer install` → CS-Fixer (dry-run) → PHPStan → PHPUnit (with coverage) → (nightly) Infection. Coverage target ≥ 85% overall, ≥ 95% on clustering/coverage/cropper core.

---

# Technical Risks and Mitigations

- **Memory blow-up on high-resolution images.** — *Mitigation:* never cluster raw pixels; bin to a weighted histogram immediately, and optionally downscale for the *cropping* pass. GD memory is capped by validating dimensions up front and rejecting/streaming absurd inputs with a clear exception.
- **Anti-aliasing / compression halos create spurious "principal" colors.** — *Mitigation:* histogram binning smooths near-duplicates; post-cluster merge by ΔE and drop clusters below a coverage floor; cluster in perceptual Lab so merges match perception.
- **Cropper removes legitimate white content.** — *Mitigation:* border-inward scanning only (never global white removal), a per-line content-fraction guard against stray noise, and configurable tolerance; documented behavior + explicit tests including "white shape on white margin" cases.
- **Non-deterministic k-means → flaky tests / unstable output.** — *Mitigation:* k-means++ with a fixed seed, deterministic tie-breaking, and silhouette selection over a bounded k range; determinism is itself a test.
- **GD can't handle CMYK JPEGs / 16-bit / ICC.** — *Mitigation:* magic-byte + colorspace detection in the loader; route to Imagick adapter if present, else throw `UnsupportedImageException` with guidance. Documented limitation.
- **PNG alpha & indexed/grayscale variety.** — *Mitigation:* loader normalizes everything to truecolor+alpha; transparency threshold configurable; explicit fixtures for palette/grayscale/alpha.
- **Interface churn breaks the parallel plan.** — *Mitigation:* freeze contracts in Week 1; any change needs an ADR + all-three approval; DTOs are readonly to discourage drift.
- **Integration becomes a single-person bottleneck.** — *Mitigation:* A ships the facade skeleton and fakes in Week 1 so B and C integrate continuously against stubs; final wiring is a short joint session, not a hand-off.
- **Uneven intern skill / onboarding drag.** — *Mitigation:* Week 1 is explicitly onboarding + a low-risk task each; pair on the facade; Week 4 buffer absorbs slippage.

---

# Acceptance Criteria

**Per module**

- **ImageLoader (A):** loads PNG and JPEG from resource, stream, path, and bytes; normalizes palette/grayscale/alpha to truecolor+alpha; correct width/height; corrupt/unsupported input throws a typed exception; unit tests + PHPStan clean.
- **ColorConverter (A):** RGB↔Lab↔HSV and ΔE match published reference values within tolerance; pure and side-effect-free.
- **WhiteBackgroundCropper (B):** returns the smallest rectangle containing all non-white, non-transparent content; near-white tolerance is configurable and effective on anti-aliased/scanned borders; never crops interior white; handles all-white, no-margin, 1-px-content, and transparent-margin cases; exact-bbox tests on synthetic fixtures pass.
- **ColorClusterer (C):** groups similar colors via k-means++ in Lab on a weighted histogram; ignores transparent pixels; automatic k (or fixed k) within Kmax; deterministic for a given seed; resolution-independent within the performance budget.
- **CoverageCalculator (C):** returns each principal color as hex+rgb with a coverage percentage; list sorted descending; percentages sum to 100.0 ± ε via largest-remainder; JSON matches the assignment's example shape.

**Final integration (maps to assignment §8)**
- Loads both PNG and JPEG from a file handle. ✔
- White background regions cropped correctly; content not mistakenly removed. ✔
- Colors grouped by clustering; a coverage percentage returned per major color; percentages sum ≈ 100%. ✔
- Transparent pixels ignored. ✔
- Delivered as a reusable Composer library (not a script), PSR-12, PHPStan level 8. ✔
- Several validation test images provided (synthetic + real); CI green on PHP 8.3/8.4/8.5. ✔
- Code on GitHub with README usage docs + ADRs. ✔

---

# Final Integration Plan

1. **Freeze the contracts (end of Week 1).** Publish `docs/contracts.md`; tag the interfaces as the integration baseline.
2. **Continuous integration against fakes (Weeks 1–2).** B and C run the facade with A's `FakeLoader`/`FakeCropper` and `SyntheticImageFactory`, so integration is exercised long before real modules land.
3. **Module merges (M2).** T1–T4 land on `main` behind green CI, each independently reviewed by its `CODEOWNERS` reviewer.
4. **Wire the real facade (Week 3, joint session).** Replace fakes with real `GdImageLoader` → `WhiteBackgroundCropper` → `KMeansClusterer` → `PercentageCoverageCalculator` inside `ImageColorAnalyzer::analyze()`; add `AnalyzerOptions` pass-through to each component's options object.
5. **End-to-end verification.** Run the integration suite on synthetic images (assert coverage against known ground truth and sum = 100) and on real PNG/JPEG scans opened via `fopen` handles; verify the JSON output matches the assignment example.
6. **Performance + edge pass.** Confirm the high-res budget holds; exercise CMYK-JPEG routing, fully transparent, mono-color, and tiny images.
7. **Docs + examples.** Finalize `README.md` (install, quick start from path and from handle, options, output format, limitations) and the three ADRs; ship `examples/`.
8. **Release.** All acceptance criteria checked, CI green across the matrix incl. the Imagick job, tag **`v1.0.0`**, and hand off the repository link.

**Definition of Done for the project:** a reviewer can `composer require` the library, open a PNG or JPEG via a file handle, call `analyze()`, and receive a sorted list of principal print colors whose coverage percentages sum to ~100 — with the white margin removed, transparency ignored, and the whole thing covered by passing tests on PHP 8.3–8.5.

---

*Sources for version guidance: [PHP: Supported Versions](https://www.php.net/supported-versions.php) · [endoflife.date/php](https://endoflife.date/php) · [PHP.Watch Versions](https://php.watch/versions).*
