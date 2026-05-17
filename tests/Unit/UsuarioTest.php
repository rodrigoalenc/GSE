<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\DatabaseTestCase;

final class UsuarioTest extends DatabaseTestCase
{
    public function testCrudCompletoDoUsuario(): void
    {
        $usuario = new \Usuario();

        $this->assertTrue($usuario->cadastrar('Maria Silva', 'maria@example.com', 'segredo123', 'admin'));

        $registro = $usuario->buscarPorEmail('maria@example.com');
        $this->assertSame('Maria Silva', $registro['nome'] ?? null);
        $this->assertTrue(password_verify('segredo123', $registro['senha'] ?? ''));
        $this->assertSame('Maria Silva', $usuario->buscarPorId($registro['id'])['nome'] ?? null);
        $this->assertCount(1, $usuario->listar());

        $this->assertTrue($usuario->atualizar($registro['id'], 'Maria Atualizada', 'maria2@example.com', 'usuario', 'nova123'));
        $atualizado = $usuario->buscarPorId($registro['id']);
        $this->assertSame('usuario', $atualizado['tipo'] ?? null);
        $this->assertTrue(password_verify('nova123', $atualizado['senha'] ?? ''));

        $this->assertTrue($usuario->atualizarPerfil($registro['id'], 'Maria Perfil', 'perfil@example.com'));
        $this->assertSame('Maria Perfil', $usuario->buscarPorId($registro['id'])['nome'] ?? null);

        $this->assertTrue($usuario->excluir($registro['id']));
        $this->assertFalse($usuario->buscarPorId($registro['id']));
    }
}
