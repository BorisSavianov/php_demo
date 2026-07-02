<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\ImageLoader\GdImageLoader;
use PHPUnit\Framework\TestCase;

final class GdImageLoaderTest extends TestCase
{
    public function testSupportsReportsTrue(): void
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        fwrite($stream, "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));
        rewind($stream);

        $loader = new GdImageLoader();
        self::assertTrue($loader->supports(FileImageSource::fromStream($stream)));
    }

    public function testLoadDecodesPixels(): void
    {
        // TODO(A): create a small PNG/JPEG fixture, load it, assert dimensions +
        // a known corner pixel. Cover indexed/grayscale/alpha PNGs.
        self::markTestIncomplete('GdImageLoader::load() pending — Developer A.');
    }
}
