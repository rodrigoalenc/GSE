<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';

final class ProgressaoTodasTurmasTest extends DatabaseTestCase
{
    private \MovimentacaoAlunos $service;
    private int $admin;
    private int $first;
    private int $second;
    private int $third;
    private int $a;
    private int $b;
    private int $retained;
    private int $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new \MovimentacaoAlunos();
        $this->admin = $this->insertUsuario();
        $this->first = $this->insertTurma('1º Ano A - EM');
        $this->second = $this->insertTurma('2º Ano A - EM');
        $this->third = $this->insertTurma('3º Ano A - EM');
        $this->a = $this->insertAlunoComDva('Aprovado primeiro', '2099-01-01', $this->first);
        $this->b = $this->insertAlunoComDva('Aprovado segundo', '2099-01-01', $this->second);
        $this->retained = $this->insertAlunoComDva('Reprovado primeiro', '2099-01-01', $this->first);
        $this->senior = $this->insertAlunoComDva('Reprovado terceiro', '2099-01-01', $this->third);
    }

    private function review(): void
    {
        $state = $this->service->conferencia(2027);
        $this->assertTrue($this->service->confirmarConferencia(2027, $state['fingerprint'], $this->admin));
    }

    /** @return array<int,int> */
    private function mapping(): array
    {
        return [$this->first => $this->second, $this->second => $this->third];
    }

    public function testPreviaGeralNaoAlteraDadosEConfirmacaoAvancaCadaAlunoSomenteUmaVez(): void
    {
        $this->review();
        $before = $this->pdo->query('SELECT * FROM alunos ORDER BY id')->fetchAll();
        $classes = $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll();
        $dvas = $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
        $plan = $this->service->previewTodas($this->mapping(), [$this->retained], 2027);
        $this->assertIsArray($plan);
        $this->assertSame(2, $plan['total']);
        $this->assertSame($before, $this->pdo->query('SELECT * FROM alunos ORDER BY id')->fetchAll());
        $result = $this->service->confirmarTodas($plan, $this->admin, str_repeat('a', 32));
        $this->assertIsInt($result);
        $this->assertSame($this->second, (int) (new \Aluno())->buscarPorId($this->a)['id_turma']);
        $this->assertSame($this->third, (int) (new \Aluno())->buscarPorId($this->b)['id_turma']);
        $this->assertSame($this->first, (int) (new \Aluno())->buscarPorId($this->retained)['id_turma']);
        $this->assertSame($this->third, (int) (new \Aluno())->buscarPorId($this->senior)['id_turma']);
        $this->assertCount(1, $this->service->historico($this->a));
        $this->assertSame($result, $this->service->confirmarTodas($plan, $this->admin, str_repeat('a', 32)));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_movimentacoes')->fetchColumn());
        $this->assertSame($classes, $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll());
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll());
        $options = $this->service->opcoesTodas(2027);
        $this->assertSame([$this->retained], array_merge(...array_map(static fn (array $group): array => array_column($group['alunos'], 'id'), $options['turmas'])));
    }

    public function testExigeConferenciaDestinoParaTodaTurmaComSelecionadosESerieExata(): void
    {
        $this->assertFalse($this->service->previewTodas($this->mapping(), [], 2027));
        $this->assertSame('review_required', $this->service->lastErrorCode());
        $this->review();
        $this->assertIsArray($this->service->previewTodas([], [], 2027), 'Destinos claros devem ser resolvidos sem mapa enviado pelo formulário.');
        foreach ([[$this->first => $this->third, $this->second => $this->third],
            [$this->first => $this->second, $this->second => $this->third, $this->third => $this->first]] as $mapping) {
            $this->assertFalse($this->service->previewTodas($mapping, [], 2027));
        }
        $this->assertFalse($this->service->previewTodas($this->mapping(), [999999], 2027));
        $this->assertIsArray($this->service->previewTodas([$this->second => $this->third], [$this->a, $this->retained], 2027));
        $this->assertFalse($this->service->previewTodas([], [$this->a, $this->b, $this->retained], 2027));
    }

    public function testAlteracaoDeQualquerTurmaOuAlunoAposPreviaNaoAplicaParteDaProgressao(): void
    {
        $this->review();
        $plan = $this->service->previewTodas($this->mapping(), [], 2027);
        $this->assertIsArray($plan);
        $this->insertAlunoComDva('Novo aluno', '2099-01-01', $this->second);
        $this->assertFalse($this->service->confirmarTodas($plan, $this->admin, str_repeat('b', 32)));
        $this->assertSame($this->first, (int) (new \Aluno())->buscarPorId($this->a)['id_turma']);
        $this->assertCount(0, $this->service->historico($this->a));
        $plan = $this->service->previewTodas($this->mapping(), [], 2027);
        $this->assertIsArray($plan);
        $this->pdo->exec('UPDATE alunos SET ativo=0 WHERE id=' . $this->b);
        $this->assertFalse($this->service->confirmarTodas($plan, $this->admin, str_repeat('c', 32)));
        $this->assertCount(0, $this->service->historico($this->a));
        $plan = $this->service->previewTodas($this->mapping(), [], 2027);
        $this->assertIsArray($plan);
        $this->assertTrue((new \Turma())->atualizar($this->third, '3º Ano B - EM', 2026, $this->admin));
        $this->assertFalse($this->service->confirmarTodas($plan, $this->admin, str_repeat('9', 32)));
        $this->assertCount(0, $this->service->historico($this->a));
    }

    public function testFalhaDeAuditoriaNaSegundaTurmaDesfazPrimeiraTurmaTambem(): void
    {
        $this->review();
        $plan = $this->service->previewTodas($this->mapping(), [], 2027);
        $this->assertIsArray($plan);
        $this->pdo->exec("CREATE TRIGGER fail_bulk_audit BEFORE INSERT ON security_audit
            WHEN NEW.action='student.progressed' AND NEW.resource_id=" . $this->b . " BEGIN SELECT RAISE(ABORT,'audit unavailable'); END");
        $this->assertFalse($this->service->confirmarTodas($plan, $this->admin, str_repeat('d', 32)));
        $this->assertSame($this->first, (int) (new \Aluno())->buscarPorId($this->a)['id_turma']);
        $this->assertSame($this->second, (int) (new \Aluno())->buscarPorId($this->b)['id_turma']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_movimentacao_lotes')->fetchColumn());
    }

    public function testPermiteMaisDeDuzentosAlunosSemLimitarSilenciosamenteETemAutorizacao(): void
    {
        $this->review();
        for ($i = 0; $i < 201; $i++) {
            $this->insertAlunoComDva('Aluno adicional ' . $i, '2099-01-01', $this->first);
        }
        $plan = $this->service->previewTodas($this->mapping(), [], 2027);
        $this->assertIsArray($plan);
        $this->assertSame(204, $plan['total']);
        $employee = $this->insertUsuario('Funcionario', 'funcionario');
        $this->assertFalse($this->service->confirmarTodas($plan, $employee, str_repeat('e', 32)));
        $this->assertIsInt($this->service->confirmarTodas($plan, $this->admin, str_repeat('f', 32)));
        $this->assertSame(204, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_movimentacoes')->fetchColumn());
    }

    public function testResolveCorrespondenciaAParaAMesmoComOutrasTurmasNaSerieSeguinte(): void
    {
        $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
        $this->assertSame($this->second, $groups[$this->first]['sugerido']);
        $alternative = $this->insertTurma('2º Ano B - EM');
        $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
        $this->assertCount(2, $groups[$this->first]['destinos']);
        $this->assertContains($alternative, array_column($groups[$this->first]['destinos'], 'id'));
        $this->assertSame($this->second, $groups[$this->first]['sugerido']);
        $this->assertArrayNotHasKey($this->third, $groups);
    }

    public function testProgrideTodasSemEnviarDestinosEMantemAParaABParaB(): void
    {
        $firstB = $this->insertTurma('1ª Série B - Ensino Médio');
        $secondB = $this->insertTurma('2º Ano B - EM');
        $studentB = $this->insertAlunoComDva('Aprovado B', '2099-01-01', $firstB);
        $this->review();
        $plan = $this->service->previewTodas([], [$this->retained], 2027);
        $this->assertIsArray($plan);
        $this->assertSame($this->second, $plan['destinos'][$this->first]);
        $this->assertSame($secondB, $plan['destinos'][$firstB]);
        $this->assertIsInt($this->service->confirmarTodas($plan, $this->admin, str_repeat('7', 32)));
        $this->assertSame($this->second, (int) (new \Aluno())->buscarPorId($this->a)['id_turma']);
        $this->assertSame($secondB, (int) (new \Aluno())->buscarPorId($studentB)['id_turma']);
    }

    public function testReconheceSiglasOrdinaisETurnosEquivalentesSemMisturarTurnos(): void
    {
        $this->assertTrue((new \Turma())->atualizar($this->first, 'Turma 1A - EM - Manhã', 2026, $this->admin));
        $this->assertTrue((new \Turma())->atualizar($this->second, 'SEGUNDA SÉRIE A - Ensino Médio - Matutino', 2026, $this->admin));
        $this->assertIsInt((new \Turma())->cadastrar('2º Ano A - EM - Vespertino', 2026, $this->admin));
        $this->assertIsInt((new \Turma())->cadastrar('2º Ano A - EM', 2026, $this->admin));
        $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
        $this->assertSame($this->second, $groups[$this->first]['sugerido']);
    }

    public function testSomenteAmbiguidadeRealExigeEscolhaEOrigemTotalmenteRetidaNaoExigeDestino(): void
    {
        $this->assertTrue((new \Turma())->atualizar($this->second, '2º Ano B - EM', 2026, $this->admin));
        $morning = $this->insertTurma('2º Ano A - EM - Matutino');
        $this->insertTurma('2º Ano A - EM - Vespertino');
        $this->review();
        $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
        $this->assertSame(0, $groups[$this->first]['sugerido']);
        $this->assertFalse($this->service->previewTodas([], [], 2027));
        $this->assertSame('invalid_bulk_destination', $this->service->lastErrorCode());
        $this->assertIsArray($this->service->previewTodas([$this->first => $morning], [], 2027));
        $this->assertIsArray($this->service->previewTodas([], [$this->a, $this->retained], 2027));
    }

    public function testUsaTurmasDoAnoDeReferenciaSemEscolherUmaTurmaHistoricaHomonima(): void
    {
        foreach ([$this->first => '1º Ano A - EM', $this->second => '2º Ano A - EM', $this->third => '3º Ano A - EM'] as $id => $name) {
            $this->assertTrue((new \Turma())->atualizar($id, $name, 2026, $this->admin));
        }
        $nextSecond = (new \Turma())->cadastrar('2º Ano A - EM', 2027, $this->admin);
        $nextThird = (new \Turma())->cadastrar('3º Ano A - EM', 2027, $this->admin);
        $this->assertIsInt($nextSecond);
        $this->assertIsInt($nextThird);
        $this->review();
        $plan = $this->service->previewTodas([], [], 2027);
        $this->assertIsArray($plan);
        $this->assertSame($nextSecond, $plan['destinos'][$this->first]);
        $this->assertSame($nextThird, $plan['destinos'][$this->second]);
    }

    public function testTransicaoDoNonoParaPrimeiroMedioMantemIdentificacaoDaTurma(): void
    {
        $this->assertTrue((new \Turma())->atualizar($this->first, '9º Ano A - EF - TURMA A', 2026, $this->admin));
        $nextA = $this->insertTurma('1ª Série A - Ensino Médio');
        $this->insertTurma('1º Ano B - EM');
        $this->review();
        $plan = $this->service->previewTodas([], [], 2027);
        $this->assertIsArray($plan);
        $this->assertSame($nextA, $plan['destinos'][$this->first]);
    }

    public function testProgressaoDeUmaTurmaTambemResolveDestinoSemCampoEnviado(): void
    {
        $this->insertTurma('2º Ano B - EM');
        $this->review();
        $plan = $this->service->preview([$this->a], 0, 'progressao', 2027);
        $this->assertIsArray($plan);
        $this->assertSame($this->second, $plan['destino']['id']);
        $this->assertIsInt($this->service->confirmar($plan, $this->admin, str_repeat('6', 32)));
        $this->assertSame($this->second, (int) (new \Aluno())->buscarPorId($this->a)['id_turma']);
    }

    public function testNaoUsaAutomaticamenteDestinoDeAnoHistoricoSemCorrespondencia(): void
    {
        $this->assertTrue((new \Turma())->atualizar($this->first, '1º Ano A - EM', 2026, $this->admin));
        $this->assertTrue((new \Turma())->atualizar($this->second, '2º Ano A - EM', 2024, $this->admin));
        $this->review();
        $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
        $this->assertSame(0, $groups[$this->first]['sugerido']);
        $this->assertFalse($this->service->previewTodas([], [], 2027));
        $this->assertIsArray($this->service->previewTodas($this->mapping(), [], 2027), 'A decisão excepcional deve continuar disponível ao administrador.');
    }

    public function testOrdinalColadoAPalavraAnoOuSerieNaoImpedeCorrespondenciaAutomatica(): void
    {
        $this->insertTurma('2º Ano B - EM');
        foreach (['1ºAno A - EM', '1ªSérie A - Ensino Médio'] as $name) {
            $this->assertTrue((new \Turma())->atualizar($this->first, $name, 2026, $this->admin));
            $groups = array_column($this->service->opcoesTodas(2027)['turmas'], null, 'origem_id');
            $this->assertSame($this->second, $groups[$this->first]['sugerido'], $name);
        }
    }
}
