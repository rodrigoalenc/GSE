<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class JournalPreparationCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gse-journal-cli-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public function testPreparationChangesOnlyJournalAndPreservesLegacySchemaAndData(): void
    {
        $path = $this->directory . '/legacy.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $migration = new \ReflectionMethod(\src\Core\DatabaseInitializer::class, 'runMigration');
        $schema = (string) file_get_contents(ROOT_PATH . '/database/schema.sql');
        for ($version = 1; $version <= 11; $version++) { $migration->invoke(null, $pdo, $version, $schema); }
        $pdo->exec("INSERT INTO usuarios(id,nome,email,senha,tipo) VALUES(42,'Operador sintetico','journal@example.test','unchanged-hash','administrador')");
        $before = $pdo->query('SELECT * FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $versions = $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;
        [$exit, $output] = $this->runPreparation($path, ['--journal-only', '--maintenance-confirmed']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('DELETE', $output);
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame('delete', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertSame($versions, $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($before, $pdo->query('SELECT * FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $pdo = null;
    }

    public function testPreparationWithoutConfirmationOrDatabaseDoesNotCreateAFile(): void
    {
        $path = $this->directory . '/missing.sqlite';
        [$exit] = $this->runPreparation($path, ['--journal-only']);
        self::assertNotSame(0, $exit);
        self::assertFileDoesNotExist($path);
        [$exit] = $this->runPreparation($path, ['--journal-only', '--maintenance-confirmed']);
        self::assertNotSame(0, $exit);
        self::assertFileDoesNotExist($path);
    }

    /** @param list<string> $arguments @return array{int,string} */
    private function runPreparation(string $path, array $arguments): array
    {
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_PATH' => $path,
            'DB_JOURNAL_MODE' => 'delete', 'LOG_PATH' => $this->directory . '/technical.log', 'MAIL_ENABLED' => 'false']);
        $process = proc_open([PHP_BINARY, ROOT_PATH . '/bin/init-db.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, ROOT_PATH, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }
}
