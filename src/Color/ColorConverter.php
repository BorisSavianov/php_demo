<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Color;

use ImageColorAnalyzer\Contracts\ColorRGBA;

/**
 * OWNER: Developer A (foundation).
 *
 * Pure, deterministic sRGB <-> CIELAB <-> HSV conversions (D65 reference white)
 * plus CIE76 delta-E. Analysis happens in Lab; RGB stays the transport format.
 */
final class ColorConverter
{
    /**
     * @return array{0:float,1:float,2:float} [L*, a*, b*]
     */
    public function rgbToLab(ColorRGBA $c): array
    {
        [$x, $y, $z] = $this->rgbToXyz($c);

        $fx = $this->pivotXyz($x / 95.047);
        $fy = $this->pivotXyz($y / 100.0);
        $fz = $this->pivotXyz($z / 108.883);

        return [
            116.0 * $fy - 16.0,
            500.0 * ($fx - $fy),
            200.0 * ($fy - $fz),
        ];
    }

    /**
     * @return array{0:float,1:float,2:float} [H in 0-360, S in 0-1, V in 0-1]
     */
    public function rgbToHsv(ColorRGBA $c): array
    {
        $r = $c->r / 255.0;
        $g = $c->g / 255.0;
        $b = $c->b / 255.0;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        $h = 0.0;
        if ($delta > 0.0) {
            if ($max === $r) {
                $h = 60.0 * fmod(($g - $b) / $delta, 6.0);
            } elseif ($max === $g) {
                $h = 60.0 * ((($b - $r) / $delta) + 2.0);
            } else {
                $h = 60.0 * ((($r - $g) / $delta) + 4.0);
            }
        }
        if ($h < 0.0) {
            $h += 360.0;
        }

        $s = $max > 0.0 ? $delta / $max : 0.0;

        return [$h, $s, $max];
    }

    /**
     * CIE76 delta-E: Euclidean distance in CIELAB.
     *
     * @param array{0:float,1:float,2:float} $lab1
     * @param array{0:float,1:float,2:float} $lab2
     */
    public function deltaE(array $lab1, array $lab2): float
    {
        return sqrt(
            ($lab1[0] - $lab2[0]) ** 2
            + ($lab1[1] - $lab2[1]) ** 2
            + ($lab1[2] - $lab2[2]) ** 2
        );
    }

    /**
     * @return array{0:float,1:float,2:float} XYZ scaled to 0-100 (D65)
     */
    private function rgbToXyz(ColorRGBA $c): array
    {
        $r = $this->linearize($c->r / 255.0) * 100.0;
        $g = $this->linearize($c->g / 255.0) * 100.0;
        $b = $this->linearize($c->b / 255.0) * 100.0;

        return [
            $r * 0.4124 + $g * 0.3576 + $b * 0.1805,
            $r * 0.2126 + $g * 0.7152 + $b * 0.0722,
            $r * 0.0193 + $g * 0.1192 + $b * 0.9505,
        ];
    }

    private function linearize(float $channel): float
    {
        return $channel > 0.04045
            ? (($channel + 0.055) / 1.055) ** 2.4
            : $channel / 12.92;
    }

    private function pivotXyz(float $t): float
    {
        return $t > 0.008856
            ? $t ** (1.0 / 3.0)
            : (7.787 * $t) + (16.0 / 116.0);
    }
}
