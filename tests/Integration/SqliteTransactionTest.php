<?php

declare(strict_types=1);

namespace Tests\Integration;

use RuntimeException;
use src\Core\SqliteTransaction;
use Tests\Support\DatabaseTestCase;

final class SqliteTransactionTest extends DatabaseTestCase
{
    public function testRollbackPreservesOriginalExceptionAndConnectionRemainsReusable(): void
    {
        $original = new RuntimeException('falha controlada');

        try {
            SqliteTransaction::immediate($this->pdo, function () use ($original): void {
                $this->pdo->exec(
                    "INSERT INTO usuarios (nome, email, senha, tipo, ativo)
                     VALUES ('Registro parcial', 'parcial@teste.local', 'hash', 'funcionario', 1)"
                );

                throw $original;
            });
            $this->fail('A exceção original deveria ter sido propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception);
        }

        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'parcial@teste.local'")->fetchColumn()
        );

        $result = SqliteTransaction::immediate($this->pdo, function (): string {
            $this->pdo->exec(
                "INSERT INTO usuarios (nome, email, senha, tipo, ativo)
                 VALUES ('Registro valido', 'valido@teste.local', 'hash', 'funcionario', 1)"
            );

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'valido@teste.local'")->fetchColumn()
        );
    }

    public function testConsecutiveFailuresDoNotLeaveAnOpenTransaction(): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                SqliteTransaction::immediate($this->pdo, static function (): void {
                    throw new RuntimeException('rollback esperado');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('rollback esperado', $exception->getMessage());
            }
        }

        $this->assertSame(
            1,
            SqliteTransaction::immediate($this->pdo, static fn (): int => 1)
        );
    }
}
