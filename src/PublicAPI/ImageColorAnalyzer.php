<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\PublicAPI;

use ImageColorAnalyzer\Contracts\ClustererInterface;
use ImageColorAnalyzer\Contracts\CoverageCalculatorInterface;
use ImageColorAnalyzer\Contracts\CropperInterface;
use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\Contracts\ImageSource;
use ImageColorAnalyzer\ImageLoader\FileImageSource;
use ImageColorAnalyzer\Options\AnalyzerOptions;
use InvalidArgumentException;

/**
 * Public entry point. Wires Loader -> Cropper -> Clusterer -> Coverage.
 * OWNER: skeleton by Developer A; final wiring is the joint integration task (T6).
 */
final class ImageColorAnalyzer
{
    public function __construct(
        private readonly ImageLoaderInterface $loader,
        private readonly CropperInterface $cropper,
        private readonly ClustererInterface $clusterer,
        private readonly CoverageCalculatorInterface $coverage,
    ) {
    }

    /**
     * @param ImageSource|resource|string $source an ImageSource, a stream resource, or a file path
     * @return list<array{color:string,coverage_percent:float}>
     */
    public function analyze(mixed $source, ?AnalyzerOptions $options = null): array
    {
        $options ??= new AnalyzerOptions();

        $raster = $this->loader->load($this->normalizeSource($source));
        $cropped = $this->cropper->crop($raster, $options->crop)->raster;
        $clusters = $this->clusterer->cluster($cropped, $options->cluster);

        $result = [];
        foreach ($this->coverage->calculate($clusters) as $item) {
            $result[] = $item->toArray();
        }

        return $result;
    }

    /**
     * @param ImageSource|resource|string $source
     */
    public function analyzeAsJson(mixed $source, ?AnalyzerOptions $options = null): string
    {
        return (string) json_encode(
            $this->analyze($source, $options),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param ImageSource|resource|string $source
     */
    private function normalizeSource(mixed $source): ImageSource
    {
        if ($source instanceof ImageSource) {
            return $source;
        }
        if (is_string($source)) {
            return FileImageSource::fromPath($source);
        }
        if (is_resource($source)) {
            return FileImageSource::fromStream($source);
        }

        throw new InvalidArgumentException('Source must be an ImageSource, a stream resource, or a path string.');
    }
}
