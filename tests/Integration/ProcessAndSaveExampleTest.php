<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ProcessAndSaveExampleTest extends TestCase
{
    public function testReportsProcessingFailuresAsConciseCliErrors(): void
    {
        $script = __DIR__ . '/../../examples/process_and_save.php';
        $missingInput = __DIR__ . '/missing-input.png';
        $outputPath = sys_get_temp_dir() . '/ica-missing-output-' . bin2hex(random_bytes(8)) . '.png';
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($missingInput),
            escapeshellarg($outputPath),
            '2>&1',
        ]);

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertCount(1, $output);
        self::assertStringStartsWith('Error: ', $output[0]);
        self::assertStringNotContainsString('Fatal error', $output[0]);
        self::assertFileDoesNotExist($outputPath);
    }
}
