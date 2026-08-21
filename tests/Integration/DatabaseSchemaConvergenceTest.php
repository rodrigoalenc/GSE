<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use src\Core\DatabaseInitializer;

final class DatabaseSchemaConvergenceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-v11-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
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

    public function testVersionTenMigrationPreservesDataRelationshipsSequencesAndIsIdempotent(): void
    {
        $path = $this->path('version-ten.sqlite');
        $pdo = $this->connection($path);
        $this->createVersionTenSchema($pdo);
        $this->insertVersionTenHistory($pdo);
        $beforeData = $this->preservedModuleData($pdo);
        $beforeSequences = $this->sequences($pdo);

        $this->initialize($pdo, $path);

        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(11, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertSame(1, $this->columnNotNull($pdo, 'turmas', 'nome_normalizado'));
        $this->assertSame(1, $this->columnNotNull($pdo, 'alunos', 'nome_normalizado'));
        $this->assertSame($beforeData, $this->preservedModuleData($pdo));
        $this->assertSame($beforeSequences, $this->sequences($pdo));
        $this->assertSame([101 => 10, 102 => 20], $this->relationships($pdo, 'alunos', 'id_turma'));
        $this->assertSame([201 => 101, 202 => 101], $this->relationships($pdo, 'dvas', 'id_aluno'));
        $this->assertSame("turma \u{00E1}lvares", $pdo->query('SELECT nome_normalizado FROM turmas WHERE id = 10')->fetchColumn());
        $this->assertSame('aluno áureo', $pdo->query('SELECT nome_normalizado FROM alunos WHERE id = 101')->fetchColumn());
        $this->assertSame('2024-01-02 03:04:05', $pdo->query('SELECT criado_em FROM turmas WHERE id = 10')->fetchColumn());
        $this->assertSame('2025-04-05 06:07:08', $pdo->query('SELECT substituido_em FROM dvas WHERE id = 201')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertCount(1, $this->backups());

        $signature = $this->moduleSchemaSignature($pdo);
        $data = $this->preservedModuleData($pdo);
        $this->initialize($pdo, $path);

        $this->assertSame($signature, $this->moduleSchemaSignature($pdo));
        $this->assertSame($data, $this->preservedModuleData($pdo));
        $this->assertSame(11, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertCount(1, $this->backups());
    }

    public function testVersionNineMigratesThroughVersionEleven(): void
    {
        $path = $this->path('version-nine.sqlite');
        $pdo = $this->connection($path);
        $this->createVersionNineSchema($pdo);
        $pdo->exec(
            "INSERT INTO usuarios
                (id, nome, email, senha, tipo, criado_em, atualizado_em)
             VALUES
                (7, 'Usuario Teste', 'v9@example.test', 'hash-artificial', 'administrador',
                 '2024-01-01 00:00:00', '2024-01-01 00:00:00');
             INSERT INTO turmas
                (id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em)
             VALUES
                (10, '  Turma   Á  ', 2026, 1, '2024-02-01 00:00:00', '2024-02-02 00:00:00');
             INSERT INTO alunos
                (id, nome_completo, data_nascimento, id_turma, ativo, criado_em, atualizado_em)
             VALUES
                (101, '  Aluno   Áureo ', '2010-03-04', 10, 1, '2024-03-01 00:00:00', '2024-03-02 00:00:00');
             INSERT INTO dvas
                (id, id_aluno, id_usuario_registro, data_vencimento, observacao, ativo,
                 criado_em, atualizado_em, substituido_em)
             VALUES
                (201, 101, 7, '2027-01-01', 'Historico v9', 1,
                 '2024-04-01 00:00:00', '2024-04-02 00:00:00', NULL);"
        );

        $this->initialize($pdo, $path);

        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame('turma á', $pdo->query('SELECT nome_normalizado FROM turmas WHERE id = 10')->fetchColumn());
        $this->assertSame('aluno áureo', $pdo->query('SELECT nome_normalizado FROM alunos WHERE id = 101')->fetchColumn());
        $this->assertSame([101 => 10], $this->relationships($pdo, 'alunos', 'id_turma'));
        $this->assertSame([201 => 101], $this->relationships($pdo, 'dvas', 'id_aluno'));
        $this->assertSame(1, $this->columnNotNull($pdo, 'turmas', 'nome_normalizado'));
        $this->assertSame(1, $this->columnNotNull($pdo, 'alunos', 'nome_normalizado'));
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testCleanAndMigratedDatabasesHaveEquivalentModuleTwoStructure(): void
    {
        $cleanPath = $this->path('clean.sqlite');
        $clean = $this->connection($cleanPath);
        $this->initialize($clean, $cleanPath);

        $migratedPath = $this->path('migrated.sqlite');
        $migrated = $this->connection($migratedPath);
        $this->createVersionTenSchema($migrated);
        $this->initialize($migrated, $migratedPath);

        $cleanSignature = $this->moduleSchemaSignature($clean);
        $migratedSignature = $this->moduleSchemaSignature($migrated);

        $this->assertSame($cleanSignature, $migratedSignature);
        $this->assertSame(
            ['idx_turmas_ativo_ano', 'ux_turmas_nome_normalizado_ano'],
            array_keys($cleanSignature['turmas']['indexes'])
        );
        $this->assertSame(
            ['idx_alunos_nascimento', 'idx_alunos_nome_normalizado', 'idx_alunos_turma_ativo'],
            array_keys($cleanSignature['alunos']['indexes'])
        );
        $this->assertSame(
            [
                'trg_protect_class_with_active_students',
                'trg_require_class_normalized_name_insert',
                'trg_require_class_normalized_name_update',
                'trg_validate_class_active_insert',
                'trg_validate_class_active_update',
            ],
            $cleanSignature['turmas']['triggers']
        );
        $this->assertSame(
            [
                'trg_prevent_student_delete',
                'trg_require_student_normalized_name_insert',
                'trg_require_student_normalized_name_update',
                'trg_validate_student_active_insert',
                'trg_validate_student_active_update',
            ],
            $cleanSignature['alunos']['triggers']
        );
    }

    public function testDatabaseRejectsNullEmptyAndWhitespaceNormalizedNamesOnInsertAndUpdate(): void
    {
        $path = $this->path('guards.sqlite');
        $pdo = $this->connection($path);
        $this->initialize($pdo, $path);

        foreach ([null, '', " \t\r\n ", "\u{00A0}\u{2003}"] as $index => $invalid) {
            $this->assertDatabaseRejects(static function () use ($pdo, $invalid, $index): void {
                $statement = $pdo->prepare(
                    'INSERT INTO turmas (nome_turma, nome_normalizado, ano_letivo) VALUES (?, ?, ?)'
                );
                $statement->execute(['Turma Invalida ' . $index, $invalid, 2030 + $index]);
            });
            $this->assertDatabaseRejects(static function () use ($pdo, $invalid, $index): void {
                $statement = $pdo->prepare(
                    'INSERT INTO alunos (nome_completo, nome_normalizado, data_nascimento) VALUES (?, ?, ?)'
                );
                $statement->execute(['Aluno Invalido ' . $index, $invalid, '2010-01-01']);
            });
        }

        $pdo->exec(
            "INSERT INTO turmas (id, nome_turma, nome_normalizado, ano_letivo)
             VALUES (10, 'Turma Valida', 'turma valida', 2026);
             INSERT INTO alunos (id, nome_completo, nome_normalizado, data_nascimento, id_turma)
             VALUES (101, 'Aluno Valido', 'aluno valido', '2010-01-01', 10);"
        );

        foreach ([null, '', " \t\r\n ", "\u{00A0}\u{2003}"] as $invalid) {
            $this->assertDatabaseRejects(static function () use ($pdo, $invalid): void {
                $pdo->prepare('UPDATE turmas SET nome_normalizado = ? WHERE id = 10')->execute([$invalid]);
            });
            $this->assertDatabaseRejects(static function () use ($pdo, $invalid): void {
                $pdo->prepare('UPDATE alunos SET nome_normalizado = ? WHERE id = 101')->execute([$invalid]);
            });
        }

        $this->assertSame('turma valida', $pdo->query('SELECT nome_normalizado FROM turmas WHERE id = 10')->fetchColumn());
        $this->assertSame('aluno valido', $pdo->query('SELECT nome_normalizado FROM alunos WHERE id = 101')->fetchColumn());
    }

    public function testFailureAfterRebuildRollsBackEverythingAndKeepsBackup(): void
    {
        $path = $this->path('rollback.sqlite');
        $pdo = $this->connection($path);
        $this->createVersionTenSchema($pdo);
        $this->insertVersionTenHistory($pdo);
        $beforeData = $this->preservedModuleData($pdo);
        $beforeSchema = $this->moduleSchemaSignature($pdo);
        $beforeSequences = $this->sequences($pdo);
        $beforeRelationships = [
            'students' => $this->relationships($pdo, 'alunos', 'id_turma'),
            'dvas' => $this->relationships($pdo, 'dvas', 'id_aluno'),
        ];
        $pdo->exec(
            "CREATE TRIGGER fail_version_eleven_for_test
             BEFORE INSERT ON schema_migrations
             WHEN NEW.version = 11
             BEGIN SELECT RAISE(ABORT, 'simulated_v11_failure'); END"
        );

        try {
            $this->initialize($pdo, $path);
            $this->fail('A falha simulada deveria interromper a migracao v11.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('simulated_v11_failure', $exception->getMessage());
        }

        $this->assertSame(10, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 11')->fetchColumn());
        $this->assertSame($beforeData, $this->preservedModuleData($pdo));
        $this->assertSame($beforeSchema, $this->moduleSchemaSignature($pdo));
        $this->assertSame($beforeSequences, $this->sequences($pdo));
        $this->assertSame($beforeRelationships['students'], $this->relationships($pdo, 'alunos', 'id_turma'));
        $this->assertSame($beforeRelationships['dvas'], $this->relationships($pdo, 'dvas', 'id_aluno'));
        $this->assertSame([], $this->temporaryTables($pdo));
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertCount(1, $this->backups());
        $backup = $this->connection($this->backups()[0]);
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testVersionElevenRejectsUnicodeCollisionBeforeRebuild(): void
    {
        $path = $this->path('collision.sqlite');
        $pdo = $this->connection($path);
        $this->createVersionTenSchema($pdo);
        $pdo->exec(
            "INSERT INTO turmas (id, nome_turma, nome_normalizado, ano_letivo, criado_em, atualizado_em)
             VALUES
                (10, 'Turma \u{00C1}lvares', 'chave-legada-a', 2026, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'TURMA A\u{0301}LVARES', 'chave-legada-b', 2026, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);"
        );
        $before = $this->preservedModuleData($pdo);

        try {
            $this->initialize($pdo, $path);
            $this->fail('A colisao Unicode deveria interromper a migracao v11.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('IDs 10,20', $exception->getMessage());
            $this->assertStringNotContainsString('Alvares', $exception->getMessage());
        }

        $this->assertSame(10, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(0, $this->columnNotNull($pdo, 'turmas', 'nome_normalizado'));
        $this->assertSame($before, $this->preservedModuleData($pdo));
        $this->assertSame([], $this->temporaryTables($pdo));
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertCount(1, $this->backups());
    }

    private function createVersionTenSchema(PDO $pdo): void
    {
        $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
        $this->assertIsString($schema);
        $schema = str_replace('nome_normalizado TEXT NOT NULL', 'nome_normalizado TEXT NULL', $schema, $count);
        $this->assertSame(2, $count);
        $pdo->exec($schema);
        $this->markMigrations($pdo, 10);
        $pdo->exec(
            "CREATE UNIQUE INDEX ux_turmas_nome_normalizado_ano
                ON turmas (nome_normalizado, ano_letivo) WHERE ano_letivo IS NOT NULL;
             CREATE INDEX idx_turmas_ativo_ano ON turmas (ativo, ano_letivo DESC, nome_turma);
             CREATE INDEX idx_alunos_nome_normalizado ON alunos (nome_normalizado);
             CREATE INDEX idx_alunos_turma_ativo ON alunos (id_turma, ativo);
             CREATE INDEX idx_alunos_nascimento ON alunos (data_nascimento);
             CREATE TRIGGER trg_require_student_normalized_name
                BEFORE INSERT ON alunos
                WHEN NEW.nome_normalizado IS NULL OR trim(NEW.nome_normalizado) = ''
                BEGIN SELECT RAISE(ABORT, 'student_normalized_name_required'); END;
             CREATE TRIGGER trg_require_class_normalized_name
                BEFORE INSERT ON turmas
                WHEN NEW.nome_normalizado IS NULL OR trim(NEW.nome_normalizado) = ''
                BEGIN SELECT RAISE(ABORT, 'class_normalized_name_required'); END;"
        );
    }

    private function createVersionNineSchema(PDO $pdo): void
    {
        $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
        $this->assertIsString($schema);
        $schema = preg_replace('/^\s*nome_normalizado TEXT NOT NULL,\R/m', '', $schema, -1, $count);
        $this->assertIsString($schema);
        $this->assertSame(2, $count);
        $pdo->exec($schema);
        $this->markMigrations($pdo, 9);
    }

    private function markMigrations(PDO $pdo, int $version): void
    {
        $insert = $pdo->prepare(
            'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)'
        );

        for ($current = 1; $current <= $version; $current++) {
            $insert->execute([$current, '2025-01-01 00:00:00']);
        }

        $pdo->exec('PRAGMA user_version = ' . $version);
    }

    private function insertVersionTenHistory(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO usuarios
                (id, nome, email, senha, tipo, criado_em, atualizado_em)
             VALUES
                (7, 'Usuario Teste', 'v10@example.test', 'hash-artificial', 'administrador',
                 '2024-01-01 00:00:00', '2024-01-01 00:00:00');
             INSERT INTO turmas
                (id, nome_turma, nome_normalizado, ano_letivo, ativo, criado_em, atualizado_em)
             VALUES
                (10, 'Turma \u{00C1}lvares', 'chave-antiga-1', 2026, 1, '2024-01-02 03:04:05', '2024-02-03 04:05:06'),
                (20, 'Turma B', 'chave-antiga-2', 2027, 0, '2024-02-02 03:04:05', '2024-03-03 04:05:06');
             INSERT INTO alunos
                (id, nome_completo, nome_normalizado, data_nascimento, id_turma,
                 telefone_aluno, telefone_responsavel, ativo, criado_em, atualizado_em,
                 inativado_em, inativado_por)
             VALUES
                (101, 'Aluno \u{00C1}ureo', 'chave-antiga-3', '2010-03-04', 10,
                 '65999990000', '6533330000', 1, '2024-03-02 03:04:05', '2024-04-03 04:05:06', NULL, NULL),
                (102, 'Aluno B', 'chave-antiga-4', '2011-04-05', 20,
                 NULL, '65988880000', 0, '2024-04-02 03:04:05', '2024-05-03 04:05:06',
                 '2025-01-02 03:04:05', 7);
             INSERT INTO dvas
                (id, id_aluno, id_usuario_registro, data_vencimento, observacao, ativo,
                 criado_em, atualizado_em, substituido_em)
             VALUES
                (201, 101, 7, '2025-01-01', 'Versao historica', 0,
                 '2024-04-02 03:04:05', '2025-04-05 06:07:08', '2025-04-05 06:07:08'),
                (202, 101, 7, '2027-01-01', 'Versao vigente', 1,
                 '2025-04-05 06:07:08', '2025-04-05 06:07:08', NULL);
             UPDATE sqlite_sequence SET seq = 80 WHERE name = 'turmas';
             UPDATE sqlite_sequence SET seq = 180 WHERE name = 'alunos';
             UPDATE sqlite_sequence SET seq = 280 WHERE name = 'dvas';"
        );
    }

    private function initialize(PDO $pdo, string $path): void
    {
        $_ENV['DB_PATH'] = $path;
        putenv('DB_PATH=' . $path);
        DatabaseInitializer::initialize($pdo);
    }

    private function connection(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function path(string $name): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $name;
    }

    /** @return list<string> */
    private function backups(): array
    {
        $items = glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite');

        return $items === false ? [] : array_values($items);
    }

    private function columnNotNull(PDO $pdo, string $table, string $column): int
    {
        foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll() as $metadata) {
            if ($metadata['name'] === $column) {
                return (int) $metadata['notnull'];
            }
        }

        return -1;
    }

    /** @return array<int,int|null> */
    private function relationships(PDO $pdo, string $table, string $foreignColumn): array
    {
        $relationships = [];
        $rows = $pdo->query("SELECT id, {$foreignColumn} FROM {$table} ORDER BY id")->fetchAll();

        foreach ($rows as $row) {
            $relationships[(int) $row['id']] = $row[$foreignColumn] === null ? null : (int) $row[$foreignColumn];
        }

        return $relationships;
    }

    /** @return array<string,int> */
    private function sequences(PDO $pdo): array
    {
        $sequences = [];
        $rows = $pdo->query(
            "SELECT name, seq FROM sqlite_sequence
             WHERE name IN ('turmas', 'alunos', 'dvas') ORDER BY name"
        )->fetchAll();

        foreach ($rows as $row) {
            $sequences[(string) $row['name']] = (int) $row['seq'];
        }

        return $sequences;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function preservedModuleData(PDO $pdo): array
    {
        return [
            'classes' => $pdo->query(
                'SELECT id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em FROM turmas ORDER BY id'
            )->fetchAll(),
            'students' => $pdo->query(
                'SELECT id, nome_completo, data_nascimento, id_turma, telefone_aluno, telefone_responsavel,
                        ativo, criado_em, atualizado_em, inativado_em, inativado_por FROM alunos ORDER BY id'
            )->fetchAll(),
            'dvas' => $pdo->query(
                'SELECT id, id_aluno, id_usuario_registro, data_vencimento, observacao, ativo,
                        criado_em, atualizado_em, substituido_em FROM dvas ORDER BY id'
            )->fetchAll(),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function moduleSchemaSignature(PDO $pdo): array
    {
        $signature = [];

        foreach (['turmas', 'alunos'] as $table) {
            $columns = [];

            foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll() as $column) {
                $columns[] = [
                    'name' => $column['name'],
                    'type' => $column['type'],
                    'notnull' => (int) $column['notnull'],
                    'default' => $column['dflt_value'],
                    'primary_key' => (int) $column['pk'],
                ];
            }

            $foreignKeys = [];

            foreach ($pdo->query('PRAGMA foreign_key_list("' . $table . '")')->fetchAll() as $foreignKey) {
                $foreignKeys[] = [
                    'table' => $foreignKey['table'],
                    'from' => $foreignKey['from'],
                    'to' => $foreignKey['to'],
                    'on_update' => $foreignKey['on_update'],
                    'on_delete' => $foreignKey['on_delete'],
                    'match' => $foreignKey['match'],
                ];
            }

            usort($foreignKeys, static fn (array $left, array $right): int => ($left['from'] <=> $right['from']));
            $indexes = [];

            foreach ($pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll() as $index) {
                $name = (string) $index['name'];

                if (!str_starts_with($name, 'idx_') && !str_starts_with($name, 'ux_')) {
                    continue;
                }

                $quotedName = str_replace('"', '""', $name);
                $indexes[$name] = [
                    'unique' => (int) $index['unique'],
                    'origin' => $index['origin'],
                    'partial' => (int) $index['partial'],
                    'columns' => $pdo->query('PRAGMA index_info("' . $quotedName . '")')->fetchAll(PDO::FETCH_COLUMN, 2),
                ];
            }

            ksort($indexes);
            $trigger = $pdo->prepare(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = :table ORDER BY name"
            );
            $trigger->execute(['table' => $table]);
            $signature[$table] = [
                'columns' => $columns,
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes,
                'triggers' => $trigger->fetchAll(PDO::FETCH_COLUMN),
            ];
        }

        return $signature;
    }

    /** @return list<string> */
    private function temporaryTables(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name IN ('turmas_v11', 'alunos_v11') ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param callable():void $operation */
    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('O banco deveria rejeitar o nome normalizado invalido.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }
}
