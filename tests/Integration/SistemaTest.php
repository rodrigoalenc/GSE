<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class SistemaTest extends DatabaseTestCase
{
    private ?string $backupPath = null;

    protected function tearDown(): void
    {
        if ($this->backupPath && is_file($this->backupPath)) {
            @unlink($this->backupPath);
        }

        parent::tearDown();
    }

    public function testLogsEBackup(): void
    {
        $this->pdo->exec("INSERT INTO logs (data_hora, usuario, acao, detalhes) VALUES (date('now', '-400 days'), 'u', 'antigo', 'x')");
        $this->pdo->exec("INSERT INTO logs (data_hora, usuario, acao, detalhes) VALUES (date('now'), 'u', 'novo', 'x')");

        $sistema = new \Sistema();

        $this->assertCount(1, $sistema->listarLogs(1));
        $this->assertSame(1, $sistema->limparLogsAntigos(365));
        $this->assertCount(1, $sistema->listarLogs(10));

        $backup = $sistema->criarBackupManual();
        $this->assertIsString($backup);
        $this->assertNotSame('', $backup);

        $this->backupPath = ROOT_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $backup;
        $backups = array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            $sistema->listarBackups()
        );

        $this->assertContains(str_replace('\\', '/', $this->backupPath), $backups);
    }
}
