<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PasswordSecurityTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        \Tests\Support\PasswordRuntime::inject(null);
        parent::tearDown();
    }

    public function testAmbiguousBcryptCannotLoginChangePasswordOrConfirmProfile(): void
    {
        foreach ([str_repeat('a', 72), str_repeat('á', 36)] as $prefix) {
            $this->pdo->exec('DELETE FROM login_attempts');
            $id = $this->insertUsuario('Legado ' . strlen($prefix) . bin2hex(random_bytes(3)), 'funcionario');
            $hash = password_hash($prefix, PASSWORD_BCRYPT, ['cost' => 4]);
            $this->pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$hash, $id]);
            $model = new \Usuario();
            $before = $model->buscarPorId($id);
            $this->assertIsArray($before);
            $ambiguous = $prefix . ' sufixo ignorado';
            $this->assertTrue(password_verify($ambiguous, $hash), 'Reproduce native bcrypt truncation.');
            $this->assertFalse(\Auth::attempt((string) $before['email'], $ambiguous));
            $this->assertSame(\Auth::ATTEMPT_INVALID, \Auth::lastAttemptStatus());
            $this->assertArrayNotHasKey('usuario_id', $_SESSION);
            $this->assertFalse($model->alterarSenha($id, $ambiguous, 'Outra frase segura 2027'));
            $this->assertSame('current_password_invalid', $model->lastErrorCode());
            $this->assertFalse($model->atualizarPerfilProprio($id, 1, 'Novo nome', 'novo@example.test', $ambiguous));
            $this->assertSame($before, $model->buscarPorId($id));
            $this->assertSame(['login.invalid'], $this->pdo->query('SELECT action FROM security_audit')->fetchAll(\PDO::FETCH_COLUMN));
            $status = (new \LoginThrottle())->status((string) $before['email'], '127.0.0.1');
            $this->assertSame(1, $status['account_failures']);
            $this->pdo->exec('DELETE FROM security_audit');
        }
    }

    public function testLegacy72BytePasswordMigratesAndLongArgon2idStillAuthenticates(): void
    {
        $password = str_repeat('á', 36);
        $id = $this->insertUsuario('Legado limite', 'funcionario');
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$hash, $id]);
        $this->assertTrue(\Auth::attempt('legado.limite@teste.local', $password));
        $row = (new \Usuario())->buscarPorId($id);
        $this->assertSame('argon2id', password_get_info($row['senha'])['algoName']);
        $this->assertSame(1, (int) $row['session_version']);
        $this->assertTrue(\Auth::check());

        $long = str_repeat('界', 100);
        $longId = $this->insertUsuario('Unicode longo', 'funcionario', true, $long);
        $this->assertTrue(\Auth::attempt('unicode.longo@teste.local', $long));
        $this->assertSame($longId, $_SESSION['usuario_id']);
    }

    public function testInvalidUtf8NullAndOversizedInputNeverAuthenticates(): void
    {
        $id = $this->insertUsuario('Entrada invalida', 'funcionario');
        $before = (new \Usuario())->buscarPorId($id);
        foreach (["Frase segura 2026\0", "Frase segura 2026\xff", str_repeat('a', 129)] as $input) {
            $this->assertFalse(\Auth::attempt('entrada.invalida@teste.local', $input));
            $this->assertArrayNotHasKey('usuario_id', $_SESSION);
        }
        $this->assertSame($before, (new \Usuario())->buscarPorId($id));
    }

    public function testUnavailableAlgorithmAndGenerationFailurePreserveAllPasswordMutations(): void
    {
        $id = $this->insertUsuario('Conta existente', 'funcionario');
        $model = new \Usuario();
        $before = $this->snapshot();
        foreach (['unavailable', 'failure'] as $mode) {
            $this->injectFailure($mode);
            $this->assertFalse($model->cadastrar('Nova conta', 'nova@example.test', 'Nova frase segura 2027', 'funcionario'));
            $this->assertSame($before, $this->snapshot());
            $this->assertFalse($model->criarAdministradorInicial('Primeiro admin', 'primeiro@example.test', 'Nova frase segura 2027'));
            $this->assertSame($before, $this->snapshot());
            $this->assertFalse($model->atualizar($id, 'Nome alterado', 'alterado@example.test', 'administrador', 'Nova frase segura 2027', true));
            $this->assertSame($before, $this->snapshot());
            $this->assertFalse($model->atualizar($id, 'Nome alterado', 'alterado@example.test', 'administrador', 'Nova frase segura 2027', true,
                $id, 'Frase segura 2026'));
            $this->assertSame($before, $this->snapshot());
            $this->assertFalse($model->alterarSenha($id, 'Frase segura 2026', 'Nova frase segura 2027'));
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function testProfileConfirmationDoesNotDependOnGeneratingANewHash(): void
    {
        $id = $this->insertUsuario('Perfil existente', 'funcionario');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        foreach (['unavailable', 'failure'] as $mode) {
            $this->injectFailure($mode);
            $this->assertNotFalse($model->atualizarPerfilProprio($id, 1, 'Nome ' . $mode, 'perfil.existente@teste.local', 'Frase segura 2026'));
            $after = $model->buscarPorId($id);
            $this->assertSame('Nome ' . $mode, $after['nome']);
            $this->assertSame($before['senha'], $after['senha']);
            $this->assertSame($before['session_version'], $after['session_version']);
        }
    }

    public function testAmbiguousBcryptCannotConfirmAdministrativeBackup(): void
    {
        $id = $this->insertUsuario('Backup legado');
        $prefix = str_repeat('á', 36);
        $hash = password_hash($prefix, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$hash, $id]);
        $before = $this->snapshot();
        $this->assertFalse((new \Sistema())->criarBackupAdministrativo($id, 1, $prefix . ' sufixo'));
        $this->assertSame($before, $this->snapshot());
    }

    public function testFailedOpportunisticRehashKeepsHashAndValidSession(): void
    {
        $id = $this->insertUsuario('Rehash falha', 'funcionario');
        $hash = password_hash('Frase segura 2026', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$hash, $id]);
        foreach (['unavailable', 'failure'] as $mode) {
            $this->injectFailure($mode);
            $this->assertTrue(\Auth::attempt('rehash.falha@teste.local', 'Frase segura 2026'));
            $row = (new \Usuario())->buscarPorId($id);
            $this->assertSame($hash, $row['senha']);
            $this->assertSame(1, (int) $row['session_version']);
            $this->assertTrue(\Auth::check());
        }
        $this->assertSame(['login.success', 'login.success'], $this->pdo->query('SELECT action FROM security_audit')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testActualCliFailsWithoutPartialAccountAndLogsContainNoSecrets(): void
    {
        $modeFile = (string) getenv('DB_PATH') . '.password-runtime';
        $log = (string) getenv('DB_PATH') . '.technical.log';
        $secret = 'Segredo CLI ficticio 2027';
        try {
            foreach (['unavailable', 'failure'] as $mode) {
                file_put_contents($modeFile, $mode);
                $environment = array_merge(getenv(), ['LOG_PATH' => $log, 'GSE_ADMIN_PASSWORD' => $secret]);
                $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . ROOT_PATH . '/tests/fixtures/password-runtime.php',
                    ROOT_PATH . '/bin/create-admin.php', '--name=Admin teste', '--email=cli@example.test'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, $environment);
                $this->assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(1, proc_close($process));
                $this->assertStringContainsString('Argon2id', $stderr);
                $this->assertSame('', $stdout);
                $this->assertSame(['users' => [], 'audit' => []], $this->snapshot());
                $contents = (is_file($log) ? file_get_contents($log) : '') . $stdout . $stderr;
                $this->assertStringNotContainsString($secret, $contents);
                $this->assertStringNotContainsString('Artificial private detail', $contents);
                $this->assertDoesNotMatchRegularExpression('/\$argon2|\$2[aby]\$|cookie|token/i', $contents);
            }
        } finally {
            @unlink($modeFile);
            @unlink($log);
        }
    }

    private function injectFailure(string $mode): void
    {
        \Tests\Support\PasswordRuntime::inject($mode);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'users' => $this->pdo->query('SELECT * FROM usuarios ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
            'audit' => $this->pdo->query('SELECT * FROM security_audit ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }
}
