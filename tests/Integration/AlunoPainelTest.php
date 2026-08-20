<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

final class AlunoPainelTest extends DatabaseTestCase
{
    public function testFluxoCompletoPreservaHistoricoSemExclusaoFisica(): void
    {
        $classId = $this->insertTurma();
        $actorId = $this->insertUsuario();
        $student = new \Aluno();
        $studentId = $student->cadastrar(
            [
                'nome_completo' => '  Joao   Souza  ',
                'data_nascimento' => '2010-05-10',
                'id_turma' => $classId,
                'telefone_aluno' => '(11) 99999-9999',
                'telefone_responsavel' => '(11) 3888-8888',
            ],
            $actorId,
            ['data_vencimento' => '2099-12-31', 'observacao' => 'Inicial']
        );

        $this->assertIsInt($studentId);
        $saved = $student->buscarPorId($studentId);
        $this->assertSame('Joao Souza', $saved['nome_completo']);
        $this->assertSame('11999999999', $saved['telefone_aluno']);
        $this->assertSame('1138888888', $saved['telefone_responsavel']);
        $this->assertTrue($student->possivelDuplicidade('joao souza', '2010-05-10'));

        $this->assertTrue($student->atualizar($studentId, [
            'nome_completo' => 'Joao Atualizado',
            'data_nascimento' => '2010-05-10',
            'id_turma' => $classId,
            'telefone_aluno' => '11911112222',
            'telefone_responsavel' => '',
        ]));

        $renewal = (new \Dva())->registrar($studentId, '2100-01-01', 'Renovada', $actorId);
        $this->assertIsArray($renewal);
        $this->assertTrue($renewal['renewed']);
        $history = (new \Dva())->historicoDoAluno($studentId);
        $this->assertCount(2, $history);
        $this->assertSame(1, array_sum(array_map(static fn (array $row): int => (int) $row['ativo'], $history)));

        $this->assertTrue($student->definirAtivo($studentId, false, $actorId));
        $this->assertSame(0, (int) $student->buscarPorId($studentId)['ativo']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());

        $this->expectException(\PDOException::class);
        $this->pdo->exec('DELETE FROM alunos WHERE id = ' . $studentId);
    }

    public function testPaginacaoFiltrosSemaforoEContagensDoPainel(): void
    {
        $classId = $this->insertTurma('Turma Filtro');
        $actorId = $this->insertUsuario('Ator Painel');
        $student = new \Aluno();
        $today = new DateTimeImmutable('2026-08-20');
        $status = new \DvaStatus($today, 30);

        $create = function (string $name, ?string $expiration) use ($student, $classId, $actorId): int {
            $id = $student->cadastrar([
                'nome_completo' => $name,
                'data_nascimento' => '2010-08-20',
                'id_turma' => $classId,
                'telefone_aluno' => '',
                'telefone_responsavel' => '',
            ], $actorId, $expiration === null ? null : ['data_vencimento' => $expiration, 'observacao' => null]);
            self::assertIsInt($id);

            return $id;
        };

        $create('Sem % DVA', null);
        $create('Vencida', '2026-08-19');
        $create('Hoje', '2026-08-20');
        $create('Limite', '2026-09-19');
        $create('Vigente', '2026-09-20');
        $inactive = $create('Inativo', '2026-08-19');
        $student->definirAtivo($inactive, false, $actorId);

        $this->assertSame(1, $student->paginate(['q' => '%', 'ativo' => '1'], 1, 10, $status)['total']);
        $this->assertSame(1, $student->paginate(['dva' => 'sem_dva', 'ativo' => '1'], 1, 10, $status)['total']);
        $this->assertSame(1, $student->paginate(['dva' => 'vencida', 'ativo' => '1'], 1, 10, $status)['total']);
        $this->assertSame(1, $student->paginate(['dva' => 'vence_hoje', 'ativo' => '1'], 1, 10, $status)['total']);
        $this->assertSame(1, $student->paginate(['dva' => 'a_vencer', 'ativo' => '1'], 1, 10, $status)['total']);
        $this->assertSame(1, $student->paginate(['dva' => 'vigente', 'ativo' => '1'], 1, 10, $status)['total']);

        $summary = (new \Painel())->resumo($status);
        $this->assertSame(5, $summary['alunos_ativos']);
        $this->assertSame(1, $summary['alunos_inativos']);
        $this->assertSame(1, $summary['vencidas']);
        $this->assertCount(5, $student->aniversariantesDoDia($today));
        $this->assertLessThanOrEqual(3, count((new \Painel())->pendenciasPrioritarias(3, $status)));
    }
}
