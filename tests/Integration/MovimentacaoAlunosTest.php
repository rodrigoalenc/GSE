<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

if (is_file(ROOT_PATH . '/src/Services/MovimentacaoAlunos.php')) {
    require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';
}

final class MovimentacaoAlunosTest extends DatabaseTestCase
{
    private \MovimentacaoAlunos $service;
    private int $admin;
    private int $source;
    private int $destination;
    private int $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(\MovimentacaoAlunos::class), 'O fluxo de movimentação ainda não existe.');
        $this->service = new \MovimentacaoAlunos();
        $this->admin = $this->insertUsuario();
        $this->source = $this->insertTurma('SEGUNDO EM - nome preservado');
        $this->destination = $this->insertTurma('TERCEIRO EM - nome preservado');
        $this->senior = $this->insertAlunoComDva('Reprovado terceiro', '2099-01-01', $this->destination);
        $this->assertTrue($this->service->definirSeries([$this->source => '11', $this->destination => '12'], $this->admin));
    }

    private function review(): void
    {
        $state = $this->service->conferencia(2027);
        $this->assertTrue($this->service->confirmarConferencia(2027, $state['fingerprint'], $this->admin));
    }

    public function testBloqueiaProgressaoAteConferirTerceiroMasPermiteRegressao(): void
    {
        $id = $this->insertAlunoComDva('Aluno segundo', '2099-01-01', $this->source);
        $this->assertFalse($this->service->preview([$id], $this->destination, 'progressao', 2027));
        $this->assertSame('review_required', $this->service->lastErrorCode());
        $this->assertIsArray($this->service->preview([$this->senior], $this->source, 'regressao', 2027));
    }

    public function testArquivaConcluintesMantemReprovadoEProgrideSomenteSelecionados(): void
    {
        $graduate = $this->insertAlunoComDva('Concluinte terceiro', '2099-01-01', $this->destination);
        $passive = new \Passivo();
        $archive = $passive->previewArquivamentoLote([$graduate], 'CAIXA CONCLUINTES', 'nova');
        $this->assertIsArray($archive);
        $this->assertIsArray($passive->arquivarAlunosLote([$graduate], $archive['caixa'], 'nova', $archive['fingerprint'], $this->admin));
        $this->review();
        $id = $this->insertAlunoComDva('Aprovado segundo', '2099-01-01', $this->source);
        $retained = $this->insertAlunoComDva('Reprovado segundo', '2099-01-01', $this->source);
        $classes = $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll();
        $dvas = $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
        $plan = $this->service->preview([$id], $this->destination, 'progressao', 2027);
        $this->assertIsArray($plan);
        $batch = $this->service->confirmar($plan, $this->admin, str_repeat('a', 32));
        $this->assertIsInt($batch);
        $this->assertSame($this->destination, (int) (new \Aluno())->buscarPorId($id)['id_turma']);
        $this->assertSame($this->source, (int) (new \Aluno())->buscarPorId($retained)['id_turma']);
        $this->assertSame(1, (int) (new \Aluno())->buscarPorId($this->senior)['ativo']);
        $this->assertSame(0, (int) (new \Aluno())->buscarPorId($graduate)['ativo']);
        $this->assertSame($classes, $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll());
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll());
        $this->assertTrue($this->service->conferencia(2027)['liberada'], 'Novos alunos progredidos ao terceiro não reabrem a conferência.');
        $history = $this->service->historico($id);
        $this->assertCount(1, $history);
        $this->assertSame('SEGUNDO EM - nome preservado', $history[0]['origem_nome']);
        $this->assertSame('TERCEIRO EM - nome preservado', $history[0]['destino_nome']);
        $this->assertSame($batch, $this->service->confirmar($plan, $this->admin, str_repeat('a', 32)));
        $this->assertCount(1, $this->service->historico($id));
    }

    public function testNovaPessoaNoTerceiroExigeNovaConferenciaEAnoSeguinteTambem(): void
    {
        $this->review();
        $id = $this->insertAlunoComDva('Novo terceiro', '2099-01-01', $this->destination);
        $this->assertFalse($this->service->conferencia(2027)['liberada']);
        $this->assertFalse($this->service->conferencia(2028)['liberada']);
        $this->assertContains($id, array_column($this->service->conferencia(2027)['alunos'], 'id'));
    }

    public function testRejeitaPreviaAntigaDaConferencia(): void
    {
        $state = $this->service->conferencia(2027);
        $this->insertAlunoComDva('Outro terceiro', '2099-01-01', $this->destination);
        $this->assertFalse($this->service->confirmarConferencia(2027, $state['fingerprint'], $this->admin));
        $this->assertSame('preview_changed', $this->service->lastErrorCode());
    }

    public function testLimitaUmaSerieValidaTurmaAtivaESelecao(): void
    {
        $this->review();
        $first = $this->insertTurma('PRIMEIRO EM');
        $this->assertTrue($this->service->definirSeries([$first => '10'], $this->admin));
        $id = $this->insertAlunoComDva('Aluno primeiro', '2099-01-01', $first);
        foreach ([[], [$id, $id], [999999], ['x'], [[$id]]] as $ids) {
            $this->assertFalse($this->service->preview($ids, $this->source, 'progressao', 2027));
        }
        $this->assertFalse($this->service->preview([$id], $this->destination, 'progressao', 2027));
        $this->assertFalse($this->service->preview([$id], $first, 'progressao', 2027));
        $this->assertFalse($this->service->preview([$id], $this->source, 'invalida', 2027));
        $this->assertFalse($this->service->preview([$id], $this->source, 'progressao', 0));
        $this->pdo->exec('UPDATE turmas SET ativo=0 WHERE id=' . $this->source);
        $this->assertFalse($this->service->preview([$id], $this->source, 'progressao', 2027));
        $this->assertCount(0, $this->service->historico($id));
    }

    public function testNaoAvancaDuasVezesNoCicloMasPermiteCorrigirRegredirEProgredir(): void
    {
        $first = $this->insertTurma('PRIMEIRO EM');
        $this->service->definirSeries([$first => '10'], $this->admin);
        $this->review();
        $id = $this->insertAlunoComDva('Aluno primeiro', '2099-01-01', $first);
        $plan = $this->service->preview([$id], $this->source, 'progressao', 2027);
        $this->assertIsInt($this->service->confirmar($plan, $this->admin, str_repeat('b', 32)));
        $this->assertFalse($this->service->preview([$id], $this->destination, 'progressao', 2027));
        $this->assertSame('already_progressed', $this->service->lastErrorCode());
        $back = $this->service->preview([$id], $first, 'regressao', 2027);
        $this->assertIsInt($this->service->confirmar($back, $this->admin, str_repeat('c', 32)));
        $this->assertIsArray($this->service->preview([$id], $this->source, 'progressao', 2027));
    }

    public function testPreviaAlteradaOuAlunoInativoNaoMovimentaNinguemDoLote(): void
    {
        $this->review();
        $a = $this->insertAlunoComDva('Aluno A', '2099-01-01', $this->source);
        $b = $this->insertAlunoComDva('Aluno B', '2099-01-01', $this->source);
        $plan = $this->service->preview([$a, $b], $this->destination, 'progressao', 2027);
        $this->pdo->exec('UPDATE alunos SET ativo=0 WHERE id=' . $b);
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('d', 32)));
        $this->assertSame($this->source, (int) (new \Aluno())->buscarPorId($a)['id_turma']);
        $this->assertCount(0, $this->service->historico($a));
    }

    public function testFalhaDeAuditoriaDesfazTodoLote(): void
    {
        $this->review();
        $id = $this->insertAlunoComDva('Aluno A', '2099-01-01', $this->source);
        $plan = $this->service->preview([$id], $this->destination, 'progressao', 2027);
        $this->pdo->exec("CREATE TRIGGER fail_movement_audit BEFORE INSERT ON security_audit
            WHEN NEW.action='student.progressed' BEGIN SELECT RAISE(ABORT,'audit unavailable'); END");
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('e', 32)));
        $this->assertSame($this->source, (int) (new \Aluno())->buscarPorId($id)['id_turma']);
        $this->assertCount(0, $this->service->historico($id));
    }

    public function testFuncionarioNaoConfiguraConfereOuMovimentaNemComPreviaValida(): void
    {
        $this->review();
        $employee = $this->insertUsuario('Funcionario', 'funcionario');
        $id = $this->insertAlunoComDva('Aluno A', '2099-01-01', $this->source);
        $plan = $this->service->preview([$id], $this->destination, 'progressao', 2027);
        $this->assertFalse($this->service->definirSeries([$this->source => '12'], $employee));
        $this->assertFalse($this->service->confirmarConferencia(2027, $this->service->conferencia(2027)['fingerprint'], $employee));
        $this->assertFalse($this->service->confirmar($plan, $employee, str_repeat('f', 32)));
        $this->assertCount(0, $this->service->historico($id));
    }

    public function testTurmasSemSerieNaoSaoAdivinhadasEMigracaoPreservaDados(): void
    {
        $unknown = $this->insertTurma('3º A nome ambiguo');
        $id = $this->insertAlunoComDva('Sem classificação', '2099-01-01', $unknown);
        $this->assertFalse($this->service->confirmarConferencia(2027, $this->service->conferencia(2027)['fingerprint'], $this->admin));
        $this->assertSame('unclassified_classes', $this->service->lastErrorCode());
        $this->assertFalse($this->service->preview([$id], $this->destination, 'regressao', 2027));
        $this->assertSame('3º A nome ambiguo', (new \Turma())->buscarPorId($unknown)['nome_turma']);
        $this->assertTrue($this->service->definirSeries([$unknown => '11'], $this->admin));
        $this->review();
        \src\Core\DatabaseInitializer::initialize($this->pdo);
        $this->assertTrue($this->service->conferencia(2027)['liberada']);
        $this->assertEmpty($this->pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testTransicaoDoFundamentalAoMedioELimitesInicialEFinal(): void
    {
        $first = $this->insertTurma('PRIMEIRO FUNDAMENTAL');
        $ninth = $this->insertTurma('NONO FUNDAMENTAL');
        $high = $this->insertTurma('PRIMEIRO MEDIO');
        $this->service->definirSeries([$first => '1', $ninth => '9', $high => '10'], $this->admin);
        $beginner = $this->insertAlunoComDva('Aluno inicial', '2099-01-01', $first);
        $transition = $this->insertAlunoComDva('Aluno transicao', '2099-01-01', $ninth);
        $this->review();
        $this->assertFalse($this->service->preview([$beginner], $ninth, 'regressao', 2027));
        $this->assertFalse($this->service->preview([$this->senior], $this->source, 'progressao', 2027));
        $plan = $this->service->preview([$transition], $high, 'progressao', 2027);
        $this->assertIsArray($plan);
        $this->assertIsInt($this->service->confirmar($plan, $this->admin, str_repeat('8', 32)));
        $this->assertSame($high, (int) (new \Aluno())->buscarPorId($transition)['id_turma']);
    }

    public function testAlterarIdentificacaoDaSerieAposPreviaExigeNovaPrevia(): void
    {
        $first = $this->insertTurma('PRIMEIRO MEDIO');
        $this->service->definirSeries([$first => '10'], $this->admin);
        $this->review();
        $id = $this->insertAlunoComDva('Aluno alteracao', '2099-01-01', $first);
        $plan = $this->service->preview([$id], $this->source, 'progressao', 2027);
        $this->assertIsArray($plan);
        $this->assertTrue($this->service->definirSeries([$this->source => '12'], $this->admin));
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('9', 32)));
        $this->assertSame($first, (int) (new \Aluno())->buscarPorId($id)['id_turma']);
        $this->assertCount(0, $this->service->historico($id));
    }

    public function testTelaNaoAfirmaAusenciaDeAlunosQuandoSerieAindaNaoFoiIdentificada(): void
    {
        $this->assertTrue((new \Turma())->atualizar($this->destination, 'Turma sem indicação de série', 2026, $this->admin));
        $this->assertTrue($this->service->definirSeries([$this->destination => ''], $this->admin));
        $year = 2027;
        $state = $this->service->conferencia($year);
        $this->assertSame([], $state['alunos']);
        $this->assertGreaterThan(0, $state['pendentes']);
        $classes = $this->service->turmas();
        $series = \MovimentacaoAlunos::series();
        $configure = false;
        $preview = null;
        $previewToken = '';
        $students = [];
        $classId = 0;
        $search = '';
        $type = 'progressao';
        $archiveClassId = $this->destination;
        $archiveStudents = $this->service->alunos($archiveClassId);
        $archiveAfter = false;
        $_GET = [];
        ob_start();
        try {
            require ROOT_PATH . '/src/Views/alunos/movimentar.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringNotContainsString('Não há alunos ativos do 3º ano', $html);
        $this->assertStringNotContainsString('name="passivo_turma"', $html);
        $this->assertStringContainsString('Identifique as séries antes de concluir a conferência', $html);
        $this->assertStringNotContainsString('action="' . \url('aluno/movimentar/conferencia') . '"', $html);
        $type = 'passivo';
        ob_start();
        try {
            require ROOT_PATH . '/src/Views/alunos/movimentar.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('name="passivo_turma"', $html);
        $this->assertStringContainsString('name="movimentacao_turma" value="' . $this->destination . '"', $html);
        $this->assertStringContainsString('aria-label="Enviar Reprovado terceiro ao Arquivo Passivo" checked', $html);
        $this->assertStringNotContainsString('id="movement-series-title"', $html);
        $this->assertStringNotContainsString('id="movement-senior-title"', $html);
        $this->assertStringNotContainsString('id="movement-selection-title"', $html);
        $this->assertSame(1, (int) (new \Aluno())->buscarPorId($this->senior)['ativo']);
        $this->assertFalse($this->service->confirmarConferencia($year, $state['fingerprint'], $this->admin));
        $this->assertSame('unclassified_classes', $this->service->lastErrorCode());
    }
}
