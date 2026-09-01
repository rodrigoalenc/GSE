<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use src\Core\DatabaseInitializer;

final class PassiveMigrationTest extends TestCase
{
    private string $root = '';
    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-passive-migration-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->database = $this->root . DIRECTORY_SEPARATOR . 'legacy.sqlite';
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
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    public function testVersionElevenUpgradePreservesLegacyRowsIdsSequenceAndCollisions(): void
    {
        $pdo = $this->legacyDatabase();
        DatabaseInitializer::initialize($pdo);
        DatabaseInitializer::initialize($pdo);

        $this->assertSame(12, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame([4, 9, 15], array_map('intval', $pdo->query('SELECT id FROM alunos_passivo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(20, (int) $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'alunos_passivo'")->fetchColumn());
        $this->assertSame('jose legado', $pdo->query('SELECT nome_normalizado FROM alunos_passivo WHERE id = 4')->fetchColumn());
        $this->assertSame(
            [12, "Jos\u{00E9} Legado", '2000-01-01', '7', 'CX-1', 1, 30, 30],
            array_values($pdo->query(
                'SELECT aluno_origem_id, nome_completo, data_nascimento, numero, caixa, ativo, criado_por, atualizado_por
                 FROM alunos_passivo WHERE id = 4'
            )->fetch(PDO::FETCH_ASSOC))
        );
        $this->assertSame(1, (int) $pdo->query('SELECT localizacao_pendente FROM alunos_passivo WHERE id = 9')->fetchColumn());
        $this->assertNull($pdo->query('SELECT caixa FROM alunos_passivo WHERE id = 9')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM alunos_passivo WHERE caixa_normalizada = 'cx-1' AND numero_normalizado = '7'")->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name = 'alunos_passivo_v12'")->fetchAll());
        $backups = glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite') ?: [];
        $this->assertCount(1, $backups);
        $backup = new PDO('sqlite:' . $backups[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(11, (int) $backup->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(3, (int) $backup->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
        $backupColumns = array_column($backup->query('PRAGMA table_info(alunos_passivo)')->fetchAll(), 'name');
        $this->assertContains('nome_sort', $backupColumns);
        $this->assertNotContains('nome_normalizado', $backupColumns);

        try {
            $pdo->exec("INSERT INTO alunos_passivo
                (aluno_origem_id, nome_completo, nome_normalizado, caixa, caixa_normalizada, ativo, localizacao_pendente)
                VALUES (12, 'Origem duplicada', 'origem duplicada', 'B', 'b', 1, 0)");
            $this->fail('Dois registros ativos para a mesma origem deveriam ser bloqueados.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }

        $pdo->exec("INSERT INTO alunos_passivo
            (nome_completo, nome_normalizado, caixa, caixa_normalizada, ativo, localizacao_pendente)
            VALUES ('Depois', 'depois', 'B', 'b', 1, 0)");
        $this->assertSame(21, (int) $pdo->lastInsertId());

        $this->expectException(\PDOException::class);
        $pdo->exec('DELETE FROM alunos_passivo WHERE id = 4');
    }

    public function testMigratedAndCleanPassiveSchemasAreEquivalent(): void
    {
        $migrated = $this->legacyDatabase();
        DatabaseInitializer::initialize($migrated);
        $cleanPath = $this->root . DIRECTORY_SEPARATOR . 'clean.sqlite';
        $clean = new PDO('sqlite:' . $cleanPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $clean->exec('PRAGMA foreign_keys = ON');
        DatabaseInitializer::initialize($clean);

        $this->assertSame($this->structure($clean), $this->structure($migrated));
    }

    public function testMigrationRestoresDisabledForeignKeyStateAfterValidation(): void
    {
        $pdo = $this->legacyDatabase();
        $pdo->exec('PRAGMA foreign_keys = OFF');

        DatabaseInitializer::initialize($pdo);

        $this->assertSame(0, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name = 'alunos_passivo_v12'")->fetchAll());
    }

    public function testFailureWhileRecordingVersionRollsBackRebuildWithoutTemporaryTable(): void
    {
        $pdo = $this->legacyDatabase();
        $pdo->exec(
            "CREATE TRIGGER fail_passive_version BEFORE INSERT ON schema_migrations
             WHEN NEW.version = 12 BEGIN SELECT RAISE(ABORT, 'forced_v12_failure'); END"
        );

        try {
            DatabaseInitializer::initialize($pdo);
            $this->fail('A falha forçada deveria interromper a migração v12.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('forced_v12_failure', $exception->getMessage());
        }

        $columns = array_column($pdo->query('PRAGMA table_info(alunos_passivo)')->fetchAll(), 'name');
        $this->assertContains('nome_sort', $columns);
        $this->assertNotContains('nome_normalizado', $columns);
        $this->assertSame([4, 9, 15], array_map('intval', $pdo->query('SELECT id FROM alunos_passivo ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 12')->fetchColumn());
        $this->assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name = 'alunos_passivo_v12'")->fetchAll());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertCount(1, glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite') ?: []);
    }

    private function legacyDatabase(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
        $this->assertIsString($schema);
        $pdo->exec($schema);
        $pdo->exec(
            "INSERT INTO usuarios (id, nome, email, senha, tipo, ativo)
             VALUES (30, 'Responsável Legado', 'responsavel.legado@teste.local', 'hash-legado', 'administrador', 1)"
        );
        $pdo->exec(
            "INSERT INTO alunos
                (id, nome_completo, nome_normalizado, data_nascimento, ativo, inativado_em, inativado_por)
             VALUES
                (12, 'Jose Legado', 'jose legado', '2000-01-01', 0, '2026-01-01 00:00:00', 30)"
        );
        $pdo->exec('DROP TABLE alunos_passivo');
        $pdo->exec(
            'CREATE TABLE alunos_passivo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                aluno_origem_id INTEGER NULL,
                nome_completo TEXT NOT NULL,
                data_nascimento TEXT NULL,
                numero TEXT NULL,
                caixa TEXT NULL,
                nome_sort TEXT NOT NULL,
                ativo INTEGER NOT NULL DEFAULT 1,
                criado_por INTEGER NULL,
                atualizado_por INTEGER NULL
            )'
        );
        $insert = $pdo->prepare(
            'INSERT INTO alunos_passivo
                (id, aluno_origem_id, nome_completo, data_nascimento, numero, caixa, nome_sort, ativo, criado_por, atualizado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([4, 12, "Jos\u{00E9} Legado", '2000-01-01', '7', 'CX-1', 'JOSE LEGADO', 1, 30, 30]);
        $insert->execute([9, null, 'Sem Caixa', null, null, null, 'SEM CAIXA', 1, null, null]);
        $insert->execute([15, null, 'Colisão Preservada', '2001-02-02', '7', 'CX-1', 'COLISAO PRESERVADA', 1, null, null]);
        $pdo->exec("UPDATE sqlite_sequence SET seq = 20 WHERE name = 'alunos_passivo'");
        $mark = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
        for ($version = 1; $version <= 11; $version++) {
            $mark->execute([$version, '2026-01-01 00:00:00']);
        }
        $pdo->exec('PRAGMA user_version = 11');

        return $pdo;
    }

    /** @return array<string,mixed> */
    private function structure(PDO $pdo): array
    {
        return [
            'columns' => $pdo->query('PRAGMA table_info(alunos_passivo)')->fetchAll(),
            'indexes' => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'alunos_passivo' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
            'triggers' => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'alunos_passivo' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
}
