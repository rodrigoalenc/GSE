<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Tests\Support\DatabaseTestCase;

final class SistemaTest extends DatabaseTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . "/gse-sistema-O'Reilly-" . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy((string) $_ENV['DB_PATH'], $this->root . '/live.sqlite');
        $this->pdo = new PDO('sqlite:' . $this->root . '/live.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0');
        \Model::setConexao($this->pdo);
        $this->configurePath($this->root . '/live.sqlite');
    }

    protected function tearDown(): void
    {
        \Model::setConexao(null);
        $this->pdo = new PDO('sqlite::memory:');
        if (isset($this->root) && is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
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
        $backup = $this->backup($sistema);
        $this->assertContains($backup, $sistema->listarBackups());
    }

    public function testRealBackupIncludesUncheckpointedWalRowsLinksSchemaAndAudit(): void
    {
        $this->pdo->exec("INSERT INTO logs (usuario, acao, detalhes) VALUES ('u', 'before', 'x')");
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->pdo->exec("INSERT INTO logs (usuario, acao, detalhes) VALUES ('u', 'committed-in-wal', 'x')");
        $actor = $this->insertUsuario('Autor Backup WAL');
        $class = $this->insertTurma('Turma Backup');
        $student = $this->insertAlunoComDva('Aluno Backup', '2026-12-31', $class);
        \AuditLogger::recordRequired($this->pdo, 'backup.fixture', 'success', $actor, null, 'Sintetico', 'aluno', $student);
        $this->assertGreaterThan(0, filesize($this->root . '/live.sqlite-wal'));
        $before = $this->snapshot($this->pdo);

        // The previous copy() implementation lost a committed row yet passed integrity_check.
        $this->assertTrue(copy($this->root . '/live.sqlite', $this->root . '/old-copy.sqlite'));
        $oldCopy = new PDO('sqlite:' . $this->root . '/old-copy.sqlite');
        $this->assertSame(1, (int) $oldCopy->query('SELECT COUNT(*) FROM logs')->fetchColumn());
        $this->assertSame('ok', $oldCopy->query('PRAGMA integrity_check')->fetchColumn());
        $oldCopy = null;

        $backup = $this->backup(new \Sistema());
        $restored = new PDO('sqlite:' . $backup, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->assertSame(2, (int) $restored->query('SELECT COUNT(*) FROM logs')->fetchColumn());
        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $restored->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame($before, $this->snapshot($restored));
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0700, fileperms(dirname($backup)) & 0777);
            $this->assertSame(0600, fileperms($backup) & 0777);
        }
    }

    public function testRapidBackupsDoNotOverwriteEachOtherAndKeepBasenameApi(): void
    {
        $sistema = new \Sistema();
        $first = $this->backup($sistema);
        $hash = hash_file('sha256', $first);
        $this->pdo->exec("INSERT INTO logs (usuario, acao, detalhes) VALUES ('u', 'later', 'x')");
        $second = $this->backup($sistema);
        $this->assertNotSame($first, $second);
        $this->assertSame($hash, hash_file('sha256', $first));
        $this->assertContains($first, $sistema->listarBackups());
        $this->assertContains($second, $sistema->listarBackups());
        $this->assertMatchesRegularExpression('/^escola_backup_MANUAL_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]{32}\.db$/', basename($second));
        $timestampLength = strlen('escola_backup_MANUAL_') + 19;
        // Retry only across a wall-clock second boundary, keeping all prior files intact.
        for ($attempt = 0; $attempt < 3 && substr(basename($first), 0, $timestampLength) !== substr(basename($second), 0, $timestampLength); $attempt++) {
            $first = $this->backup($sistema);
            $second = $this->backup($sistema);
        }
        $this->assertSame(substr(basename($first), 0, $timestampLength), substr(basename($second), 0, $timestampLength));
        $this->assertNotSame($first, $second);
    }

    public function testConfiguredDbPathIsSourceEvenWhenModelHasAnotherConnection(): void
    {
        $alternative = new PDO('sqlite:' . $this->root . '/configured.sqlite');
        $alternative->exec("CREATE TABLE marker(id INTEGER PRIMARY KEY, value TEXT); INSERT INTO marker VALUES (42, 'configured-source')");
        $this->configurePath($this->root . '/configured.sqlite');
        $backup = $this->backup(new \Sistema());
        $restored = new PDO('sqlite:' . $backup);
        $this->assertSame('configured-source', $restored->query('SELECT value FROM marker WHERE id=42')->fetchColumn());
        $this->assertSame(0, (int) $restored->query("SELECT COUNT(*) FROM sqlite_master WHERE name='usuarios'")->fetchColumn());
    }

    public function testLegacyBackupLocationRemainsDiscoverableWithoutMovingItsFile(): void
    {
        $directory = str_replace('\\', '/', ROOT_PATH) . '/database/backups';
        $created = !is_dir($directory);
        if ($created) {
            mkdir($directory, 0700, true);
        }
        $legacy = $directory . '/fixture-legacy-' . bin2hex(random_bytes(8)) . '.db';
        try {
            file_put_contents($legacy, 'synthetic-legacy-backup');
            $sistema = new \Sistema();
            $this->assertContains($legacy, array_map(static fn (string $path): string => str_replace('\\', '/', $path), $sistema->listarBackups()));
            // Resolving a default source must not duplicate the legacy directory on Windows.
            // No database is opened or created at this placeholder path.
            $this->configurePath(ROOT_PATH . '/database/fixture-not-opened.sqlite');
            $listed = array_map(static fn (string $path): string => str_replace('\\', '/', $path), $sistema->listarBackups());
            $this->assertSame(1, count(array_filter($listed, static fn (string $path): bool => $path === $legacy)));
            $this->assertSame('synthetic-legacy-backup', file_get_contents($legacy));
        } finally {
            unlink($legacy);
            if ($created && scandir($directory) === ['.', '..']) {
                rmdir($directory);
            }
        }
    }

    public function testMissingSourceAndUnavailableDirectoryReturnFalseWithoutTouchingFiles(): void
    {
        $sistema = new \Sistema();
        $this->configurePath($this->root . '/missing.sqlite');
        $this->assertFalse($sistema->criarBackupManual());
        $this->assertFileDoesNotExist($this->root . '/missing.sqlite');
        $this->assertDirectoryDoesNotExist($this->root . '/backups');
        $this->configurePath($this->root . '/live.sqlite');
        file_put_contents($this->root . '/backups', 'preserve-existing-file');
        $this->assertFalse($sistema->criarBackupManual());
        $this->assertSame('preserve-existing-file', file_get_contents($this->root . '/backups'));
    }

    public function testInvalidSourceAndForeignKeyFailureCleanOnlyTheirOwnAttempt(): void
    {
        $sistema = new \Sistema();
        $validBackup = $this->backup($sistema);
        $hash = hash_file('sha256', $validBackup);
        file_put_contents($this->root . '/invalid.sqlite', 'not a sqlite database');
        $this->configurePath($this->root . '/invalid.sqlite');
        $this->assertFalse($sistema->criarBackupManual());
        $this->configurePath($this->root . '/live.sqlite');
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        $this->pdo->exec("INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES ('Orfao','orfao','2010-01-01',999999)");
        $this->assertFalse($sistema->criarBackupManual());
        $this->assertSame([$validBackup], glob($this->root . '/backups/*.db'));
        $this->assertSame($hash, hash_file('sha256', $validBackup));
        $this->assertSame('not a sqlite database', file_get_contents($this->root . '/invalid.sqlite'));
    }

    private function configurePath(string $path): void
    {
        $_ENV['DB_PATH'] = $path;
        putenv('DB_PATH=' . $path);
    }

    private function backup(\Sistema $sistema): string
    {
        $name = $sistema->criarBackupManual();
        $this->assertIsString($name);
        $this->assertSame(basename($name), $name);
        $path = $this->root . '/backups/' . $name;
        $this->assertFileExists($path);
        return $path;
    }

    /** @return array<string,mixed> */
    private function snapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (['usuarios', 'turmas', 'alunos', 'dvas', 'security_audit', 'logs', 'sqlite_sequence', 'schema_migrations'] as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }
        $snapshot['schema'] = $pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC);
        $snapshot['user_version'] = $pdo->query('PRAGMA user_version')->fetchColumn();
        return $snapshot;
    }
}
