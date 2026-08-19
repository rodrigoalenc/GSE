<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use src\Core\Database;
use src\Core\DatabaseInitializer;

final class SqliteProtectionTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        \Model::setConexao(null);

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
        DatabaseInitializer::initialize($pdo);
        $pdo->exec("INSERT INTO usuarios (nome, email, senha, tipo) VALUES ('Teste', 'teste@example.test', 'hash', 'funcionario')");
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
