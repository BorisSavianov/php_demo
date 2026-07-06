<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\Contracts;

use ImageColorAnalyzer\Contracts\EncodedImage;
use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Exception\ImageSaveException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EncodedImageTest extends TestCase
{
    public function testExposesCanonicalPngMetadata(): void
    {
        $image = new EncodedImage('png-bytes', 12, 8);

        self::assertSame('png-bytes', $image->bytes);
        self::assertSame(ImageFormat::PNG, $image->format);
        self::assertSame('image/png', $image->mediaType);
        self::assertSame(12, $image->width);
        self::assertSame(8, $image->height);
    }

    public function testRejectsEmptyBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Encoded image bytes must not be empty.');

        new EncodedImage('', 1, 1);
    }

    public function testRejectsInvalidDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Encoded image dimensions must be positive.');

        new EncodedImage('png-bytes', 0, 1);
    }

    public function testSavesBytesToANewFile(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/crop.png';

        try {
            (new EncodedImage('png-bytes', 1, 1))->saveTo($path);

            self::assertSame('png-bytes', file_get_contents($path));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testRefusesToOverwriteByDefault(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/crop.png';
        file_put_contents($path, 'original');

        try {
            (new EncodedImage('replacement', 1, 1))->saveTo($path);
            self::fail('Expected an existing destination to be rejected.');
        } catch (ImageSaveException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
            self::assertSame('original', file_get_contents($path));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testOverwritesOnlyWhenExplicitlyRequested(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/crop.png';
        file_put_contents($path, 'original');

        try {
            (new EncodedImage('replacement', 1, 1))->saveTo($path, overwrite: true);

            self::assertSame('replacement', file_get_contents($path));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testOverwriteAtomicallyReplacesTheDestination(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/crop.png';
        file_put_contents($path, 'original');
        $original = fopen($path, 'rb');
        self::assertIsResource($original);

        try {
            (new EncodedImage('replacement', 1, 1))->saveTo($path, overwrite: true);

            self::assertSame('replacement', file_get_contents($path));
            self::assertSame('original', stream_get_contents($original));
        } finally {
            fclose($original);
            $this->removeDirectory($directory);
        }
    }

    public function testReportsInvalidDestinationWithoutCreatingDirectories(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/missing/crop.png';

        try {
            $this->expectException(ImageSaveException::class);
            $this->expectExceptionMessage('Unable to open cropped image destination');

            (new EncodedImage('png-bytes', 1, 1))->saveTo($path);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testRejectsAnEmptyDestination(): void
    {
        $this->expectException(ImageSaveException::class);
        $this->expectExceptionMessage('Cropped image destination must not be empty.');

        (new EncodedImage('png-bytes', 1, 1))->saveTo('');
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/ica-encoded-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
