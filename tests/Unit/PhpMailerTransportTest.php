<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhpMailerTransportTest extends TestCase
{
    public function testMapeiaStartTlsSemConexaoReal(): void
    {
        $this->assertSame(
            ['secure' => PHPMailer::ENCRYPTION_STARTTLS, 'auto_tls' => true],
            \PhpMailerTransport::securitySettings('tls', 587, true)
        );
    }

    public function testMapeiaTlsImplicitoPelaConstanteDoPhpMailer(): void
    {
        $this->assertSame(
            ['secure' => PHPMailer::ENCRYPTION_SMTPS, 'auto_tls' => false],
            \PhpMailerTransport::securitySettings('smtps', 465, true)
        );
    }

    public function testNoneDesativaCriptografiaETlsAutomaticoForaDeProducao(): void
    {
        $this->assertSame(
            ['secure' => '', 'auto_tls' => false],
            \PhpMailerTransport::securitySettings('none', 1025, false)
        );
    }

    #[DataProvider('invalidSettings')]
    public function testRecusaValorInvalidoOuCombinacaoIncoerente(string $encryption, int $port, bool $production): void
    {
        $this->expectException(RuntimeException::class);
        \PhpMailerTransport::securitySettings($encryption, $port, $production);
    }

    /** @return iterable<string,array{string,int,bool}> */
    public static function invalidSettings(): iterable
    {
        yield 'valor arbitrario' => ['ssl', 465, false];
        yield 'none em producao' => ['none', 25, true];
        yield 'STARTTLS na porta implicita padrao' => ['tls', 465, true];
        yield 'SMTPS na porta STARTTLS padrao' => ['smtps', 587, true];
    }
}
