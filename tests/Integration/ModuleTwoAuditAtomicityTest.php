<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class ModuleTwoAuditAtomicityTest extends DatabaseTestCase
{
    public function testStudentAndInitialDvaRollBackIfEitherAuditCannotBeRecorded(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();

        foreach (['student.created', 'dva.created'] as $action) {
            $before = $this->domainState();
            $this->rejectAudit($action);
            $this->assertFalse($model->cadastrar($this->studentData($classId), $actor, [
                'data_vencimento' => '2027-06-30', 'observacao' => 'DVA inicial',
            ]));
            $this->assertSame('database_error', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
            $this->allowAudit();
        }
    }

    public function testStudentCreationWithoutDvaStillRequiresAudit(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $before = $this->domainState();
        $this->rejectAudit('student.created');

        $model = new \Aluno();
        $this->assertFalse($model->cadastrar($this->studentData($classId), $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->domainState());
    }

    public function testStudentEditCannotCommitWithoutAuditIncludingCallsWithoutActor(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = $this->insertAlunoComDva('Aluno original', '2027-01-31', $classId);
        $model = new \Aluno();
        $this->rejectAudit('student.updated');

        foreach ([$actor, null] as $caller) {
            $before = $this->domainState();
            $this->assertFalse($model->atualizar($studentId, $this->studentData($classId), false, $caller));
            $this->assertSame('database_error', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
        }
    }

    public function testBothStudentStatusChangesRollBackTheirMetadataWithoutAudit(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = $this->insertAlunoComDva('Aluno original', '2027-01-31', $classId);
        $model = new \Aluno();

        foreach ([false, true] as $active) {
            $this->pdo->prepare('UPDATE alunos SET ativo = ?, inativado_em = ?, inativado_por = ? WHERE id = ?')
                ->execute([$active ? 0 : 1, $active ? '2026-01-01 00:00:00' : null, $active ? $actor : null, $studentId]);
            $before = $this->domainState();
            $this->rejectAudit($active ? 'student.reactivated' : 'student.deactivated');
            $this->assertFalse($model->definirAtivo($studentId, $active, $actor));
            $this->assertSame('database_error', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
            $this->allowAudit();
        }
    }

    public function testStudentStatusNoOpsPreserveStateWithoutWritingAudit(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = $this->insertAlunoComDva('Aluno original', '2027-01-31', $classId);
        $model = new \Aluno();

        foreach ([true, false] as $active) {
            $this->pdo->prepare('UPDATE alunos SET ativo = ?, inativado_em = ?, inativado_por = ? WHERE id = ?')
                ->execute([$active ? 1 : 0, $active ? null : '2026-01-01 00:00:00', $active ? null : $actor, $studentId]);
            $before = $this->domainState();
            $this->rejectAudit($active ? 'student.reactivated' : 'student.deactivated');

            $this->assertTrue($model->definirAtivo($studentId, $active, $actor));
            $this->assertNull($model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
            $this->assertFalse($model->definirAtivo($studentId, $active, 0));
            $this->assertSame('invalid_data', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
            $this->allowAudit();
        }
    }

    public function testFirstDvaRegistrationRollsBackWithoutAudit(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = (new \Aluno())->cadastrar($this->studentData($classId), $actor);
        $this->assertIsInt($studentId);
        $before = $this->domainState();
        $this->rejectAudit('dva.created');

        $model = new \Dva();
        $this->assertFalse($model->registrar($studentId, '2027-06-30', 'Nova DVA', $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertFalse($model->atualDoAluno($studentId));
        $this->assertSame($before, $this->domainState());
    }

    public function testFailedRenewalPreservesCurrentDvaAndEveryHistoricalVersion(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = $this->insertAlunoComDva('Aluno original', '2026-01-31', $classId);
        $model = new \Dva();
        $current = $model->registrar($studentId, '2027-01-31', 'DVA corrente', $actor);
        $this->assertIsArray($current);
        $this->assertCount(2, $model->historicoDoAluno($studentId));
        $history = $model->historicoDoAluno($studentId);
        $before = $this->domainState();
        $this->rejectAudit('dva.renewed');

        $this->assertFalse($model->registrar($studentId, '2027-12-31', 'Renovação recusada', $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($current['id'], (int) $model->atualDoAluno($studentId)['id']);
        $this->assertSame($history, $model->historicoDoAluno($studentId));
        $this->assertSame($before, $this->domainState());
    }

    public function testClassCreationRequiresAuditEvenWithoutActor(): void
    {
        $actor = $this->insertUsuario();
        $model = new \Turma();
        $this->rejectAudit('class.created');

        foreach ([$actor, null] as $caller) {
            $before = $this->domainState();
            $this->assertFalse($model->cadastrar('2 Ano B', 2026, $caller));
            $this->assertSame('database_error', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
        }
    }

    public function testClassEditRequiresAuditEvenWithoutActor(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Turma();
        $this->rejectAudit('class.updated');

        foreach ([$actor, null] as $caller) {
            $before = $this->domainState();
            $this->assertFalse($model->atualizar($classId, '2 Ano B', 2027, $caller));
            $this->assertSame('database_error', $model->lastErrorCode());
            $this->assertSame($before, $this->domainState());
        }
    }

    public function testBothClassStatusChangesRequireAuditEvenWithoutActor(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Turma();

        foreach ([false, true] as $active) {
            $this->pdo->prepare('UPDATE turmas SET ativo = ? WHERE id = ?')->execute([$active ? 0 : 1, $classId]);
            $this->rejectAudit($active ? 'class.reactivated' : 'class.deactivated');
            foreach ([$actor, null] as $caller) {
                $before = $this->domainState();
                $this->assertFalse($model->definirAtiva($classId, $active, $caller));
                $this->assertSame('database_error', $model->lastErrorCode());
                $this->assertSame($before, $this->domainState());
            }
            $this->allowAudit();
        }
    }

    public function testClassStatusNoOpsPreserveStateWithoutWritingAudit(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Turma();

        foreach ([true, false] as $active) {
            $this->pdo->prepare('UPDATE turmas SET ativo = ? WHERE id = ?')->execute([$active ? 1 : 0, $classId]);
            $before = $this->domainState();
            $this->rejectAudit($active ? 'class.reactivated' : 'class.deactivated');

            foreach ([$actor, null] as $caller) {
                $this->assertTrue($model->definirAtiva($classId, $active, $caller));
                $this->assertNull($model->lastErrorCode());
                $this->assertSame($before, $this->domainState());
            }
            $this->allowAudit();
        }
    }

    public function testSuccessfulOperationsRecordEachExistingEventOnceWithActorAndResource(): void
    {
        $actor = $this->insertUsuario();
        $classes = new \Turma();
        $classId = $classes->cadastrar('1 Ano A', 2026, $actor);
        $this->assertIsInt($classId);
        $this->assertTrue($classes->atualizar($classId, '1 Ano B', 2026, $actor));
        $this->assertTrue($classes->definirAtiva($classId, false, $actor));
        $this->assertTrue($classes->definirAtiva($classId, true, $actor));
        $students = new \Aluno();
        $studentId = $students->cadastrar($this->studentData($classId), $actor, ['data_vencimento' => '2027-01-31']);
        $this->assertIsInt($studentId);
        $dvas = new \Dva();
        $initial = $dvas->atualDoAluno($studentId);
        $this->assertIsArray($initial);
        $changed = $this->studentData($classId);
        $changed['nome_completo'] = 'Aluno atualizado';
        $this->assertTrue($students->atualizar($studentId, $changed, false, $actor));
        $this->assertTrue($students->definirAtivo($studentId, false, $actor));
        $this->assertTrue($students->definirAtivo($studentId, true, $actor));
        $renewal = $dvas->registrar($studentId, '2027-12-31', 'Renovada', $actor);
        $this->assertIsArray($renewal);

        $expected = [
            'class.created' => ['class', $classId],
            'class.updated' => ['class', $classId],
            'class.deactivated' => ['class', $classId],
            'class.reactivated' => ['class', $classId],
            'student.created' => ['student', $studentId],
            'dva.created' => ['dva', (int) $initial['id']],
            'student.updated' => ['student', $studentId],
            'student.deactivated' => ['student', $studentId],
            'student.reactivated' => ['student', $studentId],
            'dva.renewed' => ['dva', $renewal['id']],
        ];
        $rows = $this->pdo->query('SELECT action, result, actor_user_id, target_user_id, resource_type, resource_id FROM security_audit ORDER BY id')->fetchAll();
        $this->assertCount(count($expected), $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey($row['action'], $expected);
            $this->assertSame(\AuditLogger::SUCCESS, $row['result']);
            $this->assertSame($actor, (int) $row['actor_user_id']);
            $this->assertNull($row['target_user_id']);
            $this->assertSame($expected[$row['action']], [$row['resource_type'], (int) $row['resource_id']]);
            unset($expected[$row['action']]);
        }
        $this->assertSame([], $expected);
        $this->assertCount(2, $dvas->historicoDoAluno($studentId));
        $this->assertSame($renewal['id'], (int) $dvas->atualDoAluno($studentId)['id']);
    }

    public function testBackwardCompatibleCallsStillRecordAuditWithNullActor(): void
    {
        $classes = new \Turma();
        $classId = $classes->cadastrar('1 Ano A', 2026);
        $this->assertIsInt($classId);
        $this->assertTrue($classes->atualizar($classId, '1 Ano B', 2026));
        $this->assertTrue($classes->definirAtiva($classId, false));
        $this->assertTrue($classes->definirAtiva($classId, true));
        $studentId = $this->insertAlunoComDva('Aluno original', '2027-01-31', $classId);
        $this->assertTrue((new \Aluno())->atualizar($studentId, $this->studentData($classId)));

        $rows = $this->pdo->query('SELECT action, actor_user_id FROM security_audit ORDER BY id')->fetchAll();
        $this->assertSame(['class.created', 'class.updated', 'class.deactivated', 'class.reactivated', 'student.updated'], array_column($rows, 'action'));
        foreach ($rows as $row) {
            $this->assertNull($row['actor_user_id']);
        }
    }

    /** @return array<string,mixed> */
    private function studentData(int $classId): array
    {
        return [
            'nome_completo' => 'Aluno fictício', 'data_nascimento' => '2010-05-10', 'id_turma' => $classId,
            'telefone_aluno' => '65999998888', 'telefone_responsavel' => '65999997777',
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function domainState(): array
    {
        $state = [];
        foreach (['alunos', 'dvas', 'turmas', 'security_audit', 'sqlite_sequence'] as $table) {
            $state[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'sqlite_sequence' ? 'name' : 'id'))->fetchAll();
        }
        return $state;
    }

    private function rejectAudit(string $action): void
    {
        $this->pdo->exec('CREATE TRIGGER reject_module_two_audit BEFORE INSERT ON security_audit WHEN NEW.action = '
            . $this->pdo->quote($action) . " BEGIN SELECT RAISE(ABORT, 'forced_module_two_audit_failure'); END");
    }

    private function allowAudit(): void
    {
        $this->pdo->exec('DROP TRIGGER reject_module_two_audit');
    }
}
