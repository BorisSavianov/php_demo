<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\PublicAPI;

use ImageColorAnalyzer\Color\ColorConverter;
use ImageColorAnalyzer\ColorClusterer\ColorHistogram;
use ImageColorAnalyzer\ColorClusterer\KMeansClusterer;
use ImageColorAnalyzer\ColorClusterer\KSelector;
use ImageColorAnalyzer\Contracts\ColorRGBA;
use ImageColorAnalyzer\Contracts\EncodedImage;
use ImageColorAnalyzer\Contracts\PngEncoderInterface;
use ImageColorAnalyzer\CoverageCalculator\PercentageCoverageCalculator;
use ImageColorAnalyzer\Exception\ImageEncodingException;
use ImageColorAnalyzer\ImageLoader\InMemoryRaster;
use ImageColorAnalyzer\PublicAPI\ImageColorAnalyzer;
use ImageColorAnalyzer\Tests\Support\Fakes\FakeImageLoader;
use ImageColorAnalyzer\Tests\Support\Fakes\PassthroughCropper;
use PHPUnit\Framework\TestCase;

final class ImageColorAnalyzerTest extends TestCase
{
    public function testLegacyAnalysisDoesNotEncodeButProcessPropagatesEncoderFailure(): void
    {
        $encoder = new class () implements PngEncoderInterface {
            public int $calls = 0;

            public function encode(\ImageColorAnalyzer\Contracts\Raster $image): EncodedImage
            {
                ++$this->calls;
                throw new ImageEncodingException('forced encoder failure');
            }
        };
        $converter = new ColorConverter();
        $analyzer = new ImageColorAnalyzer(
            new FakeImageLoader(new InMemoryRaster(1, 1, [new ColorRGBA(255, 0, 0)])),
            new PassthroughCropper(),
            new KMeansClusterer($converter, new ColorHistogram(), new KSelector($converter)),
            new PercentageCoverageCalculator(),
            pngEncoder: $encoder,
        );
        $source = imagecreatetruecolor(1, 1);

        self::assertSame(
            [['color' => '#FF0000', 'coverage_percent' => 100.0]],
            $analyzer->analyze($source),
        );
        self::assertSame(0, $encoder->calls);

        try {
            $analyzer->process($source);
            self::fail('Expected process() to propagate the encoder failure.');
        } catch (ImageEncodingException $exception) {
            self::assertSame('forced encoder failure', $exception->getMessage());
            self::assertSame(1, $encoder->calls);
        }
    }
}
