<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class AlunoPainelTest extends DatabaseTestCase
{
    public function testFluxosDeAlunoEPainel(): void
    {
        $idTurma = $this->insertTurma();
        $_SESSION['usuario_id'] = $this->insertUsuario();

        $aluno = new \Aluno();
        $idAluno = $aluno->cadastrar('Joao Souza', '2010-05-10', $idTurma, '2099-12-31', '11999999999', '11888888888');

        $this->assertIsNumeric($idAluno);
        $this->assertSame(1, (int) $aluno->contarTotal());
        $this->assertSame(1, (int) $aluno->contarTotal('Joao'));
        $this->assertCount(1, $aluno->listar(10, 0));
        $this->assertSame('Joao Souza', $aluno->buscarPorId($idAluno)['nome_completo'] ?? null);
        $this->assertNotFalse($aluno->existeAluno('Joao Souza', '2010-05-10'));

        $this->assertTrue($aluno->atualizar($idAluno, 'Joao Atualizado', '2010-05-10', $idTurma, '2100-01-01', '111', '222'));
        $this->assertSame('Joao Atualizado', $aluno->buscarPorId($idAluno)['nome_completo'] ?? null);
        $this->assertCount(0, $aluno->listarSemDva());

        $idSemDva = $aluno->cadastrar('Ana Sem DVA', '2011-06-12', $idTurma, '', '333', '');
        $this->assertCount(1, $aluno->listarSemDva());
        $this->assertCount(1, $aluno->getAniversariantesDoMes('05'));
        $this->assertCount(1, $aluno->getAniversariantesHoje('10', '05'));

        $painel = new \Painel();
        $this->assertSame(2, (int) $painel->getTotalAlunos());
        $this->assertSame(1, (int) $painel->getTotalSemDva());
        $this->assertCount(1, $painel->getListaAlunosSemDva());

        $this->insertAlunoComDva('DVA Vencida', date('Y-m-d', strtotime('-1 day')), $idTurma);
        $this->insertAlunoComDva('DVA A Vencer', date('Y-m-d', strtotime('+10 days')), $idTurma);
        $this->insertAlunoComDva('DVA Vigente', date('Y-m-d', strtotime('+60 days')), $idTurma);

        $this->assertCount(1, $painel->getDvasVencidas());
        $this->assertCount(1, $painel->getDvasAVencer());
        $this->assertGreaterThanOrEqual(2, count($painel->getDvasVigentes()));

        $this->assertSame('Ana Sem DVA', $aluno->excluir($idSemDva));
        $this->assertFalse($aluno->excluir(999999));
    }
}
