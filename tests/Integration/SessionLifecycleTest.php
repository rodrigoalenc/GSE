<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class SessionLifecycleTest extends DatabaseTestCase
{
    public function testIdleAndAbsoluteTimeoutsAreIndependent(): void
    {
        $_SESSION = [
            'usuario_id' => 1,
            'auth_started_at' => 1000,
            'last_activity' => 2000,
        ];

        $this->assertSame('idle', \SessionManager::expirationReason(4001));

        $_SESSION['last_activity'] = 29_000;
        $this->assertSame('absolute', \SessionManager::expirationReason(29_801));
    }

    public function testPeriodicRenewalUpdatesMarkerAndCsrf(): void
    {
        $_SESSION = [
            'usuario_id' => 1,
            'session_renewed_at' => 1000,
            'last_activity' => 1000,
            '_csrf_token' => str_repeat('a', 64),
        ];

        $this->assertTrue(\SessionManager::touchAndRenew(2000));
        $this->assertSame(2000, $_SESSION['session_renewed_at']);
        $this->assertSame(2000, $_SESSION['last_activity']);
        $this->assertNotSame(str_repeat('a', 64), $_SESSION['_csrf_token']);
    }

    public function testPasswordRoleAndDeactivationInvalidatePreviousSessions(): void
    {
        $id = $this->insertUsuario('Sessao Alvo', 'funcionario', true, 'Frase sessão 2026');
        $model = new \Usuario();
        $before = $model->buscarPorId($id);

        $_SESSION = [
            'usuario_id' => $id,
            'auth_version' => (int) $before['session_version'],
            'usuario_tipo' => 'funcionario',
        ];
        $this->assertTrue($model->alterarSenha($id, 'Frase sessão 2026', 'Nova frase sessão 2027'));
        $this->assertFalse(\Auth::check());

        $updated = $model->buscarPorId($id);
        $_SESSION = [
            'usuario_id' => $id,
            'auth_version' => (int) $updated['session_version'],
            'usuario_tipo' => 'funcionario',
        ];
        $this->assertTrue($model->atualizar($id, 'Sessao Alvo', 'sessao.alvo@teste.local', 'administrador'));
        $this->assertFalse(\Auth::check());

        $updated = $model->buscarPorId($id);
        $_SESSION = [
            'usuario_id' => $id,
            'auth_version' => (int) $updated['session_version'],
            'usuario_tipo' => 'administrador',
        ];
        $this->insertUsuario('Outro Administrador');
        $this->assertTrue($model->definirAtivo($id, false));
        $this->assertFalse(\Auth::check());
    }

    public function testTemporaryPasswordFlowClearsFlagAndForcesNewLogin(): void
    {
        $model = new \Usuario();
        $this->assertTrue($model->cadastrar(
            'Primeiro Acesso',
            'primeiro@example.test',
            'Temporária inicial 2026',
            \Usuario::PERFIL_ADMINISTRADOR,
            true
        ));
        $created = $model->buscarPorEmail('primeiro@example.test');
        $this->assertSame(1, (int) $created['deve_alterar_senha']);
        $this->assertTrue(\Auth::attempt('primeiro@example.test', 'Temporária inicial 2026'));
        $this->assertTrue(\Auth::mustChangePassword());
        $oldVersion = (int) $_SESSION['auth_version'];

        $this->assertTrue($model->alterarSenha((int) $created['id'], 'Temporária inicial 2026', 'Frase definitiva 2027'));
        $changed = $model->buscarPorId((int) $created['id']);
        $this->assertSame(0, (int) $changed['deve_alterar_senha']);
        $this->assertNotNull($changed['password_changed_at']);
        $this->assertGreaterThan($oldVersion, (int) $changed['session_version']);
        $this->assertFalse(\Auth::check());
        $this->assertTrue(\Auth::attempt('primeiro@example.test', 'Frase definitiva 2027'));
        $this->assertFalse(\Auth::mustChangePassword());
    }
}
