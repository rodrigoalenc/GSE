<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class OwnProfileTest extends DatabaseTestCase
{
    public function testBothRolesCanChangeNameWithPasswordWithoutChangingPrivilegesOrSessionVersion(): void
    {
        $model = new \Usuario();
        foreach (['administrador', 'funcionario'] as $role) {
            $id = $this->insertUsuario('Perfil ' . $role, $role);
            $before = $model->buscarPorId($id);
            $result = $model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Nome corrigido ' . $role, (string) $before['email'], 'Frase segura 2026');
            $this->assertSame(['nome' => 'Nome corrigido ' . $role, 'email_alterado' => false], $result);
            $after = $model->buscarPorId($id);
            foreach (['tipo', 'ativo', 'senha', 'session_version', 'recebe_alertas_dva', 'deve_alterar_senha', 'password_changed_at'] as $field) {
                $this->assertSame($before[$field], $after[$field]);
            }
            $audit = $this->pdo->query("SELECT * FROM security_audit WHERE action = 'user.profile_updated' AND actor_user_id = {$id}")->fetch();
            $this->assertSame($id, (int) $audit['actor_user_id']);
            $this->assertSame($id, (int) $audit['target_user_id']);
            $this->assertSame($id, (int) $audit['resource_id']);
        }
    }

    public function testEmailChangeNormalizesAddressAndInvalidatesEveryPreviousSession(): void
    {
        $id = $this->insertUsuario('Perfil email', 'funcionario');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        $_SESSION = ['usuario_id' => $id, 'auth_version' => (int) $before['session_version']];
        $result = $model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Perfil email', ' NOVO@example.test ', 'Frase segura 2026');
        $this->assertSame(['nome' => 'Perfil email', 'email_alterado' => true], $result);
        $after = $model->buscarPorId($id);
        $this->assertSame('novo@example.test', $after['email']);
        $this->assertSame((int) $before['session_version'] + 1, (int) $after['session_version']);
        $this->assertSame($before['senha'], $after['senha']);
        $this->assertFalse(\Auth::check());
        $this->assertFalse(\Auth::attempt((string) $before['email'], 'Frase segura 2026'));
        $this->assertTrue(\Auth::attempt('novo@example.test', 'Frase segura 2026'));
    }

    public function testNameChangeOnLegacyMixedCaseEmailKeepsEquivalentAddressAndSessionValid(): void
    {
        $id = $this->insertUsuario('Perfil email legado', 'funcionario');
        $this->pdo->prepare('UPDATE usuarios SET email = ? WHERE id = ?')->execute(['LEGADO@EXAMPLE.TEST', $id]);
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        $_SESSION = ['usuario_id' => $id, 'auth_version' => (int) $before['session_version']];

        $result = $model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Nome corrigido', ' LEGADO@EXAMPLE.TEST ', 'Frase segura 2026');

        $this->assertSame(['nome' => 'Nome corrigido', 'email_alterado' => false], $result);
        $after = $model->buscarPorId($id);
        $this->assertSame('legado@example.test', $after['email']);
        $this->assertSame($before['session_version'], $after['session_version']);
        $this->assertSame($before['senha'], $after['senha']);
        $this->assertTrue(\Auth::check());
        $this->assertSame('Nome corrigido', $_SESSION['usuario_nome']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action = 'user.profile_updated' AND actor_user_id = {$id}")->fetchColumn());
    }

    public function testIncorrectOrMissingCurrentPasswordLeavesAccountAndAuditUnchanged(): void
    {
        $id = $this->insertUsuario('Perfil senha');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        foreach (['', 'Senha incorreta 2026', str_repeat('x', 129), "senha\0invalida"] as $password) {
            $this->assertFalse($model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Outro nome', 'outro@example.test', $password));
            $this->assertSame('current_password_invalid', $model->lastErrorCode());
            $this->assertSame($before, $model->buscarPorId($id));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }

    public function testDuplicateEmailIsCaseInsensitiveAndInactiveAccountsStillReserveTheirAddress(): void
    {
        $id = $this->insertUsuario('Perfil duplicado');
        $occupied = $this->insertUsuario('Endereco reservado', 'funcionario', false);
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        $reserved = $model->buscarPorId($occupied);
        $this->assertFalse($model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Outro nome', strtoupper((string) $reserved['email']), 'Frase segura 2026'));
        $this->assertSame('duplicate_email', $model->lastErrorCode());
        $this->assertSame($before, $model->buscarPorId($id));
        $this->assertSame($reserved, $model->buscarPorId($occupied));
    }

    public function testStaleInactiveAndTemporaryPasswordAccountsCannotUpdateProfile(): void
    {
        $id = $this->insertUsuario('Perfil sessao', 'funcionario');
        $model = new \Usuario();
        foreach (['session_version = session_version + 1', 'ativo = 0', 'ativo = 1, deve_alterar_senha = 1'] as $update) {
            $this->pdo->exec('UPDATE usuarios SET ' . $update . ' WHERE id = ' . $id);
            $before = $model->buscarPorId($id);
            $version = str_contains($update, 'session_version') ? (int) $before['session_version'] - 1 : (int) $before['session_version'];
            $this->assertFalse($model->atualizarPerfilProprio($id, $version, 'Outro nome', 'outro@example.test', 'Frase segura 2026'));
            $this->assertSame('session_invalid', $model->lastErrorCode());
            $this->assertSame($before, $model->buscarPorId($id));
        }
    }

    public function testAuditFailureRollsBackIdentityAndSessionVersionInOneTransaction(): void
    {
        $id = $this->insertUsuario('Perfil auditoria');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        $this->pdo->exec("CREATE TRIGGER fail_profile_audit BEFORE INSERT ON security_audit WHEN NEW.action = 'user.profile_updated' BEGIN SELECT RAISE(ABORT, 'forced'); END");
        $this->assertFalse($model->atualizarPerfilProprio($id, (int) $before['session_version'], 'Outro nome', 'outro@example.test', 'Frase segura 2026'));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($before, $model->buscarPorId($id));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }

    public function testInvalidIdentityIsRejectedWithoutChangingAccount(): void
    {
        $id = $this->insertUsuario('Perfil validacao');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);
        foreach ([['', 'valido@example.test'], [str_repeat('a', 151), 'valido@example.test'], ['Valido', 'invalido'], ["Nome\xFF", 'valido@example.test']] as [$name, $email]) {
            $this->assertFalse($model->atualizarPerfilProprio($id, (int) $before['session_version'], $name, $email, 'Frase segura 2026'));
            $this->assertSame('invalid_data', $model->lastErrorCode());
            $this->assertSame($before, $model->buscarPorId($id));
        }
    }
}
