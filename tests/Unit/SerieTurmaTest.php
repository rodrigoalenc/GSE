<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use src\Core\SerieTurma;

final class SerieTurmaTest extends TestCase
{
    public function testReconheceNomesClarosSemConfundirAnoLetivoComSerie(): void
    {
        require_once ROOT_PATH . '/src/Core/SerieTurma.php';
        foreach ([
            '1º Ano A - EF - TURMA A' => 1,
            '2º Ano B - EF - TURMA B' => 2,
            '3ª série A - Ensino Fundamental' => 3,
            '1º Ano - Ens. Fundamental' => 1,
            '2º Ano - Ens. Fundamental' => 2,
            '1º Ano - Ens. Médio' => 10,
            '2º Ano - Ens. Médio' => 11,
            'Ens. Fundamental - 1º Ano' => 1,
            'Ens. Médio - 1º Ano' => 10,
            '6º Ano B' => 6,
            '6ºAno B' => 6,
            '9 ANO A - 2026' => 9,
            'Primeiro Fundamental' => 1,
            'NONO FUNDAMENTAL' => 9,
            '1º Ano A - Ensino Médio' => 10,
            '2ª Série B - EM - matutino' => 11,
            '3º C - E.M.' => 12,
            'SEGUNDO EM - nome preservado' => 11,
            'TERCEIRO MÉDIO' => 12,
            'Ensino Médio - 2º Ano B' => 11,
            'Turma 1A - EM' => 10,
            'EM 1A' => 10,
            '2º Ano EM matutino' => 11,
            "  3º\u{00A0}ANO C - ENSINO ME\u{0301}DIO - 2026 " => 12,
        ] as $name => $expected) {
            self::assertSame($expected, SerieTurma::identificar($name), $name);
        }
    }

    public function testMantemNomesAmbiguosModalidadesEContradicoesParaRevisao(): void
    {
        require_once ROOT_PATH . '/src/Core/SerieTurma.php';
        foreach (['', '3º A', '1 Ano A', 'Turma A', 'Turma 2026', '2026 - EM',
            '4º Ano - EM', '1º Ano - EF - EM', '1º e 2º Ano - EF', '6º/7º Ano',
            '1º Ano - EJA - EM', '2ª Etapa - Ensino Médio', '3º ciclo EF',
            '2º período - Educação Infantil', 'Módulo 1 - EM', '10º Ano EF',
            'Sala 2 - EM', 'Turma 6', '1º semestre EM', '6º Ano Multisseriada', 'AEE 1º EF',
            '1º Ano em tempo integral', '3º Ano em tempo integral',
            '6º Ano Educação de Jovens e Adultos', '6º Ano E.J.A.',
            '6º Ano e 7ºB', '6º/7ºB',
            'EM 4º Ano', 'EM 6º Ano', '1º Ano - EF - EM matutino',
            '6º/7ºAno', '6º Ano e 7ºAno', '6º/10º Ano', '6º Ano e 10ºAno',
            '1º Ano - Ens.', 'Ens. - 1º Ano', '1º Ano - Ens. Fundamental e Ens. Médio',
        ] as $name) {
            self::assertNull(SerieTurma::identificar($name), $name);
        }
    }
}
