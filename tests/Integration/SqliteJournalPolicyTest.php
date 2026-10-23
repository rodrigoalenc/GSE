<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteJournalPolicyTest extends TestCase
{
    private string $directory;
    private string $path;
    private ?PDO $connection = null;
    private ?PDO $other = null;

    protected function setUp(): void
    {
        self::assertTrue(class_exists(\SqliteJournalPolicy::class), 'A conexão deve aplicar uma política de journal segura.');
        $this->directory = sys_get_temp_dir() . '/gse-journal-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->path = $this->directory . '/test.sqlite';
        $this->connection = $this->open('3.45.1');
        $this->connection->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->connection->exec("INSERT INTO entries (value) VALUES ('preserved')");
    }

    protected function tearDown(): void
    {
        foreach ([$this->connection, $this->other] as $connection) {
            if ($connection !== null && $connection->inTransaction()) { $connection->rollBack(); }
        }
        unset($connection);
        $this->connection = null;
        $this->other = null;
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($this->directory);
        }
    }

    public function testHttpRejectsUnpatchedWalWithoutMutatingIt(): void
    {
        $this->connection->exec('PRAGMA journal_mode=WAL');
        try {
            \SqliteJournalPolicy::configure($this->connection, false);
            self::fail('HTTP deve exigir manutenção CLI antes de usar WAL sem correção.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('bin/init-db.php', $exception->getMessage());
        }
        self::assertSame('wal', $this->connection->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame('preserved', $this->connection->query('SELECT value FROM entries')->fetchColumn());
    }

    public function testCliConvertsUnpatchedWalToDeleteAndPreservesData(): void
    {
        $this->connection->exec('PRAGMA journal_mode=WAL');
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->connection, true));
        self::assertSame('delete', $this->connection->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame('preserved', $this->connection->query('SELECT value FROM entries')->fetchColumn());
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->connection, false));
    }

    public function testHttpAcceptsDeleteEvenWhenWalIsPatchedWithoutPromotingMode(): void
    {
        $this->connection->sqliteCreateFunction('sqlite_version', static fn (): string => '3.53.4', 0);
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->connection, false));
        self::assertSame('delete', $this->connection->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame('WAL', \SqliteJournalPolicy::configure($this->connection, true));
        self::assertSame('WAL', \SqliteJournalPolicy::configure($this->connection, false));
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->connection, true, 'delete'));
    }

    public function testHttpForcedDeleteRejectsWalWithoutChangingPatchedDatabase(): void
    {
        $this->connection->sqliteCreateFunction('sqlite_version', static fn (): string => '3.53.4', 0);
        $this->connection->exec('PRAGMA journal_mode=WAL');
        try {
            \SqliteJournalPolicy::configure($this->connection, false, 'delete');
            self::fail('A preferência explícita exige manutenção CLI.');
        } catch (\RuntimeException) {
            self::assertSame('wal', $this->connection->query('PRAGMA journal_mode')->fetchColumn());
        }
    }

    public function testConfigureCannotRunInsideTransaction(): void
    {
        $this->connection->beginTransaction();
        try {
            \SqliteJournalPolicy::configure($this->connection, true);
            self::fail('A política não pode alterar journal em transação.');
        } catch (\RuntimeException) {
            self::assertTrue($this->connection->inTransaction());
            self::assertSame('delete', $this->connection->query('PRAGMA journal_mode')->fetchColumn());
        }
        $this->connection->rollBack();
    }

    public function testRollbackAndConcurrentWriterLockWorkInDeleteMode(): void
    {
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->connection, true));
        $this->other = $this->open('3.45.1');
        self::assertSame('DELETE', \SqliteJournalPolicy::configure($this->other, false));
        $this->connection->beginTransaction();
        $this->connection->exec("UPDATE entries SET value='uncommitted'");
        self::assertSame('preserved', $this->other->query('SELECT value FROM entries')->fetchColumn());
        try {
            $this->other->exec("UPDATE entries SET value='contender'");
            self::fail('Um segundo escritor deve aguardar a transação atual.');
        } catch (\PDOException $exception) {
            self::assertSame(5, $exception->errorInfo[1]);
        }
        $this->connection->rollBack();
        self::assertSame('preserved', $this->other->query('SELECT value FROM entries')->fetchColumn());
        $this->other->exec("UPDATE entries SET value='committed'");
        self::assertSame('committed', $this->connection->query('SELECT value FROM entries')->fetchColumn());
    }

    public function testInMemoryJournalExceptionRequiresMainFileToBeEmpty(): void
    {
        $memory = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $memory->sqliteCreateFunction('sqlite_version', static fn (): string => '3.45.1', 0);
        self::assertSame('MEMORY', \SqliteJournalPolicy::configure($memory, true));
        self::assertSame('MEMORY', \SqliteJournalPolicy::configure($memory, false));
        $this->connection->exec('PRAGMA journal_mode=MEMORY');
        $this->expectException(\RuntimeException::class);
        \SqliteJournalPolicy::configure($this->connection, false);
    }

    private function open(string $version): PDO
    {
        $connection = new PDO('sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('PRAGMA busy_timeout=50');
        $connection->sqliteCreateFunction('sqlite_version', static fn (): string => $version, 0);
        return $connection;
    }
}
