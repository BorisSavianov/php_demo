<?php

declare(strict_types=1);

use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

require __DIR__ . '/../vendor/autoload.php';

// Demonstrates the assignment's core requirement: accept a file handle.
$path = $argv[1] ?? __DIR__ . '/../tests/Fixtures/real/sample.jpg';
$handle = fopen($path, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Cannot open {$path}\n");
    exit(1);
}

$analyzer = AnalyzerFactory::createDefault();

echo $analyzer->analyzeAsJson($handle) . PHP_EOL;

fclose($handle);
