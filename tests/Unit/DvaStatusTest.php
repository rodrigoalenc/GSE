<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DvaStatusTest extends TestCase
{
    public function testClassificaTodasAsFronteirasComRegraCentral(): void
    {
        $status = new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30);

        $this->assertSame(\DvaStatus::SEM_DVA, $status->classify(null));
        $this->assertSame(\DvaStatus::VENCIDA, $status->classify('2026-08-19'));
        $this->assertSame(\DvaStatus::VENCE_HOJE, $status->classify('2026-08-20'));
        $this->assertSame(\DvaStatus::A_VENCER, $status->classify('2026-09-19'));
        $this->assertSame(\DvaStatus::VIGENTE, $status->classify('2026-09-20'));
        $this->assertSame(-1, $status->daysRemaining('2026-08-19'));
        $this->assertSame(30, $status->daysRemaining('2026-09-19'));
    }

    public function testConfiguracaoInvalidaRecuaParaPadraoSeguro(): void
    {
        $previous = $_ENV['DVA_WARNING_DAYS'] ?? null;
        $_ENV['DVA_WARNING_DAYS'] = 'invalido';
        putenv('DVA_WARNING_DAYS=invalido');

        $status = new \DvaStatus(new DateTimeImmutable('2026-08-20'));
        $this->assertSame('2026-09-19', $status->warningLimit());

        if ($previous === null) {
            unset($_ENV['DVA_WARNING_DAYS']);
            putenv('DVA_WARNING_DAYS');
        } else {
            $_ENV['DVA_WARNING_DAYS'] = $previous;
            putenv('DVA_WARNING_DAYS=' . $previous);
        }
    }
}
