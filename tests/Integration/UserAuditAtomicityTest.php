<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

final class UserAuditAtomicityTest extends DatabaseTestCase
{
    public function testDirectCreationRequiresAuditEvenWithoutAnActor(): void
    {
        $model = new \Usuario();
        $before = $this->snapshot();
        $this->rejectAudit('user.created');
        $this->assertFalse($model->cadastrar('Administrador Inicial', 'inicial@example.test', 'Frase inicial segura 2027', 'administrador'));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->cadastrar('Administrador Inicial', 'inicial@example.test', 'Frase inicial segura 2027', 'administrador'));
        $created = $model->buscarPorEmail('inicial@example.test');
        $this->assertIsArray($created);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('user.created', $events[0]['action']);
        $this->assertNull($events[0]['actor_user_id']);
        $this->assertSame((int) $created['id'], (int) $events[0]['target_user_id']);
        $this->assertSame((int) $created['id'], (int) $events[0]['resource_id']);
        $this->assertSame(1, (int) $created['deve_alterar_senha']);
    }

    public function testAdministrativeCreationRecordsTheExplicitActorAndNeverSecrets(): void
    {
        $actor = $this->insertUsuario('Autor administrativo');
        $_SESSION['usuario_id'] = $this->insertUsuario('Sessao diferente');
        $password = 'Segredo exclusivo ficticio 2027';
        $model = new \Usuario();
        $this->assertTrue($model->cadastrar('Conta criada', 'conta@example.test', $password, 'funcionario', true, false, $actor));
        $created = $model->buscarPorEmail('conta@example.test');
        $this->assertIsArray($created);
        $event = $this->events()[0];
        $this->assertSame($actor, (int) $event['actor_user_id']);
        $this->assertSame((int) $created['id'], (int) $event['target_user_id']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $encoded);
        $this->assertStringNotContainsString((string) $created['senha'], $encoded);
        foreach (['senha', 'password', 'cookie', 'csrf_token', 'session_id'] as $field) {
            $this->assertArrayNotHasKey($field, $event);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function administrativeEvents(): array
    {
        return [
            'identidade' => ['user.identity_updated'],
            'perfil' => ['user.role_updated'],
            'senha temporaria' => ['password.reset'],
            'preferencia depois dos outros eventos' => ['user.dva_alerts_updated'],
        ];
    }

    #[DataProvider('administrativeEvents')]
    public function testEveryAdministrativeAuditFailureRollsBackTheWholeEdit(string $action): void
    {
        $actor = $this->insertUsuario('Autor edicao');
        $target = $this->insertUsuario('Alvo edicao');
        $this->pdo->exec('UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id = ' . $target);
        $model = new \Usuario();
        $before = $this->snapshot();
        $this->rejectAudit($action);
        $this->assertFalse($model->atualizar($target, 'Nome alterado', 'alterado@example.test', 'funcionario', 'Nova frase operacional 2027', false, $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->atualizar($target, 'Nome alterado', 'alterado@example.test', 'funcionario', 'Nova frase operacional 2027', false, $actor));
        $after = $model->buscarPorId($target);
        $this->assertIsArray($after);
        $this->assertSame('funcionario', $after['tipo']);
        $this->assertSame(0, (int) $after['recebe_alertas_dva']);
        $this->assertSame(1, (int) $after['deve_alterar_senha']);
        $this->assertNull($after['password_changed_at']);
        $this->assertSame((int) $before['usuarios'][1]['session_version'] + 1, (int) $after['session_version']);
        $this->assertTrue(password_verify('Nova frase operacional 2027', (string) $after['senha']));
        $events = $this->events();
        $this->assertSame(['user.identity_updated', 'user.role_updated', 'password.reset', 'user.dva_alerts_updated'], array_column($events, 'action'));
        foreach ($events as $event) {
            $this->assertSame($actor, (int) $event['actor_user_id']);
            $this->assertSame($target, (int) $event['target_user_id']);
            $this->assertSame($target, (int) $event['resource_id']);
            $this->assertStringNotContainsString('Nova frase operacional 2027', (string) $event['description']);
        }
    }

    public function testIsolatedPreferenceChangeIsAtomicAndRepeatedValuesProduceNoEvents(): void
    {
        $actor = $this->insertUsuario('Autor preferencias');
        $model = new \Usuario();
        $before = $model->buscarPorId($actor);
        $this->assertIsArray($before);
        $this->rejectAudit('user.dva_alerts_updated');
        $snapshot = $this->snapshot();
        $this->assertFalse($model->atualizar($actor, (string) $before['nome'], (string) $before['email'], 'administrador', null, true));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($snapshot, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->atualizar($actor, (string) $before['nome'], (string) $before['email'], 'administrador', null, true, $actor));
        $after = $model->buscarPorId($actor);
        $this->assertSame($before['session_version'], $after['session_version']);
        $this->assertSame(1, (int) $after['recebe_alertas_dva']);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('user.dva_alerts_updated', $events[0]['action']);
        $snapshot = $this->snapshot();
        $this->assertTrue($model->atualizar($actor, (string) $after['nome'], (string) $after['email'], 'administrador', null, true, $actor));
        $this->assertTrue($model->definirAtivo($actor, true, $actor));
        $this->assertSame($snapshot, $this->snapshot());
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function statusEvents(): array
    {
        return [
            'inativacao' => ['user.deactivated', false],
            'optout automatico' => ['user.dva_alerts_updated', false],
            'reativacao' => ['user.activated', true],
        ];
    }

    #[DataProvider('statusEvents')]
    public function testStatusAndAutomaticOptOutRollBackTogetherWhenAuditFails(string $action, bool $active): void
    {
        $actor = $this->insertUsuario('Autor situacao');
        $target = $this->insertUsuario('Alvo situacao', 'administrador', !$active);
        $this->pdo->exec('UPDATE usuarios SET recebe_alertas_dva = ' . ($active ? '0' : '1') . ' WHERE id = ' . $target);
        $model = new \Usuario();
        $before = $this->snapshot();
        $this->rejectAudit($action);
        $this->assertFalse($model->definirAtivo($target, $active, $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->definirAtivo($target, $active, $actor));
        $after = $model->buscarPorId($target);
        $this->assertSame($active ? 1 : 0, (int) $after['ativo']);
        $this->assertSame((int) $before['usuarios'][1]['session_version'] + 1, (int) $after['session_version']);
        $this->assertSame(0, (int) $after['recebe_alertas_dva']);
        $this->assertSame($active ? ['user.activated'] : ['user.deactivated', 'user.dva_alerts_updated'], array_column($this->events(), 'action'));
    }

    public function testOwnPasswordChangeIsRolledBackWhenItsAuditFails(): void
    {
        $id = $this->insertUsuario('Senha propria');
        $this->pdo->exec('UPDATE usuarios SET deve_alterar_senha = 1 WHERE id = ' . $id);
        $model = new \Usuario();
        $before = $this->snapshot();
        $this->rejectAudit('password.changed');
        $this->assertFalse($model->alterarSenha($id, 'Frase segura 2026', 'Nova frase operacional 2027'));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->alterarSenha($id, 'Frase segura 2026', 'Nova frase operacional 2027'));
        $after = $model->buscarPorId($id);
        $this->assertSame(0, (int) $after['deve_alterar_senha']);
        $this->assertNotNull($after['password_changed_at']);
        $this->assertSame((int) $before['usuarios'][0]['session_version'] + 1, (int) $after['session_version']);
        $this->assertTrue(password_verify('Nova frase operacional 2027', (string) $after['senha']));
        $event = $this->events()[0];
        $this->assertSame('password.changed', $event['action']);
        $this->assertSame($id, (int) $event['actor_user_id']);
        $this->assertSame($id, (int) $event['target_user_id']);
    }

    public function testPasswordRehashRequiresAuditWithoutInvalidatingSessions(): void
    {
        $id = $this->insertUsuario('Senha rehash');
        $model = new \Usuario();
        $hash = \PasswordPolicy::hash('Frase segura 2026');
        $before = $this->snapshot();
        $this->rejectAudit('password.rehashed');
        $this->assertFalse($model->atualizarSenhaHash($id, $hash));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());

        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $this->assertTrue($model->atualizarSenhaHash($id, $hash));
        $after = $model->buscarPorId($id);
        $this->assertSame($hash, $after['senha']);
        foreach (['session_version', 'password_changed_at', 'deve_alterar_senha', 'recebe_alertas_dva', 'tipo', 'ativo'] as $field) {
            $this->assertSame($before['usuarios'][0][$field], $after[$field]);
        }
        $event = $this->events()[0];
        $this->assertSame('password.rehashed', $event['action']);
        $this->assertSame($id, (int) $event['actor_user_id']);
        $this->assertStringNotContainsString($hash, json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertTrue($model->atualizarSenhaHash($id, $hash));
        $this->assertCount(1, $this->events());
    }

    public function testLastAdministratorAndValidationGuardsDoNotProduceSuccessEvents(): void
    {
        $id = $this->insertUsuario('Ultimo administrador');
        $model = new \Usuario();
        $before = $this->snapshot();
        $this->assertFalse($model->definirAtivo($id, false, $id));
        $this->assertSame('self_deactivation', $model->lastErrorCode());
        $this->assertFalse($model->definirAtivo($id, false));
        $this->assertSame('last_active_admin', $model->lastErrorCode());
        $this->assertFalse($model->atualizar($id, 'Ultimo administrador', 'ultimo.administrador@teste.local', 'funcionario'));
        $this->assertSame('last_active_admin', $model->lastErrorCode());
        $this->assertFalse($model->alterarSenha($id, 'Senha incorreta', 'Nova frase operacional 2027'));
        $this->assertSame('current_password_invalid', $model->lastErrorCode());
        $this->assertSame($before, $this->snapshot());
    }

    public function testAStaleLoginRehashCannotRestoreThePasswordAfterAnAdministrativeReset(): void
    {
        $actor = $this->insertUsuario('Autor reset concorrente');
        $target = $this->insertUsuario('Alvo reset concorrente', 'funcionario');
        $model = new \Usuario();
        $verified = $model->buscarPorId($target);
        $this->assertIsArray($verified);
        $oldHash = (string) $verified['senha'];
        $pendingRehash = \PasswordPolicy::hash('Frase segura 2026');
        $this->assertTrue($model->atualizar($target, (string) $verified['nome'], (string) $verified['email'], 'funcionario',
            'Nova frase operacional 2027', null, $actor));
        $afterReset = $this->snapshot();
        $this->assertFalse($model->atualizarSenhaHash($target, $pendingRehash, $oldHash));
        $this->assertSame('rehash_stale', $model->lastErrorCode());
        $this->assertSame($afterReset, $this->snapshot());
        $current = $model->buscarPorId($target);
        $this->assertTrue(password_verify('Nova frase operacional 2027', (string) $current['senha']));
        $this->assertFalse(password_verify('Frase segura 2026', (string) $current['senha']));
        $this->assertSame(['password.reset'], array_column($this->events(), 'action'));
    }

    public function testARealLoginAuditsItsRehashAndKeepsTheSessionValid(): void
    {
        $id = $this->insertUsuario('Login rehash auditado', 'funcionario');
        $oldHash = password_hash('Frase segura 2026', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$oldHash, $id]);
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        $this->assertIsArray($before);
        $this->assertTrue(\Auth::attempt((string) $before['email'], 'Frase segura 2026'));
        $after = $model->buscarPorId($id);
        $this->assertNotSame($oldHash, $after['senha']);
        $this->assertTrue(password_verify('Frase segura 2026', (string) $after['senha']));
        $this->assertSame($before['session_version'], $after['session_version']);
        $this->assertTrue(\Auth::check());
        $this->assertSame(['login.success', 'password.rehashed'], array_column($this->events(), 'action'));
        $event = $this->events()[1];
        $this->assertSame($id, (int) $event['actor_user_id']);
        $this->assertStringNotContainsString((string) $after['senha'], json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function testTheActualCliCreatesOneAuditedTemporaryAdministratorAndRejectsASecond(): void
    {
        $first = $this->runCli('primeiro@example.test', true);
        $this->assertSame(0, $first['exit'], $first['stderr']);
        $this->assertStringContainsString('Administrador criado com sucesso', $first['stdout']);
        $created = (new \Usuario())->buscarPorEmail('primeiro@example.test');
        $this->assertIsArray($created);
        $this->assertSame(1, (int) $created['deve_alterar_senha']);
        $this->assertSame(1, (int) $created['recebe_alertas_dva']);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('user.initial_admin_created', $events[0]['action']);
        $this->assertNull($events[0]['actor_user_id']);
        $this->assertSame((int) $created['id'], (int) $events[0]['target_user_id']);
        $before = $this->snapshot();
        $second = $this->runCli('segundo@example.test');
        $this->assertSame(1, $second['exit']);
        $this->assertStringContainsString('Já existe um administrador ativo', $second['stderr']);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('Frase inicial segura 2027', $first['stdout'] . $first['stderr'] . $second['stdout'] . $second['stderr']);
    }

    public function testTheActualCliRollsBackCreationWhenMandatoryBootstrapAuditFails(): void
    {
        $before = $this->snapshot();
        $this->rejectAudit('user.initial_admin_created');
        $result = $this->runCli('falha@example.test');
        $this->assertSame(1, $result['exit']);
        $this->assertStringNotContainsString('Administrador criado com sucesso', $result['stdout']);
        $this->assertSame($before, $this->snapshot());
        $this->pdo->exec('DROP TRIGGER reject_user_audit');
        $retry = $this->runCli('falha@example.test');
        $this->assertSame(0, $retry['exit'], $retry['stderr']);
        $this->assertCount(1, $this->events());
    }

    public function testConcurrentCliBootstrapAttemptsCreateOnlyOneAdministrator(): void
    {
        $first = $this->startCli('concorrente.um@example.test');
        $second = $this->startCli('concorrente.dois@example.test');
        $results = [$this->finishCli($first), $this->finishCli($second)];
        $exitCodes = array_column($results, 'exit');
        sort($exitCodes);
        $this->assertSame([0, 1], $exitCodes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, (new \Usuario())->contarAdministradoresAtivos());
        $this->assertCount(1, $this->events());
        $this->assertSame('user.initial_admin_created', $this->events()[0]['action']);
        $loser = $results[0]['exit'] === 1 ? $results[0] : $results[1];
        $this->assertStringContainsString('Já existe um administrador ativo', $loser['stderr']);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runCli(string $email, bool $enableAlerts = false): array
    {
        return $this->finishCli($this->startCli($email, $enableAlerts));
    }

    /** @return array{process:resource,stdout:resource,stderr:resource,log:string} */
    private function startCli(string $email, bool $enableAlerts = false): array
    {
        $command = [PHP_BINARY, ROOT_PATH . '/bin/create-admin.php', '--name=Administrador Ficticio', '--email=' . $email];
        if ($enableAlerts) {
            $command[] = '--enable-dva-alerts';
        }
        $environment = getenv();
        $environment['APP_ENV'] = 'testing';
        $environment['DB_PATH'] = (string) getenv('DB_PATH');
        $environment['LOG_PATH'] = $environment['DB_PATH'] . '.' . bin2hex(random_bytes(5)) . '.log';
        $environment['GSE_ADMIN_PASSWORD'] = 'Frase inicial segura 2027';
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Não foi possível iniciar a CLI de teste.');
        }
        fclose($pipes[0]);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'log' => $environment['LOG_PATH']];
    }

    /**
     * @param array{process:resource,stdout:resource,stderr:resource,log:string} $running
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function finishCli(array $running): array
    {
        $stdout = stream_get_contents($running['stdout']);
        $stderr = stream_get_contents($running['stderr']);
        fclose($running['stdout']);
        fclose($running['stderr']);
        $exitCode = proc_close($running['process']);
        if (is_file($running['log'])) {
            unlink($running['log']);
        }
        return ['exit' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function rejectAudit(string $action): void
    {
        $this->pdo->exec('CREATE TRIGGER reject_user_audit BEFORE INSERT ON security_audit WHEN NEW.action = '
            . $this->pdo->quote($action) . " BEGIN SELECT RAISE(ABORT, 'forced_user_audit_failure'); END");
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        return $this->pdo->query('SELECT * FROM security_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['usuarios', 'security_audit', 'sqlite_sequence'] as $table) {
            $snapshot[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }
}
