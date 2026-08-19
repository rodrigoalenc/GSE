<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use src\Core\Database;
use src\Core\DatabaseInitializer;

final class DatabaseMigrationTest extends TestCase
{
    private string $root = '';
    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->database = $this->root . DIRECTORY_SEPARATOR . 'legacy.sqlite';
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_PATH'] = $this->database;
        putenv('APP_ENV=testing');
        putenv('DB_PATH=' . $this->database);
        \Model::setConexao(null);
    }

    protected function tearDown(): void
    {
        \Model::setConexao(null);

        if (is_dir($this->root)) {
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

    public function testCleanInitializationIsIdempotentAndVersioned(): void
    {
        $pdo = Database::getConnection();
        DatabaseInitializer::initialize($pdo);
        DatabaseInitializer::initialize($pdo);

        $this->assertSame(4, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testLegacyMigrationPreservesUserAddsSecurityFieldsAndValidatedBackup(): void
    {
        $legacy = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec(
            'CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                senha TEXT NOT NULL,
                tipo TEXT NOT NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $statement = $legacy->prepare('INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)');
        $statement->execute(['Legado Preservado', 'LEGADO@example.test', \PasswordPolicy::hash('Frase legada 2026'), 'admin']);
        $legacy = null;

        $pdo = Database::getConnection();
        DatabaseInitializer::initialize($pdo);
        $user = $pdo->query('SELECT * FROM usuarios WHERE id = 1')->fetch();
        $columns = array_column($pdo->query('PRAGMA table_info(usuarios)')->fetchAll(), 'name');
        $backups = glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite') ?: [];

        $this->assertSame('Legado Preservado', $user['nome']);
        $this->assertSame('administrador', $user['tipo']);
        $this->assertContains('session_version', $columns);
        $this->assertContains('deve_alterar_senha', $columns);
        $this->assertContains('password_changed_at', $columns);
        $this->assertCount(1, $backups);
        $backup = new PDO('sqlite:' . $backups[0]);
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)')
            ->execute(['Duplicado', 'legado@EXAMPLE.TEST', \PasswordPolicy::hash('Outra frase 2027'), 'funcionario']);
    }
}
