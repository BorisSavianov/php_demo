<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\Contracts;

use ImageColorAnalyzer\Contracts\ColorRGBA;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ColorRGBATest extends TestCase
{
    public function testToHexUppercasePadded(): void
    {
        self::assertSame('#FF0000', (new ColorRGBA(255, 0, 0))->toHex());
        self::assertSame('#0A0B0C', (new ColorRGBA(10, 11, 12))->toHex());
    }

    public function testTransparencyThreshold(): void
    {
        self::assertTrue((new ColorRGBA(0, 0, 0, 0))->isTransparent());
        self::assertFalse((new ColorRGBA(0, 0, 0, 255))->isTransparent());
    }

    public function testRejectsOutOfRangeChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColorRGBA(256, 0, 0);
    }
}
