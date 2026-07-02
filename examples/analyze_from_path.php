<?php

declare(strict_types=1);

use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

require __DIR__ . '/../vendor/autoload.php';

// Usage: php examples/analyze_from_path.php path/to/image.png
$path = $argv[1] ?? __DIR__ . '/../tests/Fixtures/real/sample.png';

$analyzer = AnalyzerFactory::createDefault();

echo $analyzer->analyzeAsJson($path) . PHP_EOL;
