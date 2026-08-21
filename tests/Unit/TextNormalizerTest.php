<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use src\Core\TextNormalizer;

final class TextNormalizerTest extends TestCase
{
    public function testComparisonKeyUsesNfcUnicodeCaseAndCollapsedWhitespace(): void
    {
        $decomposed = "  A\u{0301}LVARO\t  SOUZA  ";

        $this->assertSame('álvaro souza', TextNormalizer::comparisonKey($decomposed));
        $this->assertSame(
            TextNormalizer::comparisonKey('João da Silva'),
            TextNormalizer::comparisonKey('JOÃO   DA SILVA')
        );
    }

    public function testDisplayNamePreservesCapitalizationAndAccentsInNfc(): void
    {
        $this->assertSame('Álvaro Souza', TextNormalizer::displayName("  A\u{0301}lvaro   Souza "));
    }
}
