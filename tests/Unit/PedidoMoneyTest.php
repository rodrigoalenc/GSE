<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PedidoMoneyTest extends TestCase
{
    /** @return iterable<string,array{string,int}> */
    public static function validAmounts(): iterable
    {
        yield 'sem agrupamento' => ['1250,00', 125000];
        yield 'com agrupamento' => ['1.250,00', 125000];
        yield 'centavos exatos' => ['1.234.567,89', 123456789];
        yield 'uma casa decimal' => ['1.250,5', 125050];
        yield 'inteiro agrupado' => ['1.250', 125000];
        yield 'zero' => ['0', 0];
        yield 'centavo' => ['0,01', 1];
        yield 'limite atual agrupado' => ['999.999.999,99', 99999999999];
        yield 'limite atual simples' => ['999999999,99', 99999999999];
    }

    #[DataProvider('validAmounts')]
    public function testBrazilianAmountsBecomeExactIntegerCents(string $amount, int $expected): void
    {
        self::assertSame($expected, Contrato::cents($amount));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidAmounts(): iterable
    {
        foreach (['', '1.25', '12.50,00', '1.2500,00', '1,250', '1,250.00', '1.250,000',
            '-1,00', '+1,00', ' 1,00', '1,00 ', '01,00', '0.001,00', '1 250,00', 'R$ 1.250,00',
            '1000000000,00', '1.000.000.000,00', '99999999999999999999999,99', '1e3', 'NaN', "1,00\n"] as $amount) {
            yield $amount => [$amount];
        }
        yield 'float' => [1250.0];
        yield 'integer' => [1250];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    #[DataProvider('invalidAmounts')]
    public function testMalformedOrOutOfRangeAmountsAreRejected(mixed $amount): void
    {
        $this->expectException(DomainException::class);
        Contrato::cents($amount);
    }
}
