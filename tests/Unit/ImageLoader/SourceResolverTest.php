<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\ImageLoader\SourceResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SourceResolverTest extends TestCase
{
    public function testPassesThroughExistingImageSource(): void
    {
        $source = FileImageSource::fromBytes($this->pngHeader());

        self::assertSame($source, (new SourceResolver())->resolve($source));
    }

    public function testResolvesRawBytes(): void
    {
        $source = (new SourceResolver())->resolve($this->pngHeader());

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
    }

    public function testResolvesExplicitPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ica-source-');
        if ($path === false) {
            self::fail('Unable to create a temp file.');
        }

        try {
            file_put_contents($path, $this->pngHeader());

            $source = (new SourceResolver())->resolvePath($path);

            self::assertSame(ImageFormat::PNG, $source->detectedFormat());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testPlainStringsAreAlwaysResolvedAsBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ica-source-');
        if ($path === false) {
            self::fail('Unable to create a temp file.');
        }

        try {
            file_put_contents($path, $this->pngHeader());

            $this->expectException(UnsupportedImageException::class);

            (new SourceResolver())->resolve($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testResolvesStream(): void
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        fwrite($stream, $this->pngHeader());
        rewind($stream);

        $source = (new SourceResolver())->resolve($stream);

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
    }

    public function testResolvesGdImage(): void
    {
        $image = imagecreatetruecolor(2, 2);
        if (!$image instanceof \GdImage) {
            self::fail('Unable to create a GD image.');
        }

        $source = (new SourceResolver())->resolve($image);

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
    }

    public function testRejectsUnsupportedType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceResolver())->resolve(42);
    }

    private function pngHeader(): string
    {
        return "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16);
    }
}
