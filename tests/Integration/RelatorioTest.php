<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class RelatorioTest extends DatabaseTestCase
{
    public function testFiltrosDoRelatorio(): void
    {
        $idTurma = $this->insertTurma('Turma Relatorio');
        $this->insertAlunoComDva('Aluno Vencido', date('Y-m-d', strtotime('-2 days')), $idTurma);
        $this->insertAlunoComDva('Aluno A Vencer', date('Y-m-d', strtotime('+5 days')), $idTurma);
        $this->insertAlunoComDva('Aluno Vigente', date('Y-m-d', strtotime('+60 days')), $idTurma);
        $this->pdo->prepare('INSERT INTO alunos (nome_completo, data_nascimento, id_turma) VALUES (?, ?, ?)')
            ->execute(['Aluno Sem DVA', '2010-01-01', $idTurma]);

        $relatorio = new \Relatorio();

        $this->assertCount(1, $relatorio->getTurmas());
        $this->assertCount(4, $relatorio->buscarDados($idTurma, ''));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'sem_dva'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'vencida'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'avencer'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'vigente'));
    }
}
