<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

final class BackupCleanupTest extends DatabaseTestCase
{
    private string $root;
    private ?string $previousLog;
    private string $olderHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/gse-backup-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy((string) $_ENV['DB_PATH'], $this->root . '/fixture.sqlite');
        $this->pdo = new PDO('sqlite:' . $this->root . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $_ENV['DB_PATH'] = $this->root . '/fixture.sqlite';
        putenv('DB_PATH=' . $_ENV['DB_PATH']);
        $this->previousLog = \TechnicalLogger::path();
        $_ENV['LOG_PATH'] = $this->root . '/technical.log';
        \TechnicalLogger::configure();
        mkdir($this->root . '/backups', 0700);
        \ProtectedBackup::encrypt($this->root . '/fixture.sqlite', $this->root . '/backups/older.db.gse');
        $this->olderHash = hash_file('sha256', $this->root . '/backups/older.db.gse');
        file_put_contents($this->root . '/backups/foreign.sqlite', 'foreign synthetic file');
    }

    protected function tearDown(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $_ENV['LOG_PATH'] = $this->previousLog ?? 'logs/php_errors.log';
        \TechnicalLogger::configure();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->root);
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        foreach (['manual', 'migration'] as $flow) {
            foreach (['success', 'encryption', 'cleanup', 'both', 'already_removed', 'lying_unlink', 'write'] as $fault) {
                yield "$flow/$fault" => [$flow, $fault];
            }
        }
    }

    #[DataProvider('cases')]
    public function testLifecycle(string $flow, string $fault): void
    {
        $original = new \RuntimeException('private-person@example.test password=do-not-log');
        $snapshot = new class($fault, $original) extends \BackupSnapshot {
            public function __construct(private string $fault, private \Throwable $original) {}
            protected function encrypt(string $source, string $target): void
            {
                if (in_array($this->fault, ['encryption', 'both'], true)) { throw $this->original; }
                if ($this->fault === 'write') {
                    // Exercise a real failed write with the production warning-to-exception policy.
                    $readOnly = fopen($source, 'rb');
                    set_error_handler(static function (int $severity, string $message): bool {
                        if (!(error_reporting() & $severity)) { return false; }
                        throw new \ErrorException($message, 0, $severity);
                    });
                    try { (new \ReflectionMethod(\ProtectedBackup::class, 'write'))->invoke(null, $readOnly, 'synthetic write'); }
                    finally { restore_error_handler(); fclose($readOnly); }
                }
                parent::encrypt($source, $target);
                if ($this->fault === 'already_removed') { unlink($source); }
            }
            protected function remove(string $path): bool
            {
                if (basename($path) === 'snapshot.sqlite') {
                    if (in_array($this->fault, ['cleanup', 'both'], true)) { return false; }
                    if ($this->fault === 'lying_unlink') { return true; }
                }
                return parent::remove($path);
            }
        };
        $error = null;
        if ($flow === 'manual') {
            $sistema = new class($snapshot) extends \Sistema {
                public function __construct(private \BackupSnapshot $snapshot) {}
                protected function backupSnapshot(): \BackupSnapshot { return $this->snapshot; }
            };
            $result = $sistema->criarBackupManual();
        } else {
            try {
                $method = new \ReflectionMethod(\src\Core\DatabaseInitializer::class, 'createValidatedBackup');
                $result = $method->invoke(null, $this->pdo, $snapshot);
            } catch (\Throwable $exception) { $error = $exception; $result = false; }
        }
        $failedCleanup = in_array($fault, ['cleanup', 'both', 'lying_unlink'], true);
        $encrypted = in_array($fault, ['success', 'cleanup', 'already_removed', 'lying_unlink'], true);
        $this->assertSame(in_array($fault, ['success', 'already_removed'], true), is_string($result));
        $residuals = glob($this->root . '/backups/.snapshot-*') ?: [];
        $this->assertCount($failedCleanup ? 1 : 0, $residuals);
        if ($failedCleanup) {
            $this->assertFileExists($residuals[0] . '/snapshot.sqlite');
            $receipt = json_decode((string) file_get_contents($residuals[0] . '/identity.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(basename($residuals[0]), '.snapshot-' . $receipt['operation']);
            $this->assertSame(lstat($residuals[0] . '/snapshot.sqlite')['ino'], $receipt['file']['ino']);
            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame(0700, fileperms($residuals[0]) & 0777);
                $this->assertSame(0600, fileperms($residuals[0] . '/snapshot.sqlite') & 0777);
                $this->assertSame(0600, fileperms($residuals[0] . '/identity.json') & 0777);
            }
        }
        $backups = array_values(array_filter(glob($this->root . '/backups/*.gse') ?: [], static fn (string $path): bool => basename($path) !== 'older.db.gse'));
        $this->assertCount($encrypted ? 1 : 0, $backups);
        if ($encrypted) {
            \ProtectedBackup::decrypt($backups[0], $this->root . '/restored.sqlite');
            $restored = new PDO('sqlite:' . $this->root . '/restored.sqlite');
            $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame(21, (int) $restored->query('PRAGMA user_version')->fetchColumn());
            $restored = null;
        }
        if ($flow === 'migration') {
            if (in_array($fault, ['encryption', 'both'], true)) { $this->assertSame($original, $error); }
            elseif ($failedCleanup) { $this->assertInstanceOf(\BackupCleanupException::class, $error); }
            elseif ($fault === 'write') { $this->assertInstanceOf(\BackupWriteException::class, $error); }
        }
        $log = is_file($this->root . '/technical.log') ? (string) file_get_contents($this->root . '/technical.log') : '';
        $this->assertSame($failedCleanup, str_contains($log, 'backup_plaintext_cleanup_failed'));
        $this->assertSame(in_array($fault, ['encryption', 'both', 'write'], true), str_contains($log, 'backup_operation_failed'));
        if ($fault === 'write') { $this->assertStringContainsString('"phase":"write"', $log); }
        if ($failedCleanup) {
            $this->assertStringContainsString('"encrypted":"' . ($encrypted ? 'yes' : 'no') . '"', $log);
            $this->assertStringContainsString($receipt['operation'], $log);
        }
        foreach (['private-person', 'do-not-log', $this->root, 'snapshot.sqlite', 'GSEB'] as $sensitive) { $this->assertStringNotContainsString($sensitive, $log); }
        $this->assertSame($this->olderHash, hash_file('sha256', $this->root . '/backups/older.db.gse'));
        $this->assertSame('foreign synthetic file', file_get_contents($this->root . '/backups/foreign.sqlite'));
    }

    public function testRealExclusiveWriteFailurePreservesPreviousProtectedBackup(): void
    {
        try {
            (new \BackupSnapshot())->create($this->root . '/backups/older.db.gse', function (string $path): void {
                copy($this->root . '/fixture.sqlite', $path);
            });
            $this->fail('Exclusive destination should refuse an existing backup');
        } catch (\BackupWriteException) {
            $this->assertSame($this->olderHash, hash_file('sha256', $this->root . '/backups/older.db.gse'));
        }
        $this->assertSame([], glob($this->root . '/backups/.snapshot-*'));
        $this->assertStringContainsString('"phase":"write"', (string) file_get_contents($this->root . '/technical.log'));
    }

    public function testAdministrativeCleanupFailureCannotReportOrAuditFullSuccess(): void
    {
        $actor = $this->insertUsuario('Administrador sintético');
        $sistema = new class extends \Sistema {
            protected function backupSnapshot(): \BackupSnapshot
            {
                return new class extends \BackupSnapshot {
                    protected function remove(string $path): bool
                    {
                        return basename($path) === 'snapshot.sqlite' ? false : parent::remove($path);
                    }
                };
            }
        };
        $this->assertFalse($sistema->criarBackupAdministrativo($actor, 1, 'Frase segura 2026'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.created'")->fetchColumn());
        $listed = $sistema->listarBackupsAdministrativos($actor, 1);
        $this->assertSame(1, $listed['total'], 'Completed encrypted backup is retained and discoverable');
        $this->assertStringEndsWith('.db.gse', $listed['items'][0]['nome']);
        $this->assertCount(1, glob($this->root . '/backups/.snapshot-*/snapshot.sqlite'));
        $this->expectException(\DomainException::class);
        $sistema->abrirBackupAdministrativo('snapshot.sqlite', $actor, 1);
    }

    public function testReplacementIsPreservedAndOriginalExceptionSurvives(): void
    {
        $error = new \RuntimeException('synthetic operation failure');
        $snapshot = new \BackupSnapshot();
        try {
            $snapshot->create($this->root . '/backups/new.gse', static function (string $path) use ($error): void {
                // Retain the original inode, so a replacement cannot reuse its identity.
                rename($path, dirname($path) . '/owned-original.sqlite');
                file_put_contents($path, 'foreign replacement');
                throw $error;
            });
            $this->fail('Expected original exception');
        } catch (\Throwable $caught) { $this->assertSame($error, $caught); }
        $directories = glob($this->root . '/backups/.snapshot-*');
        $this->assertCount(1, $directories);
        $this->assertSame('foreign replacement', file_get_contents($directories[0] . '/snapshot.sqlite'));
        $this->assertFileExists($directories[0] . '/owned-original.sqlite');
        $this->assertStringContainsString('backup_plaintext_cleanup_failed', (string) file_get_contents($this->root . '/technical.log'));
    }

    public static function loggingFailures(): iterable
    {
        yield 'operation only' => [false];
        yield 'operation and cleanup' => [true];
    }

    #[DataProvider('loggingFailures')]
    public function testLoggerFailureCannotSkipCleanupOrReplaceOriginal(bool $failCleanup): void
    {
        $original = new \RuntimeException('private-person@example.test secret=do-not-log');
        $snapshot = new class($failCleanup) extends \BackupSnapshot {
            public function __construct(private bool $failCleanup) {}
            protected function record(string $event, array $context): void { throw new \RuntimeException('logger unavailable'); }
            protected function remove(string $path): bool { return $this->failCleanup ? false : parent::remove($path); }
        };
        $previousErrorLog = ini_set('error_log', $this->root . '/fallback.log');
        try {
            $snapshot->create($this->root . '/backups/new.gse', static function (string $path) use ($original): void {
                file_put_contents($path, 'synthetic snapshot');
                throw $original;
            });
            $this->fail('Expected original error');
        } catch (\Throwable $caught) { $this->assertSame($original, $caught); }
        finally { ini_set('error_log', $previousErrorLog); }
        $this->assertCount($failCleanup ? 1 : 0, glob($this->root . '/backups/.snapshot-*'));
        $log = (string) file_get_contents($this->root . '/fallback.log');
        $this->assertStringContainsString('backup_operation_failed', $log);
        $this->assertSame($failCleanup, str_contains($log, 'backup_plaintext_cleanup_failed'));
        $this->assertStringNotContainsString('private-person', $log);
        $this->assertStringNotContainsString('do-not-log', $log);
    }
}
