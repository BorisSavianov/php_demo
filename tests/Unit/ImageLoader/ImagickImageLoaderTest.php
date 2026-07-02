<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\ImageLoader\ImagickImageLoader;
use PHPUnit\Framework\TestCase;

final class ImagickImageLoaderTest extends TestCase
{
    public function testSupportReflectsWhetherImagickExtensionIsLoaded(): void
    {
        $loader = new ImagickImageLoader();
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        self::assertSame(class_exists('Imagick'), $loader->supports($source));
    }

    public function testLoadThrowsClearExceptionWhenImagickIsMissing(): void
    {
        if (class_exists('Imagick')) {
            self::markTestSkipped('This assertion applies only when ext-imagick is not loaded.');
        }

        $loader = new ImagickImageLoader();
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        $this->expectException(UnsupportedImageException::class);
        $loader->load($source);
    }
}
