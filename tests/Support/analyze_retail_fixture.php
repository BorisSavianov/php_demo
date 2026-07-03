<?php

declare(strict_types=1);

use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 3 || !in_array($arguments[1], ['path', 'handle'], true)) {
    fwrite(STDERR, "Usage: php analyze_retail_fixture.php <path|handle> <image>\n");
    exit(2);
}

$analyzer = AnalyzerFactory::createDefault();

if ($arguments[1] === 'path') {
    $result = $analyzer->analyzePath($arguments[2]);
} else {
    $handle = fopen($arguments[2], 'rb');
    if (!is_resource($handle)) {
        fwrite(STDERR, "Unable to open fixture: {$arguments[2]}\n");
        exit(2);
    }

    try {
        $result = $analyzer->analyze($handle);
    } finally {
        fclose($handle);
    }
}

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
