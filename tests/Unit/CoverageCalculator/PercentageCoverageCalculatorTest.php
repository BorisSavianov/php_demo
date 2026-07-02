<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\CoverageCalculator;

use PHPUnit\Framework\TestCase;

final class PercentageCoverageCalculatorTest extends TestCase
{
    public function testPercentagesSumToOneHundred(): void
    {
        // TODO(C): sum of coverage_percent == 100.0 via largest-remainder rounding.
        self::markTestIncomplete('PercentageCoverageCalculator::calculate() pending — Developer C.');
    }

    public function testSortedDescendingByCoverage(): void
    {
        // TODO(C): result ordered from largest to smallest coverage.
        self::markTestIncomplete('PercentageCoverageCalculator::calculate() pending — Developer C.');
    }
}
