<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\ImageLoader;

use ImageColorAnalyzer\Contracts\ImageFormat;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\Exception\InvalidImageException;
use ImageColorAnalyzer\Exception\UnsupportedImageException;

/**
 * OWNER: Developer A (foundation).
 *
 * Wraps a file path or stream resource, sniffing PNG/JPEG from magic bytes
 * (never from the file extension).
 */
final class FileImageSource implements ImageSource
{
    /**
     * @param resource $stream
     */
    private function __construct(
        private $stream,
        private readonly ImageFormat $format,
    ) {
    }

    public static function fromPath(string $path): self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidImageException("Cannot open image file: {$path}");
        }

        return self::fromStream($handle);
    }

    /**
     * @param resource $handle
     */
    public static function fromStream($handle): self
    {
        if (!is_resource($handle)) {
            throw new InvalidImageException('Expected a valid, readable stream resource.');
        }

        return new self($handle, self::sniff($handle));
    }

    /**
     * @return resource
     */
    public function stream()
    {
        rewind($this->stream);

        return $this->stream;
    }

    public function detectedFormat(): ImageFormat
    {
        return $this->format;
    }

    /**
     * @param resource $handle
     */
    private static function sniff($handle): ImageFormat
    {
        rewind($handle);
        $magic = (string) fread($handle, 8);
        rewind($handle);

        if (str_starts_with($magic, "\x89PNG\x0d\x0a\x1a\x0a")) {
            return ImageFormat::PNG;
        }
        if (str_starts_with($magic, "\xFF\xD8\xFF")) {
            return ImageFormat::JPEG;
        }

        throw new UnsupportedImageException('Only PNG and JPEG sources are supported.');
    }
}
