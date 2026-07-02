<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Unit\WhiteBackgroundCropper;

use PHPUnit\Framework\TestCase;

final class WhiteBackgroundCropperTest extends TestCase
{
    public function testCropsSymmetricWhiteBorder(): void
    {
        // TODO(B): SyntheticImageFactory::contentOnBorder(100, 100, 20, red)
        //          -> expect boundingBox (20, 20, 60, 60), wasCropped = true.
        self::markTestIncomplete('WhiteBackgroundCropper::crop() pending — Developer B.');
    }

    public function testKeepsInteriorWhite(): void
    {
        // TODO(B): white shape inside colored content must NOT be cropped away.
        self::markTestIncomplete('WhiteBackgroundCropper::crop() pending — Developer B.');
    }

    public function testHandlesNearWhiteAndAllWhite(): void
    {
        // TODO(B): near-white (250,250,250) border cropped within tolerance;
        //          fully white image returns wasCropped = false.
        self::markTestIncomplete('WhiteBackgroundCropper::crop() pending — Developer B.');
    }
}
