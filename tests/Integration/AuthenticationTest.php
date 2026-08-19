<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class AuthenticationTest extends DatabaseTestCase
{
    public function testLoginValidoCriaSessaoELogoutEncerraSessao(): void
    {
        $id = $this->insertUsuario('Admin Login', 'administrador', true, 'Frase login 2026');

        $this->assertTrue(\Auth::attempt('admin.login@teste.local', 'Frase login 2026'));
        $this->assertSame($id, $_SESSION['usuario_id'] ?? null);
        $this->assertSame('Admin Login', $_SESSION['usuario_nome'] ?? null);
        $this->assertSame('administrador', $_SESSION['usuario_tipo'] ?? null);
        $this->assertTrue(\Auth::isAdmin());

        \Auth::logout();
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testLoginInvalidoNaoRevelaNemCriaSessao(): void
    {
        $this->insertUsuario('Conta Valida', 'funcionario', true, 'Frase válida 2026');

        $this->assertFalse(\Auth::attempt('conta.valida@teste.local', 'SenhaErrada1'));
        $this->assertFalse(\Auth::attempt('inexistente@teste.local', 'SenhaErrada1'));
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testUsuarioInativoNaoPodeEntrar(): void
    {
        $this->insertUsuario('Conta Inativa', 'funcionario', false, 'Frase inativa 2026');

        $this->assertFalse(\Auth::attempt('conta.inativa@teste.local', 'Frase inativa 2026'));
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testFuncionarioAutenticadoNaoTemAutorizacaoAdministrativa(): void
    {
        $this->insertUsuario('Funcionario Teste', 'funcionario', true, 'Frase funcionário 2026');

        $this->assertTrue(\Auth::attempt('funcionario.teste@teste.local', 'Frase funcionário 2026'));
        $this->assertFalse(\Auth::isAdmin());
        $this->assertTrue(\Auth::check());
    }
}
