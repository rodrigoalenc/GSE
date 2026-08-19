<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use Tests\Support\DatabaseTestCase;

final class AdminIntegrityTest extends DatabaseTestCase
{
    public function testDatabaseTriggerAtomicallyProtectsLastAdministrator(): void
    {
        $id = $this->insertUsuario('Administrador Atomico');
        $statement = $this->pdo->prepare('UPDATE usuarios SET tipo = :role WHERE id = :id');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('last_active_admin');
        $statement->execute(['role' => 'funcionario', 'id' => $id]);
    }

    public function testImmediateTransactionPreventsConcurrentAdministrativeWrite(): void
    {
        $this->insertUsuario('Administrador Concorrente');
        $path = (string) $this->pdo->query('PRAGMA database_list')->fetch()['file'];
        $second = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second->exec('PRAGMA busy_timeout = 50');
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $second->exec("UPDATE usuarios SET tipo = 'funcionario' WHERE id = 1");
            $this->fail('A escrita concorrente deveria ser recusada durante o lock imediato.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('locked', strtolower($exception->getMessage()));
        } finally {
            $this->pdo->exec('ROLLBACK');
        }

        $this->assertSame('administrador', $this->pdo->query('SELECT tipo FROM usuarios WHERE id = 1')->fetchColumn());
    }

    public function testDatabaseAlsoPreventsDeletingLastActiveAdministrator(): void
    {
        $this->insertUsuario('Administrador Nao Excluivel');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('last_active_admin');
        $this->pdo->exec('DELETE FROM usuarios WHERE id = 1');
    }
}
