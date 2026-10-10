<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Tests\Support\DatabaseTestCase;

final class AdministrativeBackupTest extends DatabaseTestCase
{
    private string $root;
    private int $admin;
    private const PASSWORD = 'Backup seguro 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/gse-admin-backup-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy((string) $_ENV['DB_PATH'], $this->root . '/live.sqlite');
        $this->pdo = new PDO('sqlite:' . $this->root . '/live.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0');
        \Model::setConexao($this->pdo);
        $_ENV['DB_PATH'] = $this->root . '/live.sqlite';
        putenv('DB_PATH=' . $_ENV['DB_PATH']);
        $this->admin = $this->insertUsuario('Administrador Backup', 'administrador', true, self::PASSWORD);
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
                $entry->isLink() || !$entry->isDir() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testAdministrativeSnapshotKeepsCommittedWalAndAuditsCreationAndDownload(): void
    {
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->pdo->exec("INSERT INTO logs(usuario,acao,detalhes) VALUES ('synthetic','committed-in-wal','keep')");
        mkdir($this->root . '/private-pdfs', 0700);
        file_put_contents($this->root . '/private-pdfs/synthetic.pdf', 'SYNTHETIC_PRIVATE_PDF_CONTENT');
        file_put_contents($this->root . '/synthetic-code.php', 'SYNTHETIC_CODE_CONTENT');
        $this->assertGreaterThan(0, filesize($this->root . '/live.sqlite-wal'));
        $model = new \Sistema();
        $first = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($first);
        $firstPath = $this->root . '/backups/' . $first;
        $firstHash = hash_file('sha256', $firstPath);
        $restored = new PDO('sqlite:' . $firstPath);
        $this->assertSame('committed-in-wal', $restored->query('SELECT acao FROM logs')->fetchColumn());
        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $restored->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame(16, (int) $restored->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(0, (int) $restored->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.created'")->fetchColumn(), 'Creation audit occurs after the snapshot.');
        $restored = null;
        $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_PDF_CONTENT', (string) file_get_contents($firstPath));
        $this->assertStringNotContainsString('SYNTHETIC_CODE_CONTENT', (string) file_get_contents($firstPath));
        $second = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $opened = $model->abrirBackupAdministrativo($first, $this->admin, 1);
        $this->assertSame($first, $opened['nome']);
        $this->assertSame(filesize($firstPath), $opened['bytes']);
        $this->assertSame("SQLite format 3\0", fread($opened['handle'], 16));
        fclose($opened['handle']);
        $this->assertSame($firstHash, hash_file('sha256', $firstPath));
        $audits = $this->pdo->query("SELECT action,actor_user_id,resource_type FROM security_audit WHERE action LIKE 'backup.%' ORDER BY id")->fetchAll();
        $this->assertSame(['backup.created', 'backup.created', 'backup.downloaded'], array_column($audits, 'action'));
        $this->assertSame([$this->admin, $this->admin, $this->admin], array_column($audits, 'actor_user_id'));
        $this->assertSame(['backup', 'backup', 'backup'], array_column($audits, 'resource_type'));
        $this->assertCount(2, glob($this->root . '/backups/*'));
    }

    public function testCreationRevalidatesPasswordRoleActivityTemporaryPasswordAndSessionVersion(): void
    {
        $model = new \Sistema();
        $employee = $this->insertUsuario('Funcionario Backup', 'funcionario', true, self::PASSWORD);
        $this->assertFalse($model->criarBackupAdministrativo($employee, 1, self::PASSWORD));
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, 'Senha incorreta'));
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 2, self::PASSWORD));
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, str_repeat('a', 129)));
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, "invalid\0password"));
        $this->pdo->exec('UPDATE usuarios SET deve_alterar_senha=1 WHERE id=' . $this->admin);
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD));
        $this->pdo->exec('UPDATE usuarios SET deve_alterar_senha=0 WHERE id=' . $this->admin);
        $this->insertUsuario('Administrador Reserva');
        $this->pdo->exec('UPDATE usuarios SET ativo=0 WHERE id=' . $this->admin);
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD));
        $this->assertDirectoryDoesNotExist($this->root . '/backups');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.created'")->fetchColumn());
    }

    public function testCreationAuditFailureRemovesOnlyNewSnapshotAndKeepsEarlierFiles(): void
    {
        $model = new \Sistema();
        $old = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($old);
        $hash = hash_file('sha256', $this->root . '/backups/' . $old);
        file_put_contents($this->root . '/backups/keep.txt', 'must remain');
        $this->pdo->exec("CREATE TRIGGER reject_backup_creation_audit BEFORE INSERT ON security_audit WHEN NEW.action='backup.created' BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD));
        $this->assertSame([$this->root . '/backups/' . $old], glob($this->root . '/backups/*.db'));
        $this->assertSame($hash, hash_file('sha256', $this->root . '/backups/' . $old));
        $this->assertSame('must remain', file_get_contents($this->root . '/backups/keep.txt'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.created'")->fetchColumn());
    }

    public function testDownloadAuditFailureDoesNotReturnAStreamOrModifySnapshot(): void
    {
        $model = new \Sistema();
        $name = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($name);
        $hash = hash_file('sha256', $this->root . '/backups/' . $name);
        $streams = count(get_resources('stream'));
        $this->pdo->exec("CREATE TRIGGER reject_backup_download_audit BEFORE INSERT ON security_audit WHEN NEW.action='backup.downloaded' BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
        try {
            $model->abrirBackupAdministrativo($name, $this->admin, 1);
            $this->fail('Download must fail when mandatory audit fails.');
        } catch (\PDOException $expected) {
            $this->assertStringContainsString('synthetic audit failure', $expected->getMessage());
        }
        $this->assertSame($streams, count(get_resources('stream')), 'The opened file handle is closed on audit failure.');
        $this->assertSame($hash, hash_file('sha256', $this->root . '/backups/' . $name));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.downloaded'")->fetchColumn());
    }

    public function testDownloadRejectsTraversalUnrecognizedNamesAndNonSqliteFiles(): void
    {
        $model = new \Sistema();
        $name = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($name);
        foreach (['../' . $name, '..\\' . $name, '/' . $name, 'live.sqlite', '%2e%2e%2f' . $name, $name . "\r\nX-Injected: yes", $name . '/suffix'] as $bad) {
            try {
                $model->abrirBackupAdministrativo($bad, $this->admin, 1);
                $this->fail('Unsafe filename was accepted.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $invalid = 'escola_backup_MANUAL_2026-01-02_03-04-05.db';
        file_put_contents($this->root . '/backups/' . $invalid, '%PDF-synthetic-not-a-database');
        try {
            $model->abrirBackupAdministrativo($invalid, $this->admin, 1);
            $this->fail('A renamed PDF must not be downloaded as a SQLite snapshot.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.downloaded'")->fetchColumn());
    }

    public function testLegacySnapshotsAndBoundedMetadataPaginationDoNotCreateOrExposePaths(): void
    {
        $model = new \Sistema();
        $this->assertSame([], $model->listarBackupsAdministrativos($this->admin, 1)['items']);
        $this->assertDirectoryDoesNotExist($this->root . '/backups');
        $name = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($name);
        $legacy = 'escola_backup_MANUAL_2026-01-02_03-04-05.db';
        copy($this->root . '/backups/' . $name, $this->root . '/backups/' . $legacy);
        $opened = $model->abrirBackupAdministrativo($legacy, $this->admin, 1);
        $this->assertSame($legacy, $opened['nome']);
        fclose($opened['handle']);
        for ($i = 0; $i < 205; $i++) {
            $copy = 'escola_backup_MANUAL_2026-01-02_03-04-05_' . sprintf('%032x', $i) . '.db';
            copy($this->root . '/backups/' . $name, $this->root . '/backups/' . $copy);
        }
        $before = count(glob($this->root . '/backups/*'));
        $result = $model->listarBackupsAdministrativos($this->admin, 1, 9999);
        $this->assertSame(200, $result['total']);
        $this->assertSame(10, $result['page']);
        $this->assertSame(10, $result['pages']);
        $this->assertCount(20, $result['items']);
        $this->assertSame(['nome', 'bytes', 'modificado'], array_keys($result['items'][0]));
        $this->assertStringNotContainsString($this->root, json_encode($result, JSON_UNESCAPED_SLASHES));
        $this->assertSame($before, count(glob($this->root . '/backups/*')));
    }

    public function testEmployeeAndRevokedSessionCannotListOrDownload(): void
    {
        $model = new \Sistema();
        $employee = $this->insertUsuario('Funcionario Privado', 'funcionario');
        $name = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($name);
        foreach ([[$employee, 1], [$this->admin, 2], [999999, 1]] as [$actor, $version]) {
            foreach (['list', 'download'] as $action) {
                try {
                    if ($action === 'list') {
                        $model->listarBackupsAdministrativos($actor, $version);
                    } else {
                        $model->abrirBackupAdministrativo($name, $actor, $version);
                    }
                    $this->fail('Private backup access must be denied.');
                } catch (\RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.downloaded'")->fetchColumn());
    }

    public function testMissingSnapshotIsUnavailableUnderProductionWarningPolicy(): void
    {
        $model = new \Sistema();
        $existing = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($existing);
        $missing = 'escola_backup_MANUAL_2000-01-01_00-00-00_' . str_repeat('f', 32) . '.db';
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $model->abrirBackupAdministrativo($missing, $this->admin, 1);
            $this->fail('An absent snapshot must be rejected as unavailable.');
        } catch (\DomainException $expected) {
            $this->assertSame('Backup indisponivel.', $expected->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
        $this->assertFileExists($this->root . '/backups/' . $existing);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='backup.downloaded'")->fetchColumn());
    }

    public function testSourceUnderPublicCannotCreateAnExposedSnapshot(): void
    {
        $publicFixture = ROOT_PATH . '/public/.gse-backup-test-' . bin2hex(random_bytes(8));
        mkdir($publicFixture, 0700);
        $publicSource = $publicFixture . '/synthetic.sqlite';
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy($this->root . '/live.sqlite', $publicSource);
        $_ENV['DB_PATH'] = $publicSource;
        putenv('DB_PATH=' . $publicSource);
        try {
            $model = new \Sistema();
            $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD));
            $this->assertDirectoryDoesNotExist($publicFixture . '/backups', 'Reject the destination before creating files or directories.');
            $this->assertFalse($model->criarBackupManual());
            $this->assertDirectoryDoesNotExist($publicFixture . '/backups');
        } finally {
            $_ENV['DB_PATH'] = $this->root . '/live.sqlite';
            putenv('DB_PATH=' . $_ENV['DB_PATH']);
            foreach ([$publicSource . '-wal', $publicSource . '-shm', $publicSource] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($publicFixture);
        }
    }

    public function testSymlinkedFileAndBackupDirectoryAreRejected(): void
    {
        $model = new \Sistema();
        $name = $model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD);
        $this->assertIsString($name);
        $linkedName = 'escola_backup_MANUAL_2026-01-02_03-04-05.db';
        if (!@symlink($this->root . '/backups/' . $name, $this->root . '/backups/' . $linkedName)) {
            $this->markTestSkipped('Symbolic links require OS permission; this case runs on Linux CI.');
        }
        $this->assertNotContains($linkedName, array_column($model->listarBackupsAdministrativos($this->admin, 1)['items'], 'nome'));
        try {
            $model->abrirBackupAdministrativo($linkedName, $this->admin, 1);
            $this->fail('Symlinked backup must not be downloaded.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
        unlink($this->root . '/backups/' . $linkedName);
        rename($this->root . '/backups', $this->root . '/real-backups');
        $this->assertTrue(symlink($this->root . '/real-backups', $this->root . '/backups'));
        $this->assertFalse($model->criarBackupAdministrativo($this->admin, 1, self::PASSWORD));
        $this->assertFileExists($this->root . '/real-backups/' . $name);
    }
}
