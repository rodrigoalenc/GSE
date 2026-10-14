<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        $this->assertSame(
            'Álvaro Souza',
            TextNormalizer::displayName("\u{00A0}Álvaro\u{2003}\u{2003}Souza\u{00A0}")
        );
    }

    public function testSearchKeyRemovesDiacriticsWithoutChangingComparisonKeySemantics(): void
    {
        $this->assertSame('jose alvares', TextNormalizer::searchKey(" JOSE\u{0301}  \u{00C1}lvares "));
        $this->assertSame("jos\u{00E9}", TextNormalizer::comparisonKey("Jos\u{00E9}"));
        $this->assertNotSame(TextNormalizer::comparisonKey("Jos\u{00E9}"), TextNormalizer::searchKey("Jos\u{00E9}"));
    }

    public function testInvalidUtf8FailsSafely(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');

        TextNormalizer::comparisonKey("Nome \xC3\x28");
    }
}
