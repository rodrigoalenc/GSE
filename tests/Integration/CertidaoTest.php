<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class CertidaoTest extends DatabaseTestCase
{
    public function testFluxosDeCertidao(): void
    {
        $this->pdo->exec("INSERT INTO lista_fornecedores (nome) VALUES ('Fornecedor A')");
        $this->pdo->exec("INSERT INTO lista_tipos_certidao (nome) VALUES ('Fiscal')");

        $certidao = new \Certidao();
        $vencimento = date('Y-m-d', strtotime('+15 days'));

        $this->assertTrue($certidao->cadastrar(1, 1, date('Y-m-d'), $vencimento, 'OK', 'arquivo.pdf'));
        $this->assertCount(1, $certidao->listarVigentes());
        $this->assertSame('Fornecedor A', $certidao->buscarPorId(1)['fornecedor'] ?? null);
        $this->assertCount(1, $certidao->buscarVencendoProximosDias(30));

        $this->assertTrue($certidao->atualizar(1, 1, 1, date('Y-m-d'), '2024-03-10', 'Atualizada', 'novo.pdf'));
        $this->assertTrue($certidao->alternarArquivo(1, 1));
        $this->assertCount(1, $certidao->listarPorAno('2024'));
        $this->assertContains('2024', $certidao->getAnosDisponiveis());

        $this->assertTrue($certidao->adicionarOpcaoLista('lista_fornecedores', 'Fornecedor B'));
        $this->assertTrue($certidao->atualizarOpcaoLista('lista_fornecedores', 2, 'Fornecedor B2'));
        $this->assertTrue($certidao->excluirOpcaoLista('lista_fornecedores', 2));
        $this->assertFalse($certidao->adicionarOpcaoLista('tabela_invalida', 'X'));
        $this->assertCount(1, $certidao->listarFornecedores());
        $this->assertSame(['Fiscal'], $certidao->listarTiposCertidao(true));

        $excluida = $certidao->excluir(1);
        $this->assertSame('novo.pdf', $excluida['arquivo_pdf'] ?? null);
    }
}
