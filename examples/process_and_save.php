<?php

declare(strict_types=1);

use ImageColorAnalyzer\Exception\ImageAnalyzerException;
use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

require __DIR__ . '/../vendor/autoload.php';

// Usage: php examples/process_and_save.php input.jpg output.png [--overwrite]
$input = $argv[1] ?? null;
$output = $argv[2] ?? null;
if ($input === null || $output === null) {
    fwrite(STDERR, "Usage: php examples/process_and_save.php <input> <output.png> [--overwrite]\n");
    exit(1);
}

try {
    $result = AnalyzerFactory::createDefault()->processPath($input);
    $result->croppedImage->saveTo($output, overwrite: in_array('--overwrite', $argv, true));
} catch (ImageAnalyzerException $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo $result->json . PHP_EOL;
