<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RetailImageSetMemoryTest extends TestCase
{
    /**
     * @var array<string, list<array{color: string, coverage_percent: float}>>
     */
    private const EXPECTED = [
        'Apache_Jeans_38x45.png' => [
            ['color' => '#000000', 'coverage_percent' => 52.3],
            ['color' => '#FEFEFE', 'coverage_percent' => 45.5],
            ['color' => '#797A7D', 'coverage_percent' => 2.2],
        ],
        'Boutique_Klamotte_35_30.png' => [
            ['color' => '#FFFFFF', 'coverage_percent' => 71.0],
            ['color' => '#020202', 'coverage_percent' => 27.0],
            ['color' => '#A1A1A1', 'coverage_percent' => 2.0],
        ],
        'Coffee_Station_32_33.png' => [
            ['color' => '#FFFFFF', 'coverage_percent' => 80.6],
            ['color' => '#020202', 'coverage_percent' => 13.4],
            ['color' => '#DE4445', 'coverage_percent' => 3.2],
            ['color' => '#8F9092', 'coverage_percent' => 1.8],
            ['color' => '#CAC1C2', 'coverage_percent' => 1.0],
        ],
        'Decathlon_50_55.png' => [
            ['color' => '#FFFFFF', 'coverage_percent' => 91.8],
            ['color' => '#0B0B0B', 'coverage_percent' => 8.2],
        ],
        'E-Wheels_Europe_45_50_All.png' => [
            ['color' => '#FFFFFF', 'coverage_percent' => 80.3],
            ['color' => '#131E28', 'coverage_percent' => 15.2],
            ['color' => '#76C6D7', 'coverage_percent' => 2.3],
            ['color' => '#70777D', 'coverage_percent' => 1.1],
            ['color' => '#BDCACF', 'coverage_percent' => 1.1],
        ],
        'STAR_BE_RESTAURANT_GERMANY_32_24.png' => [
            ['color' => '#FFFEFE', 'coverage_percent' => 83.1],
            ['color' => '#C75362', 'coverage_percent' => 10.9],
            ['color' => '#070707', 'coverage_percent' => 4.9],
            ['color' => '#9F9F9F', 'coverage_percent' => 1.1],
        ],
        'Wholefoods_38x41_.png' => [
            ['color' => '#FEFEFE', 'coverage_percent' => 83.2],
            ['color' => '#000000', 'coverage_percent' => 15.4],
            ['color' => '#5E5E5E', 'coverage_percent' => 1.4],
        ],
    ];

    public function testEveryRetailFixtureIsStableThroughBothApisAt128Megabytes(): void
    {
        foreach (self::EXPECTED as $filename => $expected) {
            $path = dirname(__DIR__) . '/Fixtures/real/retail/' . $filename;

            $fromPath = $this->analyzeInConstrainedProcess('path', $path);
            $fromHandle = $this->analyzeInConstrainedProcess('handle', $path);

            self::assertSame($expected, $fromPath, "Unexpected path result for {$filename}");
            self::assertSame($expected, $fromHandle, "Unexpected handle result for {$filename}");
            self::assertSame($fromPath, $fromHandle, "Public APIs diverged for {$filename}");
        }
    }

    /**
     * @return list<array{color: string, coverage_percent: float}>
     */
    private function analyzeInConstrainedProcess(string $mode, string $path): array
    {
        $helper = dirname(__DIR__) . '/Support/analyze_retail_fixture.php';
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', $helper, $mode, $path];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        self::assertIsResource($process, 'Unable to start constrained analyzer process.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stderr);
        self::assertSame(0, $exitCode, $stderr === '' ? 'Constrained analyzer process failed.' : $stderr);
        self::assertIsString($stdout);

        /** @var list<array{color: string, coverage_percent: float}> $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
