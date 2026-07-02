<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\CoverageCalculator;

use ImageColorAnalyzer\Contracts\ClusterResult;
use ImageColorAnalyzer\Contracts\CoverageCalculatorInterface;
use ImageColorAnalyzer\Exception\NotImplementedException;

/**
 * OWNER: Developer C.
 *
 * Turns a {@see ClusterResult} into a sorted list of {@see \ImageColorAnalyzer\Contracts\ColorCoverage}.
 * Percentage = cluster weight / totalAnalyzedPixels * 100, normalized with the
 * largest-remainder method so displayed values sum to exactly 100.0.
 *
 * TODO(C): implement calculate().
 */
final class PercentageCoverageCalculator implements CoverageCalculatorInterface
{
    public function calculate(ClusterResult $result): array
    {
        throw new NotImplementedException('PercentageCoverageCalculator::calculate() pending — Developer C.');
    }
}
