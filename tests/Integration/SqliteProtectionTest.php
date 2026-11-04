<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use src\Core\Database;
use src\Core\DatabaseInitializer;

final class SqliteProtectionTest extends TestCase
{
    private string $root = '';
    private array $previousEnvironment;
    private string|false $previousDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnvironment = $_ENV;
        $this->previousDatabasePath = getenv('DB_PATH');
    }

    protected function tearDown(): void
    {
        \Model::setConexao(null);
        $_ENV = $this->previousEnvironment;
        putenv($this->previousDatabasePath === false ? 'DB_PATH' : 'DB_PATH=' . $this->previousDatabasePath);

        if ($this->root !== '' && is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    public function testInMemoryDatabaseInitializesWithoutConfiguredPath(): void
    {
        unset($_ENV['DB_PATH']);
        putenv('DB_PATH');
        $pdo = new PDO('sqlite::memory:');

        DatabaseInitializer::initialize($pdo);
        $this->assertSame(DatabaseInitializer::latestVersion(), (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        DatabaseInitializer::initialize($pdo);
        $this->assertSame(DatabaseInitializer::latestVersion(), (int) $pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testInitializerProtectsOnlyTheDatabaseOpenedByPdo(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Modos POSIX são verificados no runner Linux; Windows usa ACL do sistema.');
        }

        $this->root = sys_get_temp_dir() . '/gse-permissions-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
        $configuredPath = $this->root . '/configured.sqlite';
        $openedPath = $this->root . '/opened.sqlite';
        touch($configuredPath);
        chmod($configuredPath, 0644);
        $_ENV['DB_PATH'] = $configuredPath;
        $_ENV['DB_FILE_MODE'] = '0600';
        $pdo = new PDO('sqlite:' . $openedPath);

        foreach (['novo', 'já inicializado'] as $schema) {
            chmod($openedPath, 0644);
            DatabaseInitializer::initialize($pdo);

            clearstatcache();
            $this->assertSame(0600, fileperms($openedPath) & 0777, $schema);
            $this->assertSame(0644, fileperms($configuredPath) & 0777, $schema);
        }
    }

    public function testLinuxDatabaseAndSidecarsReceiveRestrictivePermissions(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Modos POSIX são verificados no runner Linux; Windows usa ACL do sistema.');
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-permissions-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
        $database = $this->root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'gse.sqlite';
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_PATH'] = $database;
        $_ENV['DB_DIRECTORY_MODE'] = '0700';
        $_ENV['DB_FILE_MODE'] = '0600';
        putenv('DB_PATH=' . $database);
        \Model::setConexao(null);
        $pdo = Database::getConnection();
        DatabaseInitializer::initialize($pdo, true);
        \test_insert_user($pdo, ['nome'=>'Teste','email'=>'teste@example.test','senha'=>'hash','tipo'=>'funcionario']);
        Database::protectFiles($database);

        clearstatcache(true, $database);
        $this->assertSame(0700, fileperms(dirname($database)) & 0777);
        $this->assertSame(0600, fileperms($database) & 0777);

        foreach ([$database . '-wal', $database . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                clearstatcache(true, $sidecar);
                $this->assertSame(0600, fileperms($sidecar) & 0777);
            }
        }
    }
}
