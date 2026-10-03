<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PassiveBatchArchiveTest extends DatabaseTestCase
{
    public function testPreviewIsReadOnlyAndConfirmationArchivesActiveAndInactiveStudentsAtomically(): void
    {
        $actor = $this->insertUsuario('Admin Lote Passivo');
        $class = $this->insertTurma('Turma Lote Ficticia');
        $zeta = $this->insertBatchStudent('Zeta Ficticia', true, $class);
        $ana = $this->insertBatchStudent('Ana Ficticia', false, $class);
        $bia = $this->insertBatchStudent('Bia Ficticia', true, $class);
        foreach ([$zeta, $ana, $bia] as $student) {
            $this->pdo->prepare('INSERT INTO dvas(id_aluno,id_usuario_registro,data_vencimento,observacao) VALUES(?,?,?,?)')
                ->execute([$student, $actor, '2027-01-01', 'DVA ficticia preservada']);
        }
        $studentsBefore = $this->studentRows();
        $dvasBefore = $this->dvaRows();
        $model = new \Passivo();

        $selection = $model->alunosParaArquivar([$zeta, (string) $ana, $bia]);
        $this->assertIsArray($selection);
        $this->assertSame([$ana, $bia, $zeta], array_column($selection, 'id'));
        $preview = $model->previewArquivamentoLote([$zeta, $ana, $bia], 'CX-LOTE', 'nova');
        $this->assertIsArray($preview);
        $this->assertSame('CX-LOTE', $preview['caixa']);
        $this->assertSame('nova', $preview['tipo_caixa']);
        $this->assertSame(3, $preview['total']);
        $this->assertSame([$ana, $bia, $zeta], array_column($preview['alunos'], 'id'));
        $this->assertSame(['1', '2', '3'], array_map('strval', array_column($preview['alunos'], 'numero')));
        $this->assertSame('1', (string) $preview['primeiro_numero']);
        $this->assertSame('3', (string) $preview['ultimo_numero']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $preview['fingerprint']);
        $this->assertSame($studentsBefore, $this->studentRows());
        $this->assertSame($dvasBefore, $this->dvaRows());
        $this->assertSame(0, $this->passiveCount());
        $this->assertSame(0, $this->auditCount());

        $result = $model->arquivarAlunosLote([$zeta, $ana, $bia], 'CX-LOTE', 'nova', $preview['fingerprint'], $actor);
        $this->assertIsArray($result);
        $this->assertSame('CX-LOTE', $result['caixa']);
        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['ids']);
        $archives = array_map(fn (int $id): array|false => $model->buscarPorId($id), $result['ids']);
        $this->assertSame([$ana, $bia, $zeta], array_column($archives, 'aluno_origem_id'));
        $this->assertSame(['1', '2', '3'], array_column($archives, 'numero'));
        $this->assertSame(['Ana Ficticia', 'Bia Ficticia', 'Zeta Ficticia'], array_column($archives, 'nome_completo'));
        $this->assertSame(['2010-05-10', '2010-05-10', '2010-05-10'], array_column($archives, 'data_nascimento'));
        $this->assertSame([0, 0, 0], array_column($this->studentRows(), 'ativo'));
        $this->assertSame($dvasBefore, $this->dvaRows());
        $this->assertSame(2, $this->auditCount('student.deactivated'));
        $this->assertSame(3, $this->auditCount('passive.student_archived'));
        $this->assertSame(1, $this->auditCount('passive.batch_archived'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit WHERE actor_user_id != ' . $actor)->fetchColumn());
        foreach ($this->studentRows() as $after) {
            $before = array_values(array_filter($studentsBefore, static fn (array $row): bool => $row['id'] === $after['id']))[0];
            foreach (['id', 'nome_completo', 'nome_normalizado', 'data_nascimento', 'id_turma', 'telefone_aluno', 'telefone_responsavel', 'criado_em'] as $field) {
                $this->assertSame($before[$field], $after[$field], 'Campo cadastral preservado: ' . $field);
            }
        }
        $this->assertFalse($model->arquivarAlunosLote([$zeta, $ana, $bia], 'CX-LOTE', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame(3, $this->passiveCount());
        $this->assertSame(1, $this->auditCount('passive.batch_archived'));
    }

    public function testExistingBoxStartsAfterLargestNumericPositionIncludingInactiveRecords(): void
    {
        $actor = $this->insertUsuario('Admin Caixa Existente Lote');
        $model = new \Passivo();
        $existing = $model->cadastrar(['nome_completo' => 'Arquivo ficticio anterior', 'caixa' => 'CX-ANTIGA', 'numero' => '00012'], $actor);
        $this->assertIsInt($existing);
        $this->assertTrue($model->definirAtivo($existing, false, $actor));
        $this->assertIsInt($model->cadastrar(['nome_completo' => 'Posicao alfanumerica', 'caixa' => 'CX-ANTIGA', 'numero' => 'A-999'], $actor));
        $before = $model->buscarPorId($existing);
        $bia = $this->insertBatchStudent('Bia Caixa Existente');
        $ana = $this->insertBatchStudent('Ana Caixa Existente');

        $preview = $model->previewArquivamentoLote([$bia, $ana], 'cx-antiga', 'existente');
        $this->assertIsArray($preview);
        $this->assertSame(['13', '14'], array_map('strval', array_column($preview['alunos'], 'numero')));
        $this->assertSame('13', (string) $preview['primeiro_numero']);
        $this->assertSame('14', (string) $preview['ultimo_numero']);
        $result = $model->arquivarAlunosLote([$bia, $ana], 'cx-antiga', 'existente', $preview['fingerprint'], $actor);
        $this->assertIsArray($result);
        $this->assertSame(['13', '14'], array_column(array_map(fn (int $id): array|false => $model->buscarPorId($id), $result['ids']), 'numero'));
        $this->assertSame($before, $model->buscarPorId($existing));
    }

    public function testNewBoxCannotReuseAnInactiveBoxAndExistingBoxMustExist(): void
    {
        $actor = $this->insertUsuario('Admin Exclusividade Caixa Lote');
        $student = $this->insertBatchStudent('Aluno Caixa Exclusiva');
        $model = new \Passivo();
        $old = $model->cadastrar(['nome_completo' => 'Arquivo inativo ficticio', 'caixa' => 'CX-OCUPADA'], $actor);
        $this->assertIsInt($old);
        $this->assertTrue($model->definirAtivo($old, false, $actor));

        $this->assertFalse($model->previewArquivamentoLote([$student], 'cx-ocupada', 'nova'));
        $this->assertFalse($model->previewArquivamentoLote([$student], 'CX-INEXISTENTE', 'existente'));
        $this->assertFalse($model->previewArquivamentoLote([$student], 'CX-NOVA', 'tipo-forjado'));
        $this->assertSame(1, $this->passiveCount());
        $this->assertSame(1, $this->studentRows()[0]['ativo']);
    }

    public function testStrictSelectionRejectsMalformedIdsAndEnforcesBatchLimit(): void
    {
        $this->assertSame(200, \Passivo::ARCHIVE_BATCH_MAX);
        $ids = [];
        for ($index = 1; $index <= 201; $index++) {
            $ids[] = $this->insertBatchStudent('Aluno ficticio lote ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
        $model = new \Passivo();
        foreach ([[], [0], [-1], [true], [1.5], [[1]], ['1 OR 1=1'], ['1x'], ['999999999999999999999999'], [999999], $ids] as $invalid) {
            $this->assertFalse($model->alunosParaArquivar($invalid));
            $this->assertFalse($model->previewArquivamentoLote($invalid, 'CX-LIMITE', 'nova'));
        }
        $preview = $model->previewArquivamentoLote(array_slice($ids, 0, 200), 'CX-LIMITE', 'nova');
        $this->assertIsArray($preview);
        $this->assertSame(200, $preview['total']);
        $this->assertSame('200', (string) $preview['ultimo_numero']);
        $this->assertSame(0, $this->passiveCount());
    }

    public function testAlreadyArchivedStudentRejectsWholeSelection(): void
    {
        $actor = $this->insertUsuario('Admin Duplicidade Lote');
        $archived = $this->insertBatchStudent('Aluno ja arquivado', false);
        $active = $this->insertBatchStudent('Aluno ainda ativo');
        $model = new \Passivo();
        $this->assertIsInt($model->arquivarAluno($archived, ['caixa' => 'CX-ANTERIOR', 'numero' => '1'], $actor));
        $students = $this->studentRows();
        $this->assertFalse($model->alunosParaArquivar([$active, $archived]));
        $this->assertFalse($model->previewArquivamentoLote([$active, $archived], 'CX-DUPLICADA', 'nova'));
        $this->assertSame($students, $this->studentRows());
        $this->assertSame(1, $this->passiveCount());
    }

    public function testStudentChangeAfterPreviewRejectsEveryArchive(): void
    {
        $actor = $this->insertUsuario('Admin Concorrencia Aluno Lote');
        $ana = $this->insertBatchStudent('Ana Antes Previa');
        $bia = $this->insertBatchStudent('Bia Antes Previa');
        $model = new \Passivo();
        $preview = $model->previewArquivamentoLote([$ana, $bia], 'CX-CONCORRENTE', 'nova');
        $this->assertIsArray($preview);
        $this->pdo->prepare('UPDATE alunos SET nome_completo=?,nome_normalizado=? WHERE id=?')
            ->execute(['Ana Depois Previa', \src\Core\TextNormalizer::comparisonKey('Ana Depois Previa'), $ana]);
        $current = $this->studentRows();
        $this->assertFalse($model->arquivarAlunosLote([$ana, $bia], 'CX-CONCORRENTE', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame('preview_changed', $model->lastErrorCode());
        $this->assertSame($current, $this->studentRows());
        $this->assertSame(0, $this->passiveCount());
        $this->assertSame(0, $this->auditCount('passive.batch_archived'));
    }

    public function testExistingBoxNumberChangeAfterPreviewRejectsEveryArchive(): void
    {
        $actor = $this->insertUsuario('Admin Concorrencia Numero Lote');
        $ana = $this->insertBatchStudent('Ana Numero Concorrente');
        $bia = $this->insertBatchStudent('Bia Numero Concorrente');
        $model = new \Passivo();
        $this->assertIsInt($model->cadastrar(['nome_completo' => 'Arquivo anterior', 'caixa' => 'CX-NUMERO', 'numero' => '5'], $actor));
        $preview = $model->previewArquivamentoLote([$ana, $bia], 'CX-NUMERO', 'existente');
        $this->assertIsArray($preview);
        $this->assertIsInt($model->cadastrar(['nome_completo' => 'Arquivo concorrente', 'caixa' => 'CX-NUMERO', 'numero' => '6'], $actor));
        $current = $this->studentRows();
        $this->assertFalse($model->arquivarAlunosLote([$ana, $bia], 'CX-NUMERO', 'existente', $preview['fingerprint'], $actor));
        $this->assertSame('preview_changed', $model->lastErrorCode());
        $this->assertSame($current, $this->studentRows());
        $this->assertSame(2, $this->passiveCount());
    }

    public function testNewBoxClaimedAfterPreviewRejectsEveryArchive(): void
    {
        $actor = $this->insertUsuario('Admin Concorrencia Caixa Lote');
        $student = $this->insertBatchStudent('Aluno Caixa Concorrente');
        $model = new \Passivo();
        $preview = $model->previewArquivamentoLote([$student], 'CX-REIVINDICADA', 'nova');
        $this->assertIsArray($preview);
        $this->assertIsInt($model->cadastrar(['nome_completo' => 'Arquivo de outro operador', 'caixa' => 'CX-REIVINDICADA', 'numero' => '1'], $actor));
        $current = $this->studentRows();
        $this->assertFalse($model->arquivarAlunosLote([$student], 'CX-REIVINDICADA', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame('preview_changed', $model->lastErrorCode());
        $this->assertSame($current, $this->studentRows());
        $this->assertSame(1, $this->passiveCount());
    }

    public function testAuditFailureOnLastStudentRollsBackAllStudentsArchivesAndAudits(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Ultimo Lote');
        $ids = [$this->insertBatchStudent('Ana Auditoria'), $this->insertBatchStudent('Bia Auditoria'), $this->insertBatchStudent('Zeta Auditoria')];
        $model = new \Passivo();
        $preview = $model->previewArquivamentoLote($ids, 'CX-AUDITORIA', 'nova');
        $this->assertIsArray($preview);
        $students = $this->studentRows();
        $this->pdo->exec("CREATE TRIGGER fail_last_batch_student BEFORE INSERT ON security_audit
            WHEN NEW.action='passive.student_archived'
             AND (SELECT COUNT(*) FROM security_audit WHERE action='passive.student_archived')=2
            BEGIN SELECT RAISE(ABORT,'forced_last_student_audit'); END");

        $this->assertFalse($model->arquivarAlunosLote($ids, 'CX-AUDITORIA', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame($students, $this->studentRows());
        $this->assertSame(0, $this->passiveCount());
        $this->assertSame(0, $this->auditCount());
        $this->pdo->exec('DROP TRIGGER fail_last_batch_student');
        $this->assertIsArray($model->arquivarAlunosLote($ids, 'CX-AUDITORIA', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame(3, $this->passiveCount());
    }

    public function testSummaryAuditFailureRollsBackEntireBatch(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Resumo Lote');
        $ids = [$this->insertBatchStudent('Ana Resumo'), $this->insertBatchStudent('Bia Resumo')];
        $model = new \Passivo();
        $preview = $model->previewArquivamentoLote($ids, 'CX-RESUMO', 'nova');
        $this->assertIsArray($preview);
        $students = $this->studentRows();
        $this->pdo->exec("CREATE TRIGGER fail_batch_summary BEFORE INSERT ON security_audit
            WHEN NEW.action='passive.batch_archived'
            BEGIN SELECT RAISE(ABORT,'forced_batch_summary'); END");

        $this->assertFalse($model->arquivarAlunosLote($ids, 'CX-RESUMO', 'nova', $preview['fingerprint'], $actor));
        $this->assertSame($students, $this->studentRows());
        $this->assertSame(0, $this->passiveCount());
        $this->assertSame(0, $this->auditCount());
    }

    public function testNumberIncrementRejectsOverflowBeyondSupportedPositionLength(): void
    {
        $actor = $this->insertUsuario('Admin Limite Numeracao Lote');
        $student = $this->insertBatchStudent('Aluno Limite Numeracao');
        $model = new \Passivo();
        $this->assertIsInt($model->cadastrar(['nome_completo' => 'Arquivo no limite', 'caixa' => 'CX-LIMITE-NUMERO', 'numero' => str_repeat('9', \Passivo::NUMBER_MAX_LENGTH)], $actor));
        $this->assertFalse($model->previewArquivamentoLote([$student], 'CX-LIMITE-NUMERO', 'existente'));
        $this->assertSame(1, $this->passiveCount());
        $this->assertSame(1, $this->studentRows()[0]['ativo']);
    }

    private function insertBatchStudent(string $name, bool $active = true, ?int $class = null): int
    {
        $display = \src\Core\TextNormalizer::displayName($name);
        $this->pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma,telefone_aluno,telefone_responsavel,ativo) VALUES(?,?,?,?,?,?,?)')
            ->execute([$display, \src\Core\TextNormalizer::comparisonKey($display), '2010-05-10', $class, '65999990001', '65999990002', $active ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function studentRows(): array
    {
        return $this->pdo->query('SELECT * FROM alunos ORDER BY id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function dvaRows(): array
    {
        return $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
    }

    private function passiveCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn();
    }

    private function auditCount(?string $action = null): int
    {
        if ($action === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM security_audit WHERE action=?');
        $statement->execute([$action]);

        return (int) $statement->fetchColumn();
    }
}
