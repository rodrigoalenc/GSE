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

        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(11, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertSame(1, $this->columnNotNull($pdo, 'turmas', 'nome_normalizado'));
        $this->assertSame(1, $this->columnNotNull($pdo, 'alunos', 'nome_normalizado'));
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

    public function testLegacyStudentsClassesAndMultipleDvasArePreservedDeterministically(): void
    {
        $legacy = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec(
            "CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
                senha TEXT NOT NULL, tipo TEXT NOT NULL, criado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE turmas (id INTEGER PRIMARY KEY AUTOINCREMENT, nome_turma TEXT NOT NULL UNIQUE);
            CREATE TABLE alunos (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome_completo TEXT NOT NULL, data_nascimento TEXT NOT NULL,
                id_turma INTEGER NULL, telefone_aluno TEXT NULL, telefone_responsavel TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE dvas (
                id INTEGER PRIMARY KEY AUTOINCREMENT, id_aluno INTEGER NOT NULL,
                id_usuario_registro INTEGER NULL, data_vencimento TEXT NOT NULL,
                observacao TEXT NULL, criado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO usuarios (nome, email, senha, tipo) VALUES
                ('Admin Legado', 'admin.legado@example.test', 'hash-legado-nao-real', 'admin');
            INSERT INTO turmas (nome_turma) VALUES ('Turma Histórica');
            INSERT INTO alunos (nome_completo, data_nascimento, id_turma) VALUES
                ('Aluno Preservado', '2010-01-02', 1);
            INSERT INTO dvas (id_aluno, id_usuario_registro, data_vencimento, observacao, criado_em) VALUES
                (1, 1, '2025-01-01', 'Primeira', '2024-01-01 10:00:00'),
                (1, 1, '2026-01-01', 'Segunda', '2025-01-01 10:00:00'),
                (1, 1, '2027-01-01', 'Terceira', '2025-01-01 10:00:00');"
        );
        $legacy = null;

        $pdo = Database::getConnection();
        DatabaseInitializer::initialize($pdo);
        DatabaseInitializer::initialize($pdo);

        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT id FROM dvas WHERE ativo = 1')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM dvas WHERE ativo = 1')->fetchColumn());
        $this->assertNull($pdo->query('SELECT ano_letivo FROM turmas WHERE id = 1')->fetchColumn() ?: null);
        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertCount(1, glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite') ?: []);
        $pdo->exec("INSERT INTO turmas (nome_turma, nome_normalizado, ano_letivo) VALUES ('Turma Histórica', 'turma histórica', 2026)");
        $pdo->exec("INSERT INTO turmas (nome_turma, nome_normalizado, ano_letivo) VALUES ('Turma Histórica', 'turma histórica', 2027)");
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO dvas (id_aluno, data_vencimento, ativo) VALUES (1, ?, 1)')->execute(['2028-01-01']);
    }

    public function testModuleOneUpgradePreservesEveryStudentClassRelationshipWithRealForeignKey(): void
    {
        $legacy = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec('PRAGMA foreign_keys = ON');
        $legacy->exec(
            "CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
                senha TEXT NOT NULL, tipo TEXT NOT NULL, ativo INTEGER NOT NULL DEFAULT 1,
                session_version INTEGER NOT NULL DEFAULT 1, deve_alterar_senha INTEGER NOT NULL DEFAULT 0,
                password_changed_at TEXT NULL, criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT
            );
            CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
            CREATE TABLE login_attempts (
                key_type TEXT NOT NULL, key_hash TEXT NOT NULL, failure_count INTEGER NOT NULL DEFAULT 0,
                first_failure_at TEXT NOT NULL, last_failure_at TEXT NOT NULL, blocked_until TEXT NULL,
                PRIMARY KEY (key_type, key_hash)
            );
            CREATE TABLE security_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT NOT NULL, action TEXT NOT NULL,
                result TEXT NOT NULL, actor_user_id INTEGER, target_user_id INTEGER, ip_address TEXT,
                request_id TEXT NOT NULL, description TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE turmas (id INTEGER PRIMARY KEY AUTOINCREMENT, nome_turma TEXT NOT NULL UNIQUE);
            CREATE TABLE alunos (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome_completo TEXT NOT NULL, data_nascimento TEXT NOT NULL,
                id_turma INTEGER NULL, telefone_aluno TEXT NULL, telefone_responsavel TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_turma) REFERENCES turmas(id) ON DELETE SET NULL
            );
            CREATE TABLE dvas (
                id INTEGER PRIMARY KEY AUTOINCREMENT, id_aluno INTEGER NOT NULL,
                id_usuario_registro INTEGER NULL, data_vencimento TEXT NOT NULL,
                observacao TEXT NULL, criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_aluno) REFERENCES alunos(id) ON DELETE RESTRICT,
                FOREIGN KEY (id_usuario_registro) REFERENCES usuarios(id) ON DELETE SET NULL
            );
            INSERT INTO schema_migrations (version, applied_at) VALUES
                (1, CURRENT_TIMESTAMP), (2, CURRENT_TIMESTAMP), (3, CURRENT_TIMESTAMP), (4, CURRENT_TIMESTAMP);
            PRAGMA user_version = 4;
            INSERT INTO usuarios (id, nome, email, senha, tipo) VALUES
                (7, 'Admin Legado', 'admin@example.test', 'hash-de-teste', 'administrador');
            INSERT INTO turmas (id, nome_turma) VALUES (10, 'Turma A'), (20, 'Turma B');
            INSERT INTO alunos (id, nome_completo, data_nascimento, id_turma) VALUES
                (101, 'Aluno Um', '2010-01-01', 10),
                (102, 'Aluno Dois', '2011-02-02', 20),
                (103, 'Aluno Tres', '2012-03-03', 10);
            INSERT INTO dvas (id, id_aluno, id_usuario_registro, data_vencimento, observacao) VALUES
                (201, 101, 7, '2026-10-01', 'DVA de teste'),
                (202, 102, 7, '2026-11-01', 'Outra DVA');"
        );
        $this->assertSame(1, (int) $legacy->query('PRAGMA foreign_keys')->fetchColumn());
        $legacy = null;

        $pdo = Database::getConnection();
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        DatabaseInitializer::initialize($pdo);

        $relationships = $pdo->query('SELECT id, id_turma FROM alunos ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        $this->assertSame([101 => 10, 102 => 20, 103 => 10], array_map('intval', $relationships));
        $this->assertSame([10, 20], array_map('intval', $pdo->query('SELECT id FROM turmas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([101, 102, 103], array_map('intval', $pdo->query('SELECT id FROM alunos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([201, 202], array_map('intval', $pdo->query('SELECT id FROM dvas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT recebe_alertas_dva FROM usuarios WHERE id = 7')->fetchColumn());

        $pdo->exec("INSERT INTO turmas (nome_turma, nome_normalizado, ano_letivo) VALUES ('Turma A', 'turma a', 2026), ('Turma A', 'turma a', 2027)");
        DatabaseInitializer::initialize($pdo);
        $this->assertSame([101 => 10, 102 => 20, 103 => 10], array_map(
            'intval',
            $pdo->query('SELECT id, id_turma FROM alunos ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)
        ));
        $this->assertSame(11, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testUnicodeMigrationAbortsSafelyWhenLegacyClassesCollide(): void
    {
        $legacy = new PDO('sqlite:' . $this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec(
            "CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
             CREATE TABLE turmas (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome_turma TEXT NOT NULL, ano_letivo INTEGER,
                ativo INTEGER NOT NULL DEFAULT 1, criado_em TEXT, atualizado_em TEXT
             );
             CREATE TABLE alunos (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nome_completo TEXT NOT NULL, data_nascimento TEXT NOT NULL,
                id_turma INTEGER, telefone_aluno TEXT, telefone_responsavel TEXT, ativo INTEGER NOT NULL DEFAULT 1,
                criado_em TEXT, atualizado_em TEXT, inativado_em TEXT, inativado_por INTEGER,
                FOREIGN KEY (id_turma) REFERENCES turmas(id) ON DELETE SET NULL
             );
             CREATE TABLE dvas (
                id INTEGER PRIMARY KEY AUTOINCREMENT, id_aluno INTEGER NOT NULL, id_usuario_registro INTEGER,
                data_vencimento TEXT NOT NULL, observacao TEXT, ativo INTEGER NOT NULL DEFAULT 1,
                criado_em TEXT, atualizado_em TEXT, substituido_em TEXT,
                FOREIGN KEY (id_aluno) REFERENCES alunos(id) ON DELETE RESTRICT
             );
             INSERT INTO schema_migrations (version, applied_at) VALUES
                (1, CURRENT_TIMESTAMP), (2, CURRENT_TIMESTAMP), (3, CURRENT_TIMESTAMP),
                (4, CURRENT_TIMESTAMP), (5, CURRENT_TIMESTAMP), (6, CURRENT_TIMESTAMP),
                (7, CURRENT_TIMESTAMP), (8, CURRENT_TIMESTAMP), (9, CURRENT_TIMESTAMP);
             PRAGMA user_version = 9;"
        );
        $insertClass = $legacy->prepare(
            'INSERT INTO turmas (id, nome_turma, ano_letivo, criado_em, atualizado_em) VALUES (?, ?, 2026, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $insertClass->execute([10, 'Turma Álvares']);
        $insertClass->execute([20, "TURMA A\u{0301}LVARES"]);
        $legacy->exec(
            "INSERT INTO alunos (id, nome_completo, data_nascimento, id_turma) VALUES
                (101, 'Aluno Preservado', '2010-01-01', 10);
             INSERT INTO dvas (id, id_aluno, data_vencimento) VALUES
                (201, 101, '2026-12-01');"
        );
        $legacy = null;

        $pdo = Database::getConnection();

        try {
            DatabaseInitializer::initialize($pdo);
            $this->fail('A colisão Unicode deveria interromper a migração.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('IDs 10,20', $exception->getMessage());
        }

        $this->assertSame(9, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertNotContains('nome_normalizado', array_column($pdo->query('PRAGMA table_info(turmas)')->fetchAll(), 'name'));
        $this->assertSame([101 => 10], array_map(
            'intval',
            $pdo->query('SELECT id, id_turma FROM alunos')->fetchAll(PDO::FETCH_KEY_PAIR)
        ));
        $this->assertSame([201], array_map('intval', $pdo->query('SELECT id FROM dvas')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertCount(1, glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.sqlite') ?: []);
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
}
