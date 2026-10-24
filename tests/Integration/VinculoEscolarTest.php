<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

if (is_file(ROOT_PATH . '/src/Services/VinculoEscolar.php')) {
    require_once ROOT_PATH . '/src/Services/VinculoEscolar.php';
}

final class VinculoEscolarTest extends DatabaseTestCase
{
    public function testHistoryShowsTheResponsibleNameInsteadOfCiphertext(): void
    {
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $this->assertIsArray($plan);
        $this->assertNotFalse($this->service->confirmar($plan, $this->admin, bin2hex(random_bytes(16))));
        $history = $this->service->historico($this->student);
        $this->assertSame('Usuario Teste', $history[0]['responsavel']);
        $this->assertStringNotContainsString('gse1:', $history[0]['responsavel']);
    }

    private \VinculoEscolar $service;
    private int $admin;
    private int $source;
    private int $target;
    private int $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(\VinculoEscolar::class), 'Falta o fluxo de transferência e retorno escolar.');
        $this->service = new \VinculoEscolar();
        $this->admin = $this->insertUsuario();
        $this->source = $this->class('1º ANO ENSINO MÉDIO A', 10, 2026);
        $this->target = $this->class('1º ANO ENSINO MÉDIO B', 10, 2026);
        $this->student = $this->insertAlunoComDva('Aluno fictício', '2027-01-01', $this->source);
    }

    private function class(string $name, ?int $series, ?int $year): int
    {
        $id = $this->insertTurma($name);
        $this->pdo->prepare('UPDATE turmas SET ano_letivo=? WHERE id=?')->execute([$year, $id]);
        if ($series !== null) {
            $this->pdo->prepare('INSERT INTO turmas_series(id_turma,serie) VALUES(?,?)')->execute([$id, $series]);
        }
        return $id;
    }

    private function archive(): int
    {
        $this->assertTrue((new \Aluno())->definirAtivo($this->student, false, $this->admin));
        $passive = (new \Passivo())->arquivarAluno($this->student, ['caixa' => 'CAIXA TESTE', 'numero' => '1'], $this->admin);
        $this->assertIsInt($passive);
        return $passive;
    }

    public function testTransferenciaPreservaDvaGravaHistoricoAuditoriaEReciboUmaVez(): void
    {
        $dvas = $this->pdo->query('SELECT * FROM dvas')->fetchAll();
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $this->assertIsArray($plan);
        $result = $this->service->confirmar($plan, $this->admin, str_repeat('a', 32));
        $this->assertIsInt($result);
        $this->assertSame($this->target, (int) (new \Aluno())->buscarPorId($this->student)['id_turma']);
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas')->fetchAll());
        $history = $this->service->historico($this->student);
        $this->assertCount(1, $history);
        $this->assertSame('transferencia', $history[0]['tipo']);
        $this->assertSame(10, (int) $history[0]['origem_serie']);
        $this->assertSame(10, (int) $history[0]['destino_serie']);
        $this->assertSame($result, $this->service->confirmar($plan, $this->admin, str_repeat('a', 32)));
        $this->assertCount(1, $this->service->historico($this->student));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.transferred'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_movimentacoes')->fetchColumn());
    }

    public function testTransferenciaRejeitaOutraSerieAnoAusenteDiferenteTurmaInativaESemSerie(): void
    {
        $invalid = [
            $this->source,
            $this->class('2º ANO ENSINO MÉDIO', 11, 2026),
            $this->class('Mesmo nível outro ano', 10, 2027),
            $this->class('Ano não informado', 10, null),
            $this->class('Sem identificação', null, 2026),
        ];
        $inactive = $this->class('Turma inativa', 10, 2026);
        $this->pdo->prepare('UPDATE turmas SET ativo=0 WHERE id=?')->execute([$inactive]);
        $invalid[] = $inactive;
        foreach ($invalid as $target) {
            $this->assertFalse($this->service->previewTransferencia($this->student, $target, $this->admin));
        }
        $this->assertSame($this->source, (int) (new \Aluno())->buscarPorId($this->student)['id_turma']);
    }

    public function testFuncionarioEAdministradorInativoNaoTemPreviaNemConfirma(): void
    {
        $employee = $this->insertUsuario('Funcionário teste', 'funcionario');
        $inactive = $this->insertUsuario('Administrador inativo', 'administrador', false);
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        foreach ([$employee, $inactive, 0] as $actor) {
            $this->assertFalse($this->service->previewTransferencia($this->student, $this->target, $actor));
            $this->assertSame('invalid_actor', $this->service->lastErrorCode());
            $this->assertFalse($this->service->confirmar($plan, $actor, str_repeat('b', 32)));
        }
        $this->assertSame([], $this->service->historico($this->student));
    }

    public function testRevisoesDeAlunoTurmaEPreviaInvalidaNaoConfirmam(): void
    {
        foreach (['alunos', 'turmas'] as $table) {
            $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
            $id = $table === 'alunos' ? $this->student : $this->target;
            $this->pdo->prepare('UPDATE ' . $table . ' SET atualizado_em=atualizado_em WHERE id=?')->execute([$id]);
            $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('c', 32)));
            $this->assertSame('preview_changed', $this->service->lastErrorCode());
        }
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $plan['fingerprint'] = 'inválida';
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('d', 32)));
        $plan['aluno']['revisao'] = 0;
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('e', 32)));
        $this->assertSame([], $this->service->historico($this->student));
    }

    public function testRetornoDesativaPassivoAntesDeReativarAlunoPreservandoTodoHistorico(): void
    {
        $passive = $this->archive();
        $archiveBefore = (new \Passivo())->buscarPorId($passive);
        $dvas = $this->pdo->query('SELECT * FROM dvas')->fetchAll();
        $this->pdo->exec("CREATE TRIGGER ensure_return_order BEFORE UPDATE OF ativo ON alunos
            WHEN NEW.ativo=1 AND EXISTS(SELECT 1 FROM alunos_passivo WHERE aluno_origem_id=NEW.id AND ativo=1)
            BEGIN SELECT RAISE(ABORT,'simultaneous_active'); END");
        $plan = $this->service->previewRetorno($passive, $this->target, $this->admin);
        $this->assertIsArray($plan);
        $result = $this->service->confirmar($plan, $this->admin, str_repeat('f', 32));
        $this->assertIsInt($result);
        $student = (new \Aluno())->buscarPorId($this->student);
        $after = (new \Passivo())->buscarPorId($passive);
        $this->assertSame(1, (int) $student['ativo']);
        $this->assertSame($this->target, (int) $student['id_turma']);
        $this->assertSame(0, (int) $after['ativo']);
        foreach (['nome_completo', 'data_nascimento', 'caixa', 'numero', 'aluno_origem_id', 'criado_em'] as $field) {
            $this->assertSame($archiveBefore[$field], $after[$field]);
        }
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas')->fetchAll());
        $this->assertSame($result, $this->service->confirmar($plan, $this->admin, str_repeat('f', 32)));
        $this->assertSame('retorno', $this->service->historico($this->student)[0]['tipo']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.returned_from_passive'")->fetchColumn());
        $this->assertFalse((new \Passivo())->definirAtivo($passive, true, $this->admin));
    }

    public function testRetornoExigeVinculoPassivoAtivoAlunoInativoTurmaValidaEAdmin(): void
    {
        $passive = $this->archive();
        $employee = $this->insertUsuario('Funcionário retorno', 'funcionario');
        $this->assertFalse($this->service->previewRetorno($passive, $this->target, $employee));
        $this->assertFalse($this->service->previewRetorno($passive, 999999, $this->admin));
        $manual = (new \Passivo())->cadastrar(['nome_completo' => 'Registro manual', 'data_nascimento' => '', 'caixa' => 'MANUAL', 'numero' => '2'], $this->admin);
        $this->assertIsInt($manual);
        $this->assertFalse($this->service->previewRetorno($manual, $this->target, $this->admin));
        $this->pdo->prepare('UPDATE alunos_passivo SET ativo=0 WHERE id=?')->execute([$passive]);
        $this->assertFalse($this->service->previewRetorno($passive, $this->target, $this->admin));
        $this->assertSame(0, (int) (new \Aluno())->buscarPorId($this->student)['ativo']);
    }

    public function testMudancaDeRevisaoPassivaOuArquivamentoAposPreviaInvalidaConfirmacao(): void
    {
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $passive = $this->archive();
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('1', 32)));
        $plan = $this->service->previewRetorno($passive, $this->target, $this->admin);
        $this->pdo->prepare('UPDATE alunos_passivo SET atualizado_em=atualizado_em WHERE id=?')->execute([$passive]);
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('2', 32)));
        $this->assertSame('preview_changed', $this->service->lastErrorCode());
        $this->assertSame(1, (int) (new \Passivo())->buscarPorId($passive)['ativo']);
    }

    public function testFalhaDeAuditoriaDesfazTransferenciaERetornoIncluindoRecibo(): void
    {
        foreach (['transferencia', 'retorno'] as $type) {
            $passive = $type === 'retorno' ? $this->archive() : null;
            $plan = $passive === null ? $this->service->previewTransferencia($this->student, $this->target, $this->admin)
                : $this->service->previewRetorno($passive, $this->target, $this->admin);
            $before = $this->pdo->query('SELECT * FROM alunos')->fetchAll();
            $archives = $this->pdo->query('SELECT * FROM alunos_passivo')->fetchAll();
            $this->pdo->exec("CREATE TRIGGER fail_link_audit BEFORE INSERT ON security_audit
                WHEN NEW.action IN ('student.transferred','student.returned_from_passive') BEGIN SELECT RAISE(ABORT,'forced audit failure'); END");
            $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('3', 32)));
            $this->assertSame($before, $this->pdo->query('SELECT * FROM alunos')->fetchAll());
            $this->assertSame($archives, $this->pdo->query('SELECT * FROM alunos_passivo')->fetchAll());
            $this->assertSame([], $this->service->historico($this->student));
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM request_receipts WHERE operation='student.school_link'")->fetchColumn());
            $this->pdo->exec('DROP TRIGGER fail_link_audit');
        }
    }

    public function testEdicaoComumNaoDesfazVinculoRegistrado(): void
    {
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $this->assertIsInt($this->service->confirmar($plan, $this->admin, str_repeat('4', 32)));
        $model = new \Aluno();
        $student = $model->buscarPorId($this->student);
        $this->assertSame(1, (int) $student['possui_movimentacao']);
        $student['id_turma'] = $this->source;
        $this->assertFalse($model->atualizar($this->student, $student, false, $this->admin, (int) $student['revisao']));
        $this->assertSame('class_change_requires_movement', $model->lastErrorCode());
    }

    public function testReciboReutilizadoComOutroAlunoOuTokenInvalidoNaoMovimenta(): void
    {
        $plan = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $this->assertFalse($this->service->confirmar($plan, $this->admin, 'token inválido'));
        $this->assertSame([], $this->service->historico($this->student));
        $this->assertIsInt($this->service->confirmar($plan, $this->admin, str_repeat('5', 32)));
        $other = $this->insertAlunoComDva('Outro aluno fictício', '2027-01-01', $this->source);
        $otherPlan = $this->service->previewTransferencia($other, $this->target, $this->admin);
        $this->assertFalse($this->service->confirmar($otherPlan, $this->admin, str_repeat('5', 32)));
        $this->assertSame($this->source, (int) (new \Aluno())->buscarPorId($other)['id_turma']);
        $this->assertSame([], $this->service->historico($other));
    }

    public function testFalhaNaSegundaAuditoriaDoRetornoDesfazPrimeiroEventoEHistorico(): void
    {
        $passive = $this->archive();
        $plan = $this->service->previewRetorno($passive, $this->target, $this->admin);
        $this->pdo->exec("CREATE TRIGGER fail_passive_return_audit BEFORE INSERT ON security_audit
            WHEN NEW.action='passive.student_returned' BEGIN SELECT RAISE(ABORT,'forced second audit failure'); END");
        $this->assertFalse($this->service->confirmar($plan, $this->admin, str_repeat('6', 32)));
        $this->assertSame(0, (int) (new \Aluno())->buscarPorId($this->student)['ativo']);
        $this->assertSame(1, (int) (new \Passivo())->buscarPorId($passive)['ativo']);
        $this->assertSame([], $this->service->historico($this->student));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.returned_from_passive'")->fetchColumn());
    }

    public function testTelaPermiteEscolherConferirEConfirmarSemJavascript(): void
    {
        $file = ROOT_PATH . '/src/Views/alunos/vinculo-escolar.php';
        $this->assertFileExists($file, 'Falta o formulário guiado de vínculo escolar.');
        $type = 'transferencia';
        $student = (new \Aluno())->buscarPorId($this->student);
        $record = null;
        $classes = array_values(array_filter($this->service->turmas(), fn (array $class): bool => (int) $class['id'] === $this->target));
        $preview = $this->service->previewTransferencia($this->student, $this->target, $this->admin);
        $previewToken = str_repeat('a', 64);
        $requestToken = str_repeat('b', 32);
        $selected = $this->target;
        $path = 'aluno/transferir/' . $this->student;
        $previewPath = 'aluno/transferir/preview/' . $this->student;
        ob_start();
        try {
            require $file;
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Transferir entre turmas', $html);
        $this->assertStringContainsString('Conferir a transferência', $html);
        $this->assertStringContainsString('name="_csrf_token"', $html);
        $this->assertStringContainsString('name="_request_token" value="' . $requestToken . '"', $html);
        $this->assertStringContainsString('name="confirmar" value="1" required', $html);
        $this->assertStringContainsString('Aluno fictício', $html);
        $this->assertStringContainsString('1º ANO ENSINO MÉDIO B', $html);
    }
}
