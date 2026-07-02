<?php

declare(strict_types=1);

namespace ImageColorAnalyzer\Tests\Integration;

use ImageColorAnalyzer\PublicAPI\AnalyzerFactory;
use ImageColorAnalyzer\PublicAPI\ImageColorAnalyzer;
use PHPUnit\Framework\TestCase;

final class EndToEndTest extends TestCase
{
    public function testFactoryWiresTheFacade(): void
    {
        self::assertInstanceOf(ImageColorAnalyzer::class, AnalyzerFactory::createDefault());
    }

    public function testAnalyzeSyntheticBandsFromHandle(): void
    {
        // TODO(all): once modules land, write a synthetic PNG of known bands to a
        // php://temp handle, run analyze(), and assert colors + sum ~= 100.
        self::markTestIncomplete('Full pipeline pending loader/cropper/clusterer/coverage.');
    }
}
