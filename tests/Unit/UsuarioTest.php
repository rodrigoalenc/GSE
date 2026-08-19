<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\DatabaseTestCase;

final class UsuarioTest extends DatabaseTestCase
{
    public function testCrudComInativacaoDoUsuario(): void
    {
        $usuario = new \Usuario();

        $this->assertTrue($usuario->cadastrar(
            'Maria Silva',
            'maria@example.com',
            'Frase segura 2026',
            \Usuario::PERFIL_FUNCIONARIO
        ));

        $registro = $usuario->buscarPorEmail('MARIA@example.com');
        $this->assertSame('Maria Silva', $registro['nome'] ?? null);
        $this->assertTrue(password_verify('Frase segura 2026', $registro['senha'] ?? ''));
        $this->assertCount(1, $usuario->listar('maria'));
        $this->assertSame([
            'total' => 1,
            'ativos' => 1,
            'inativos' => 0,
            'administradores' => 0,
        ], $usuario->estatisticas());

        $this->assertTrue($usuario->atualizar(
            (int) $registro['id'],
            'Maria Atualizada',
            'maria2@example.com',
            \Usuario::PERFIL_FUNCIONARIO,
            'Nova frase segura 2027'
        ));
        $atualizado = $usuario->buscarPorId((int) $registro['id']);
        $this->assertTrue(password_verify('Nova frase segura 2027', $atualizado['senha'] ?? ''));

        $this->assertTrue($usuario->definirAtivo((int) $registro['id'], false));
        $this->assertSame(0, (int) $usuario->buscarPorId((int) $registro['id'])['ativo']);
        $this->assertSame(1, $usuario->estatisticas()['inativos']);
        $this->assertTrue($usuario->definirAtivo((int) $registro['id'], true));
    }

    public function testRejeitaDadosInvalidosEDuplicidadeDeEmail(): void
    {
        $usuario = new \Usuario();

        $this->assertFalse($usuario->cadastrar('', 'invalido', '123', 'outro'));
        $this->assertTrue($usuario->cadastrar(
            'Primeiro',
            'conta@example.com',
            'Frase de teste 2026',
            \Usuario::PERFIL_FUNCIONARIO
        ));
        $this->assertFalse($usuario->cadastrar(
            'Duplicado',
            'CONTA@example.com',
            'Outra frase teste 2027',
            \Usuario::PERFIL_FUNCIONARIO
        ));
    }

    public function testProtegePropriaContaEUltimoAdministrador(): void
    {
        $id = $this->insertUsuario('Administrador Unico');
        $usuario = new \Usuario();

        $this->assertFalse($usuario->definirAtivo($id, false, $id));
        $this->assertFalse($usuario->definirAtivo($id, false));
        $this->assertFalse($usuario->atualizar(
            $id,
            'Administrador Unico',
            'administrador.unico@teste.local',
            \Usuario::PERFIL_FUNCIONARIO
        ));

        $this->insertUsuario('Segundo Administrador');
        $this->assertTrue($usuario->definirAtivo($id, false));
        $this->assertTrue($usuario->definirAtivo($id, true));
        $this->assertTrue($usuario->atualizar(
            $id,
            'Administrador Rebaixado',
            'administrador.rebaixado@teste.local',
            \Usuario::PERFIL_FUNCIONARIO
        ));
        $this->assertTrue($usuario->alterarSenha($id, 'Frase segura 2026', 'Nova frase posterior 2027'));
    }
}
