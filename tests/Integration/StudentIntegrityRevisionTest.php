<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';

final class StudentIntegrityRevisionTest extends DatabaseTestCase
{
    public function testOldContactFormCannotUndoAnnualProgression(): void
    {
        $actor = $this->insertUsuario();
        $source = $this->insertTurma('PRIMEIRO');
        $target = $this->insertTurma('SEGUNDO');
        $service = new \MovimentacaoAlunos();
        $this->assertTrue($service->definirSeries([$source => '10', $target => '11'], $actor));
        $student = $this->insertAlunoComDva('Aluno com formulário aberto', '2099-01-01', $source);
        $model = new \Aluno();
        $opened = $model->buscarPorId($student);
        $plan = $service->previewAnual([], 2027);
        $this->assertIsArray($plan);
        $this->assertIsInt($service->confirmarAutomatico($plan, $actor, str_repeat('a', 32)));
        $history = $service->historico($student);
        $opened['telefone_aluno'] = '65999998888';
        $this->assertFalse($model->atualizar($student, $opened, false, $actor, (int) ($opened['revisao'] ?? 1)));
        $this->assertSame('stale_revision', $model->lastErrorCode());
        $current = $model->buscarPorId($student);
        $this->assertSame($target, (int) $current['id_turma']);
        $this->assertNotSame('65999998888', $current['telefone_aluno']);
        $this->assertSame($history, $service->historico($student));
        $current['telefone_aluno'] = '65999998888';
        $this->assertTrue($model->atualizar($student, $current, false, $actor, (int) $current['revisao']));
        $this->assertSame($target, (int) $model->buscarPorId($student)['id_turma']);
    }

    public function testFreshFormCannotChangeClassAfterRecordedMovement(): void
    {
        $actor = $this->insertUsuario();
        $source = $this->insertTurma('PRIMEIRO');
        $target = $this->insertTurma('SEGUNDO');
        $service = new \MovimentacaoAlunos();
        $this->assertTrue($service->definirSeries([$source => '10', $target => '11'], $actor));
        $student = $this->insertAlunoComDva('Aluno progredido', '2099-01-01', $source);
        $plan = $service->previewAnual([], 2027);
        $this->assertIsArray($plan);
        $this->assertIsInt($service->confirmarAutomatico($plan, $actor, str_repeat('c', 32)));
        $model = new \Aluno();
        $current = $model->buscarPorId($student);
        $history = $service->historico($student);
        $audit = $this->pdo->query('SELECT * FROM security_audit ORDER BY id')->fetchAll();
        $attempt = $current;
        $attempt['id_turma'] = $source;
        $attempt['telefone_aluno'] = '65999997777';
        $this->assertFalse($model->atualizar($student, $attempt, false, $actor, (int) $current['revisao']));
        $this->assertSame('class_change_requires_movement', $model->lastErrorCode());
        $this->assertSame($current, $model->buscarPorId($student));
        $this->assertSame($history, $service->historico($student));
        $this->assertSame($audit, $this->pdo->query('SELECT * FROM security_audit ORDER BY id')->fetchAll());
        // Trusted internal callers cannot bypass the movement rule by omitting revision.
        $this->assertFalse($model->atualizar($student, $attempt, false, $actor));
        $current['telefone_aluno'] = '65999997777';
        $this->assertTrue($model->atualizar($student, $current, false, $actor, (int) $current['revisao']));
        $this->assertSame('65999997777', $model->buscarPorId($student)['telefone_aluno']);
        $this->assertSame($target, (int) $model->buscarPorId($student)['id_turma']);
        $this->assertSame($history, $service->historico($student));
    }

    public function testInitialClassCorrectionWithoutMovementHistoryRemainsAllowed(): void
    {
        $actor = $this->insertUsuario();
        $source = $this->insertTurma('CADASTRO INCORRETO');
        $target = $this->insertTurma('TURMA CORRETA');
        $student = $this->insertAlunoComDva('Correção inicial', '2099-01-01', $source);
        $model = new \Aluno();
        $current = $model->buscarPorId($student);
        $current['id_turma'] = $target;
        $this->assertTrue($model->atualizar($student, $current, false, $actor, (int) $current['revisao']));
        $this->assertSame($target, (int) $model->buscarPorId($student)['id_turma']);
        $this->assertSame([], (new \MovimentacaoAlunos())->historico($student));
    }

    public function testMovedStudentFormTargetsVisibleClassErrorAndRespectsMovementRole(): void
    {
        require_once ROOT_PATH . '/src/Core/UiPresentation.php';
        foreach ([true, false] as $canManageMovement) {
            $editing = true;
            $studentId = 1;
            $classChangeLocked = true;
            $currentClass = ['nome_turma' => 'Segundo atual', 'ano_letivo' => 2027, 'turma_ativa' => 1, 'id_turma' => 2];
            $possibleDuplicate = false;
            $errors = ['Este aluno possui histórico de movimentação escolar. Para alterar a turma, use Movimentar alunos.'];
            $turmas = [];
            $data = ['revisao' => '3', 'nome_completo' => 'Aluno', 'data_nascimento' => '2010-05-10',
                'id_turma' => '1', 'telefone_aluno' => '', 'telefone_responsavel' => '', 'observacao' => '', 'data_vencimento' => ''];
            ob_start();
            require ROOT_PATH . '/src/Views/alunos/form.php';
            $html = (string) ob_get_clean();
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//div[@role="alert"]//a[@href="#turma_atual"]')->length);
            $this->assertSame(1, $xpath->query('//input[@id="turma_atual"][@aria-invalid="true"][@aria-describedby="turma-movement-help turma_atual-error"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="turma_atual-error"]')->length);
            $this->assertSame($canManageMovement ? 1 : 0, $xpath->query('//a[contains(@href,"aluno/movimentar")]')->length);
            if (!$canManageMovement) {
                $this->assertStringContainsString('solicite a um administrador', $html);
            }
        }
    }

    public function testClassFormRefusesRevisionFromBeforeStatusChange(): void
    {
        $actor = $this->insertUsuario();
        $id = $this->insertTurma('TURMA ORIGINAL');
        $model = new \Turma();
        $opened = $model->buscarPorId($id);
        $this->assertTrue($model->definirAtiva($id, false, $actor));
        $this->assertFalse($model->atualizar($id, 'NOME ANTIGO REENVIADO', 2026, $actor, (int) ($opened['revisao'] ?? 1)));
        $this->assertSame('stale_revision', $model->lastErrorCode());
        $this->assertSame('TURMA ORIGINAL', $model->buscarPorId($id)['nome_turma']);
    }

    public function testPassiveFormRefusesRevisionFromBeforeRenumbering(): void
    {
        $actor = $this->insertUsuario();
        $model = new \Passivo();
        $id = $model->cadastrar(['nome_completo' => 'Arquivo', 'data_nascimento' => '', 'caixa' => 'CX', 'numero' => '99'], $actor);
        $this->assertIsInt($id);
        $opened = $model->buscarPorId($id);
        $plan = $model->previewEnumeracao('CX');
        $this->assertIsArray($plan);
        $this->assertSame(1, $model->aplicarEnumeracao('CX', $plan['assignments'], $actor));
        $this->assertFalse($model->atualizar($id, $opened, $actor, (int) ($opened['revisao'] ?? 1)));
        $this->assertSame('stale_revision', $model->lastErrorCode());
        $this->assertSame('1', $model->buscarPorId($id)['numero']);
    }

    public function testReactivationRequiresExplicitPassiveDeactivationFirst(): void
    {
        $actor = $this->insertUsuario();
        $student = $this->insertAlunoComDva('Aluno arquivado', '2099-01-01', $this->insertTurma());
        $model = new \Aluno();
        $passive = new \Passivo();
        $this->assertTrue($model->definirAtivo($student, false, $actor));
        $record = $passive->arquivarAluno($student, ['caixa' => 'CX', 'numero' => '1'], $actor);
        $this->assertIsInt($record);
        $before = $passive->buscarPorId($record);
        $this->assertFalse($model->definirAtivo($student, true, $actor));
        $this->assertSame('active_passive_record', $model->lastErrorCode());
        $this->assertSame(0, (int) $model->buscarPorId($student)['ativo']);
        $this->assertSame($before, $passive->buscarPorId($record));
        $this->assertTrue($passive->definirAtivo($record, false, $actor));
        $this->assertTrue($model->definirAtivo($student, true, $actor));
        $this->assertFalse($passive->definirAtivo($record, true, $actor));
        $this->assertSame('active_student', $passive->lastErrorCode());
        $this->assertSame(0, (int) $passive->buscarPorId($record)['ativo']);
    }

    public function testReplayedMigrationSkipsWorkAfterAnotherInitializerCommitted(): void
    {
        $method = new \ReflectionMethod(\src\Core\DatabaseInitializer::class, 'runMigration');
        $before = $this->pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
        // Simulate an initializer whose applied-version snapshot predates another commit.
        $method->invoke(null, $this->pdo, 20, (string) file_get_contents(ROOT_PATH . '/database/schema.sql'));
        $this->assertSame($before, $this->pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll());
        $this->assertSame(count($before), (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testContractQueriesUseIndexesForProductsAndOperationProofs(): void
    {
        $products = $this->pdo->query('EXPLAIN QUERY PLAN SELECT * FROM pedido_produtos WHERE id_pedido=42 ORDER BY numero_pagina,id')->fetchAll();
        $proofs = $this->pdo->query("EXPLAIN QUERY PLAN SELECT 1 FROM modulo5_operacoes WHERE recurso_id=42 AND tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida') LIMIT 1")->fetchAll();
        $this->assertStringContainsString('idx_pedido_produtos_pedido_pagina', implode(' ', array_column($products, 'detail')));
        $this->assertStringContainsString('idx_modulo5_operacoes_recurso_tipo', implode(' ', array_column($proofs, 'detail')));
    }

    public function testConcurrentMigrationWorkersCommitEachVersionOnlyOnce(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-migration-race-' . bin2hex(random_bytes(8)) . '.sqlite';
        $barrier = $path . '.go';
        $workers = [];
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        try {
            $pdo->exec('PRAGMA journal_mode=WAL');
            \DataProtection::register($pdo);
            $migration = new \ReflectionMethod(\src\Core\DatabaseInitializer::class, 'runMigration');
            $schema = (string) file_get_contents(ROOT_PATH . '/database/schema.sql');
            for ($version = 1; $version <= 20; $version++) {
                $migration->invoke(null, $pdo, $version, $schema);
            }
            for ($worker = 0; $worker < 3; $worker++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/migration-race.php', $path, $barrier, (string) $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            for ($attempt = 0; $attempt < 1000; $attempt++) {
                if (is_file($barrier . '.0') && is_file($barrier . '.1') && is_file($barrier . '.2')) {
                    break;
                }
                usleep(10000);
            }
            $this->assertFileExists($barrier . '.2');
            file_put_contents($barrier, 'go');
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $this->assertSame('OK', $output);
            }
            $workers = [];
            $this->assertSame(21, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
            $this->assertSame(21, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
            $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                    proc_close($process);
                }
            }
            $pdo = null;
            foreach ([$path, $path . '-wal', $path . '-shm', $barrier, $barrier . '.0', $barrier . '.1', $barrier . '.2'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testRevisionTriggerTerminatesWithRecursiveTriggersEnabled(): void
    {
        $id = $this->insertTurma();
        $this->pdo->exec('PRAGMA recursive_triggers=ON');
        $this->pdo->prepare('UPDATE turmas SET atualizado_em=atualizado_em WHERE id=?')->execute([$id]);
        $this->assertSame(2, (int) (new \Turma())->buscarPorId($id)['revisao']);
    }

    public function testReadOnlySchemaCheckAcceptsCurrentDatabase(): void
    {
        $before = $this->pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
        \src\Core\DatabaseInitializer::assertCurrent($this->pdo);
        $this->assertSame(22, \src\Core\DatabaseInitializer::latestVersion());
        $this->assertSame($before, $this->pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll());
    }

    public function testReadOnlySchemaCheckRejectsMissingMigrationWithoutApplyingIt(): void
    {
        $this->pdo->exec('DELETE FROM schema_migrations WHERE version=21');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manutenção');
        try {
            \src\Core\DatabaseInitializer::assertCurrent($this->pdo);
        } finally {
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=21')->fetchColumn());
        }
    }
}
