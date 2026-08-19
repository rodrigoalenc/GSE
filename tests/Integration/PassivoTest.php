<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PassivoTest extends DatabaseTestCase
{
    public function testFluxosDePassivo(): void
    {
        $passivo = new \Passivo();

        $id = $passivo->cadastrar('Jose Acentuado', '2000-01-01', '', 'A');
        $passivo->cadastrar('Ana Caixa', '2001-01-01', '7', 'A');
        $passivo->cadastrar('Bruno Caixa', '2002-01-01', '', 'B');

        $this->assertIsNumeric($id);
        $this->assertCount(3, $passivo->buscar());
        $this->assertCount(2, $passivo->buscar('', 'A'));
        $this->assertCount(1, $passivo->buscar('Jose'));
        $this->assertSame('Jose Acentuado', $passivo->buscarPorId($id)['nome_completo'] ?? null);
        $this->assertTrue($passivo->atualizar($id, 'Jose Atualizado', '2000-01-01', '', 'A'));
        $this->assertSame(['A', 'B'], $passivo->getListaCaixas());
        $this->assertCount(2, $passivo->getResumoCaixas());
        $this->assertSame(1, $passivo->enumerarCaixa('B'));
        $this->assertCount(2, $passivo->listarParaTxt('A'));
        $this->assertTrue($passivo->excluir($id));

        $csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'passivo_' . uniqid('', true) . '.csv';
        file_put_contents($csv, "nome;nascimento;numero;caixa\nCSV Um;2000-01-01;1;C\nCSV Dois;2001-02-02;2;C\n");

        try {
            $this->assertTrue($passivo->importarCSV($csv));
            $this->assertCount(2, $passivo->buscar('', 'C'));
        } finally {
            @unlink($csv);
        }
    }
}
