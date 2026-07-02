<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Support;

use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;

/**
 * OWNER: Developer A. Builds rasters with exactly-known composition so tests can
 * assert crop boxes and coverage percentages against ground truth.
 */
final class SyntheticImageFactory
{
    public static function solid(int $width, int $height, ColorRGBA $color): InMemoryRaster
    {
        /** @var list<ColorRGBA> $pixels */
        $pixels = array_fill(0, $width * $height, $color);

        return new InMemoryRaster($width, $height, $pixels, $color->a < 255);
    }

    /**
     * A content rectangle centered on a border of `margin` px filled with $background.
     */
    public static function contentOnBorder(
        int $width,
        int $height,
        int $margin,
        ColorRGBA $content,
        ?ColorRGBA $background = null,
    ): InMemoryRaster {
        $background ??= new ColorRGBA(255, 255, 255);
        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $inside = $x >= $margin && $y >= $margin
                    && $x < $width - $margin && $y < $height - $margin;
                $pixels[] = $inside ? $content : $background;
            }
        }

        return new InMemoryRaster($width, $height, $pixels, false);
    }

    /**
     * Horizontal color bands filling the whole image (known per-color coverage).
     *
     * @param list<array{color:ColorRGBA,fraction:float}> $bands fractions should sum to ~1.0
     */
    public static function bands(int $width, int $height, array $bands): InMemoryRaster
    {
        /** @var list<ColorRGBA> $pixels */
        $pixels = [];
        $assigned = 0;
        $last = count($bands) - 1;
        foreach ($bands as $i => $band) {
            $rows = $i === $last ? $height - $assigned : (int) round($band['fraction'] * $height);
            for ($r = 0; $r < $rows; $r++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixels[] = $band['color'];
                }
            }
            $assigned += $rows;
        }

        return new InMemoryRaster($width, $height, $pixels, false);
    }
}
