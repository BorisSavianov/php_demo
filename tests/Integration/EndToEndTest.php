<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Integration;

use ImageColorAnalyzer\Options\AnalyzerOptions;
use ImageColorAnalyzer\Options\ClusterOptions;
use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;
use ImageColorAnalyzer\PublicAPI\ImageColorAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real, fully-wired pipeline (GD loader -> white cropper -> k-means
 * clusterer -> coverage) through the public facade, from a file handle and from
 * a path, as the assignment requires.
 */
final class EndToEndTest extends TestCase
{
    public function testFactoryWiresTheFacade(): void
    {
        self::assertInstanceOf(ImageColorAnalyzer::class, AnalyzerFactory::createDefault());
    }

    public function testAnalyzePngBandsFromHandle(): void
    {
        // 120x120: 20px white border; inner 80x80 split 50/30/20 by rows.
        $handle = $this->pngHandle(120, 120, static function (\GdImage $img): void {
            imagefilledrectangle($img, 0, 0, 119, 119, self::rgb($img, 255, 255, 255));
            imagefilledrectangle($img, 20, 20, 99, 59, self::rgb($img, 255, 0, 0));   // 40 rows
            imagefilledrectangle($img, 20, 60, 99, 83, self::rgb($img, 0, 0, 255));    // 24 rows
            imagefilledrectangle($img, 20, 84, 99, 99, self::rgb($img, 0, 255, 0));    // 16 rows
        });

        $colors = AnalyzerFactory::createDefault()->analyze($handle);
        fclose($handle);

        $byColor = $this->byColor($colors);
        self::assertSame(['#0000FF', '#00FF00', '#FF0000'], $this->sortedKeys($byColor));
        self::assertEqualsWithDelta(50.0, $byColor['#FF0000'], 0.6);
        self::assertEqualsWithDelta(30.0, $byColor['#0000FF'], 0.6);
        self::assertEqualsWithDelta(20.0, $byColor['#00FF00'], 0.6);
        self::assertEqualsWithDelta(100.0, array_sum($byColor), 1e-9);
    }

    public function testAnalyzeAsJsonMatchesAssignmentShape(): void
    {
        $handle = $this->pngHandle(60, 60, static function (\GdImage $img): void {
            imagefilledrectangle($img, 0, 0, 59, 59, self::rgb($img, 255, 255, 255));
            imagefilledrectangle($img, 10, 10, 49, 49, self::rgb($img, 200, 0, 0));
        });

        $json = AnalyzerFactory::createDefault()->analyzeAsJson($handle);
        fclose($handle);

        /** @var list<array{color:string,coverage_percent:float}> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($decoded);
        foreach ($decoded as $entry) {
            self::assertArrayHasKey('color', $entry);
            self::assertArrayHasKey('coverage_percent', $entry);
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $entry['color']);
            self::assertIsFloat($entry['coverage_percent']);
        }
        self::assertStringContainsString('coverage_percent', $json);
    }

    public function testAnalyzePathAsJsonPreservesFloatFraction(): void
    {
        // A clean mono-color-on-white image yields a single 100.0% color. Without
        // JSON_PRESERVE_ZERO_FRACTION that renders as "100", diverging from the
        // documented float shape and from analyzeAsJson.
        $path = tempnam(sys_get_temp_dir(), 'ica') . '.png';
        $image = imagecreatetruecolor(40, 40);
        imagefilledrectangle($image, 0, 0, 39, 39, self::rgb($image, 255, 255, 255));
        imagefilledrectangle($image, 10, 10, 29, 29, self::rgb($image, 0, 0, 0));
        imagepng($image, $path);

        try {
            $json = AnalyzerFactory::createDefault()->analyzePathAsJson($path);
        } finally {
            unlink($path);
        }

        self::assertStringContainsString('100.0', $json);
        /** @var list<array{color:string,coverage_percent:float}> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsFloat($decoded[0]['coverage_percent']);
    }

    public function testAnalyzeFromPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ica') . '.png';
        $image = imagecreatetruecolor(40, 40);
        imagefilledrectangle($image, 0, 0, 39, 39, self::rgb($image, 255, 255, 255));
        imagefilledrectangle($image, 10, 10, 29, 29, self::rgb($image, 0, 0, 0));
        imagepng($image, $path);

        try {
            $colors = AnalyzerFactory::createDefault()->analyzePath($path);
        } finally {
            unlink($path);
        }

        $byColor = $this->byColor($colors);
        self::assertArrayHasKey('#000000', $byColor);
        self::assertEqualsWithDelta(100.0, array_sum($byColor), 1e-9);
    }

    public function testTransparentPixelsAreIgnored(): void
    {
        // Left half opaque red, right half fully transparent.
        $handle = $this->pngHandle(40, 20, static function (\GdImage $img): void {
            imagesavealpha($img, true);
            imagealphablending($img, false);
            imagefilledrectangle($img, 0, 0, 39, 19, self::rgba($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 0, 0, 19, 19, self::rgb($img, 255, 0, 0));
        });

        $colors = AnalyzerFactory::createDefault()->analyze($handle);
        fclose($handle);

        $byColor = $this->byColor($colors);
        self::assertSame(['#FF0000'], array_keys($byColor));
        self::assertEqualsWithDelta(100.0, $byColor['#FF0000'], 1e-9);
    }

    public function testFixedKIsHonoredEndToEnd(): void
    {
        $handle = $this->pngHandle(60, 60, static function (\GdImage $img): void {
            imagefilledrectangle($img, 0, 0, 59, 29, self::rgb($img, 255, 0, 0));
            imagefilledrectangle($img, 0, 30, 59, 44, self::rgb($img, 0, 255, 0));
            imagefilledrectangle($img, 0, 45, 59, 59, self::rgb($img, 0, 0, 255));
        });

        $options = new AnalyzerOptions(cluster: new ClusterOptions(fixedK: 2, mergeDeltaE: 0.0, minClusterCoverage: 0.0));
        $colors = AnalyzerFactory::createDefault()->analyze($handle, $options);
        fclose($handle);

        self::assertLessThanOrEqual(2, count($colors));
        self::assertEqualsWithDelta(100.0, array_sum($this->byColor($colors)), 1e-9);
    }

    public function testAutomaticKPreservesWeightedSingleBinAccentFromRealImage(): void
    {
        $path = __DIR__ . '/../Fixtures/real/weighted-single-bin-accent.png';
        $expected = [
            ['color' => '#8FC4E2', 'coverage_percent' => 75.3],
            ['color' => '#F8C83E', 'coverage_percent' => 18.3],
            ['color' => '#000000', 'coverage_percent' => 6.4],
        ];

        $analyzer = AnalyzerFactory::createDefault();
        self::assertSame($expected, $analyzer->analyzePath($path));

        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        try {
            self::assertSame($expected, $analyzer->analyze($handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param callable(\GdImage):void $draw
     *
     * @return resource
     */
    private function pngHandle(int $width, int $height, callable $draw)
    {
        $image = imagecreatetruecolor($width, $height);
        $draw($image);
        $handle = fopen('php://temp', 'r+b');
        self::assertIsResource($handle);
        imagepng($image, $handle);
        rewind($handle);

        return $handle;
    }

    /**
     * @param list<array{color:string,coverage_percent:float}> $colors
     *
     * @return array<string, float>
     */
    private function byColor(array $colors): array
    {
        $map = [];
        foreach ($colors as $entry) {
            $map[$entry['color']] = $entry['coverage_percent'];
        }

        return $map;
    }

    /**
     * @param array<string, float> $byColor
     *
     * @return list<string>
     */
    private function sortedKeys(array $byColor): array
    {
        $keys = array_keys($byColor);
        sort($keys);

        return $keys;
    }

    private static function rgb(\GdImage $image, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($image, $r, $g, $b);
        self::assertNotFalse($color);

        return $color;
    }

    private static function rgba(\GdImage $image, int $r, int $g, int $b, int $a): int
    {
        $color = imagecolorallocatealpha($image, $r, $g, $b, $a);
        self::assertNotFalse($color);

        return $color;
    }
}
