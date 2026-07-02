<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use PHPUnit\Framework\TestCase;

final class FileImageSourceTest extends TestCase
{
    public function testDetectsPngFromMagicBytes(): void
    {
        $source = FileImageSource::fromStream($this->streamWith("\x89PNG\x0d\x0a\x1a\x0a"));

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
    }

    public function testDetectsJpegFromMagicBytes(): void
    {
        $source = FileImageSource::fromStream($this->streamWith("\xFF\xD8\xFF\xE0"));

        self::assertSame(ImageFormat::JPEG, $source->detectedFormat());
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(UnsupportedImageException::class);
        FileImageSource::fromStream($this->streamWith('GIF89a'));
    }

    public function testCreatesSourceFromRawBytes(): void
    {
        $source = FileImageSource::fromBytes("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 16));

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
        self::assertSame("\x89PNG\x0d\x0a\x1a\x0a", fread($source->stream(), 8));
    }

    public function testCopiesNonSeekableStreamsIntoSeekableSources(): void
    {
        NonSeekableImageStream::register();
        NonSeekableImageStream::$bytes = "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x01", 16);

        $stream = fopen(NonSeekableImageStream::PROTOCOL . '://image', 'rb');
        if (!is_resource($stream)) {
            self::fail('Unable to open a non-seekable test stream.');
        }

        $source = FileImageSource::fromStream($stream);

        self::assertSame(ImageFormat::PNG, $source->detectedFormat());
        self::assertSame(NonSeekableImageStream::$bytes, stream_get_contents($source->stream()));
    }

    public function testRejectsInvalidStreamArgument(): void
    {
        $this->expectException(InvalidImageException::class);

        /** @phpstan-ignore-next-line Deliberately passes invalid input to cover runtime validation. */
        FileImageSource::fromStream('not a stream');
    }

    /**
     * @return resource
     */
    private function streamWith(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        if (!is_resource($stream)) {
            self::fail('Unable to open a temporary stream.');
        }
        fwrite($stream, $bytes . str_repeat("\x00", 32));
        rewind($stream);

        return $stream;
    }
}

final class NonSeekableImageStream
{
    public const PROTOCOL = 'ica-nonseekable';

    public static string $bytes = '';

    /** @var resource|null Stream context, assigned by PHP's stream machinery. */
    public $context;

    private int $position = 0;

    public static function register(): void
    {
        $wrappers = stream_get_wrappers();
        if (!in_array(self::PROTOCOL, $wrappers, true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$bytes, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$bytes);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
