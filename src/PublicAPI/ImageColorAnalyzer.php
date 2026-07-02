<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\PublicAPI;

use ImageColorAnalyzer\Contracts\ClustererInterface;
use ImageColorAnalyzer\Contracts\CoverageCalculatorInterface;
use ImageColorAnalyzer\Contracts\CropperInterface;
use ImageColorAnalyzer\Contracts\ImageLoaderInterface;
use ImageColorAnalyzer\ImageLoader\SourceResolver;
use ImageColorAnalyzer\Options\AnalyzerOptions;

/**
 * Public entry point. Wires Loader -> Cropper -> Clusterer -> Coverage.
 * OWNER: skeleton by Developer A; final wiring is the joint integration task (T6).
 */
final class ImageColorAnalyzer
{
    private readonly SourceResolver $sourceResolver;

    public function __construct(
        private readonly ImageLoaderInterface $loader,
        private readonly CropperInterface $cropper,
        private readonly ClustererInterface $clusterer,
        private readonly CoverageCalculatorInterface $coverage,
        ?SourceResolver $sourceResolver = null,
    ) {
        $this->sourceResolver = $sourceResolver ?? new SourceResolver();
    }

    /**
     * @param mixed $source ImageSource, stream resource, raw image bytes, or GD image
     * @return list<array{color:string,coverage_percent:float}>
     */
    public function analyze(mixed $source, ?AnalyzerOptions $options = null): array
    {
        $options ??= new AnalyzerOptions();

        $raster = $this->loader->load($this->sourceResolver->resolve($source));
        $cropped = $this->cropper->crop($raster, $options->crop)->raster;
        $clusters = $this->clusterer->cluster($cropped, $options->cluster);

        $result = [];
        foreach ($this->coverage->calculate($clusters) as $item) {
            $result[] = $item->toArray();
        }

        return $result;
    }

    /**
     * @return list<array{color:string,coverage_percent:float}>
     */
    public function analyzePath(string $path, ?AnalyzerOptions $options = null): array
    {
        return $this->analyze($this->sourceResolver->resolvePath($path), $options);
    }

    /**
     * @param mixed $source ImageSource, stream resource, raw image bytes, or GD image
     */
    public function analyzeAsJson(mixed $source, ?AnalyzerOptions $options = null): string
    {
        return (string) json_encode(
            $this->analyze($source, $options),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function analyzePathAsJson(string $path, ?AnalyzerOptions $options = null): string
    {
        return (string) json_encode(
            $this->analyzePath($path, $options),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        );
    }
}
