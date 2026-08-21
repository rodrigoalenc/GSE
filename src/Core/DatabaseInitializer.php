<?php

declare(strict_types=1);

namespace src\Core;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseInitializer
{
    private const LATEST_VERSION = 9;

    public static function initialize(PDO $pdo): void
    {
        $schemaPath = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/database/schema.sql';

        if (!is_readable($schemaPath)) {
            throw new RuntimeException('O arquivo database/schema.sql nao foi encontrado.');
        }

        $schema = file_get_contents($schemaPath);

        if ($schema === false) {
            throw new RuntimeException('Nao foi possivel ler o schema do banco.');
        }

        $applied = self::appliedVersions($pdo);

        if (count($applied) === self::LATEST_VERSION
            && min($applied) === 1
            && max($applied) === self::LATEST_VERSION) {
            Database::protectFiles();

            return;
        }

        $backupPath = self::hasApplicationTables($pdo) ? self::createValidatedBackup($pdo) : null;

        try {
            for ($version = 1; $version <= self::LATEST_VERSION; $version++) {
                if (in_array($version, $applied, true)) {
                    continue;
                }

                self::runMigration($pdo, $version, $schema);
            }

            Database::protectFiles();
        } catch (Throwable $exception) {
            if ($backupPath !== null && class_exists('\TechnicalLogger', false)) {
                \TechnicalLogger::error('database_migration_failed', [
                    'backup_created' => 'yes',
                    'exception' => $exception::class,
                ]);
            }

            throw $exception;
        }
    }

    private static function runMigration(PDO $pdo, int $version, string $schema): void
    {
        $rebuildsClasses = $version === 6 && self::classesRequireRebuild($pdo);
        $foreignKeysBefore = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();

        if ($rebuildsClasses) {
            // SQLite ignora mudancas de foreign_keys dentro de uma transacao. A
            // desativacao ocorre antes do BEGIN IMMEDIATE e e sempre restaurada.
            $pdo->exec('PRAGMA foreign_keys = OFF');

            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 0) {
                throw new RuntimeException('Nao foi possivel preparar a migracao segura de turmas.');
            }
        }

        try {
            SqliteTransaction::immediate($pdo, static function (PDO $pdo) use ($schema, $version): void {
                self::applyMigration($pdo, $version, $schema);

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $insert->execute([
                    'version' => $version,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $pdo->exec('PRAGMA user_version = ' . $version);
            });
        } finally {
            if ($rebuildsClasses) {
                $pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeysBefore === 1 ? 'ON' : 'OFF'));

                if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== $foreignKeysBefore) {
                    throw new RuntimeException('Nao foi possivel restaurar a protecao de chaves estrangeiras.');
                }
            }
        }

        if ($rebuildsClasses && self::foreignKeyViolations($pdo) !== []) {
            throw new RuntimeException('A migracao de turmas deixou relacionamentos invalidos.');
        }
    }

    private static function applyMigration(PDO $pdo, int $version, string $schema): void
    {
        match ($version) {
            1 => $pdo->exec($schema),
            2 => self::migrateLegacyUsers($pdo),
            3 => self::ensureCaseInsensitiveEmailUniqueness($pdo),
            4 => self::ensureLastAdminTrigger($pdo),
            5 => self::migrateStudents($pdo),
            6 => self::migrateClasses($pdo),
            7 => self::migrateDvas($pdo),
            8 => self::migrateAuditAndAlerts($pdo),
            9 => self::ensureModuleTwoGuardsAndNotifications($pdo),
            default => throw new RuntimeException('Versao de migracao desconhecida.'),
        };
    }

    private static function migrateLegacyUsers(PDO $pdo): void
    {
        self::addColumnIfMissing($pdo, 'usuarios', 'ativo', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'usuarios', 'atualizado_em', 'TEXT');
        self::addColumnIfMissing($pdo, 'usuarios', 'session_version', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'usuarios', 'deve_alterar_senha', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'usuarios', 'password_changed_at', 'TEXT');

        $pdo->exec("UPDATE usuarios SET tipo = 'administrador' WHERE tipo = 'admin'");
        $pdo->exec("UPDATE usuarios SET tipo = 'funcionario' WHERE tipo IN ('usuario', 'funcionário', 'funcionario')");
        $pdo->exec("UPDATE usuarios SET tipo = 'funcionario' WHERE tipo NOT IN ('administrador', 'funcionario')");
        $pdo->exec('UPDATE usuarios SET ativo = 1 WHERE ativo IS NULL OR ativo NOT IN (0, 1)');
        $pdo->exec('UPDATE usuarios SET session_version = 1 WHERE session_version IS NULL OR session_version < 1');
        $pdo->exec('UPDATE usuarios SET deve_alterar_senha = 0 WHERE deve_alterar_senha IS NULL OR deve_alterar_senha NOT IN (0, 1)');
        $pdo->exec("UPDATE usuarios SET atualizado_em = COALESCE(atualizado_em, criado_em, CURRENT_TIMESTAMP)");
    }

    private static function ensureCaseInsensitiveEmailUniqueness(PDO $pdo): void
    {
        $duplicate = $pdo->query(
            'SELECT lower(trim(email)) AS normalized FROM usuarios
             GROUP BY lower(trim(email)) HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();

        if ($duplicate !== false) {
            throw new RuntimeException(
                'A migracao foi interrompida: existem e-mails duplicados que diferem apenas por maiusculas e minusculas.'
            );
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_usuarios_email_nocase ON usuarios (email COLLATE NOCASE)');
    }

    private static function ensureLastAdminTrigger(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_preserve_last_active_admin
             BEFORE UPDATE OF tipo, ativo ON usuarios
             WHEN OLD.tipo = 'administrador' AND OLD.ativo = 1
              AND (NEW.tipo <> 'administrador' OR NEW.ativo <> 1)
              AND (SELECT COUNT(*) FROM usuarios WHERE tipo = 'administrador' AND ativo = 1) <= 1
             BEGIN
                SELECT RAISE(ABORT, 'last_active_admin');
             END"
        );

        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_prevent_delete_last_active_admin
             BEFORE DELETE ON usuarios
             WHEN OLD.tipo = 'administrador' AND OLD.ativo = 1
              AND (SELECT COUNT(*) FROM usuarios WHERE tipo = 'administrador' AND ativo = 1) <= 1
             BEGIN
                SELECT RAISE(ABORT, 'last_active_admin');
             END"
        );
    }

    private static function migrateStudents(PDO $pdo): void
    {
        self::addColumnIfMissing($pdo, 'alunos', 'ativo', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'alunos', 'atualizado_em', 'TEXT');
        self::addColumnIfMissing($pdo, 'alunos', 'inativado_em', 'TEXT');
        self::addColumnIfMissing($pdo, 'alunos', 'inativado_por', 'INTEGER REFERENCES usuarios(id) ON DELETE SET NULL');

        $pdo->exec('UPDATE alunos SET ativo = 1 WHERE ativo IS NULL OR ativo NOT IN (0, 1)');
        $pdo->exec('UPDATE alunos SET atualizado_em = COALESCE(atualizado_em, criado_em)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_nome ON alunos (nome_completo COLLATE NOCASE)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_turma_ativo ON alunos (id_turma, ativo)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_nascimento ON alunos (data_nascimento)');
    }

    private static function migrateClasses(PDO $pdo): void
    {
        $requiresRebuild = self::classesRequireRebuild($pdo);
        self::addColumnIfMissing($pdo, 'turmas', 'ano_letivo', 'INTEGER');
        self::addColumnIfMissing($pdo, 'turmas', 'ativo', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumnIfMissing($pdo, 'turmas', 'criado_em', 'TEXT');
        self::addColumnIfMissing($pdo, 'turmas', 'atualizado_em', 'TEXT');

        $pdo->exec('UPDATE turmas SET ativo = 1 WHERE ativo IS NULL OR ativo NOT IN (0, 1)');

        if ($requiresRebuild) {
            $beforeCounts = self::moduleTwoCounts($pdo);
            $classIdsBefore = self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id');
            $studentIdsBefore = self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id');
            $dvaIdsBefore = self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id');
            $relationshipsBefore = self::studentClassRelationships($pdo);
            $pdo->exec(
                'CREATE TABLE turmas_v6 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nome_turma TEXT NOT NULL,
                    ano_letivo INTEGER NULL,
                    ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
                    criado_em TEXT NULL,
                    atualizado_em TEXT NULL
                )'
            );
            $pdo->exec(
                'INSERT INTO turmas_v6 (id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em)
                 SELECT id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em FROM turmas'
            );

            if ((int) $pdo->query('SELECT COUNT(*) FROM turmas_v6')->fetchColumn() !== $beforeCounts['classes']) {
                throw new RuntimeException('A migracao de turmas falhou na validacao de contagem.');
            }

            $pdo->exec('DROP TABLE turmas');
            $pdo->exec('ALTER TABLE turmas_v6 RENAME TO turmas');

            if (self::moduleTwoCounts($pdo) !== $beforeCounts
                || self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id') !== $classIdsBefore
                || self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id') !== $studentIdsBefore
                || self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id') !== $dvaIdsBefore
                || self::studentClassRelationships($pdo) !== $relationshipsBefore
                || self::foreignKeyViolations($pdo) !== []) {
                throw new RuntimeException('A migracao de turmas nao preservou integralmente os dados e relacionamentos.');
            }
        }

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_turmas_nome_ano
             ON turmas (nome_turma COLLATE NOCASE, ano_letivo)
             WHERE ano_letivo IS NOT NULL'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_turmas_ativo_ano ON turmas (ativo, ano_letivo DESC, nome_turma)');
    }

    private static function migrateDvas(PDO $pdo): void
    {
        $hadActiveColumn = self::columnExists($pdo, 'dvas', 'ativo');
        self::addColumnIfMissing($pdo, 'dvas', 'ativo', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'dvas', 'atualizado_em', 'TEXT');
        self::addColumnIfMissing($pdo, 'dvas', 'substituido_em', 'TEXT');

        if (!$hadActiveColumn) {
            $pdo->exec('UPDATE dvas SET ativo = 0');
            $pdo->exec(
                'UPDATE dvas SET ativo = 1
                 WHERE id = (
                    SELECT atual.id FROM dvas AS atual
                    WHERE atual.id_aluno = dvas.id_aluno
                    ORDER BY COALESCE(atual.criado_em, \'\') DESC, atual.id DESC
                    LIMIT 1
                 )'
            );
        } else {
            $pdo->exec('UPDATE dvas SET ativo = 0 WHERE ativo IS NULL OR ativo NOT IN (0, 1)');
        }

        $pdo->exec('UPDATE dvas SET atualizado_em = COALESCE(atualizado_em, criado_em)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_dvas_um_ativo_por_aluno ON dvas (id_aluno) WHERE ativo = 1');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dvas_ativo_vencimento ON dvas (ativo, data_vencimento)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dvas_aluno_historico ON dvas (id_aluno, criado_em DESC, id DESC)');
    }

    private static function migrateAuditAndAlerts(PDO $pdo): void
    {
        self::addColumnIfMissing($pdo, 'security_audit', 'resource_type', 'TEXT');
        self::addColumnIfMissing($pdo, 'security_audit', 'resource_id', 'INTEGER');
        self::addColumnIfMissing($pdo, 'usuarios', 'recebe_alertas_dva', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec('UPDATE usuarios SET recebe_alertas_dva = 0 WHERE recebe_alertas_dva IS NULL OR recebe_alertas_dva NOT IN (0, 1)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_audit_resource ON security_audit (resource_type, resource_id)');
    }

    private static function classesRequireRebuild(PDO $pdo): bool
    {
        if (!self::tableExists($pdo, 'turmas')) {
            return false;
        }

        foreach ($pdo->query('PRAGMA index_list(turmas)')->fetchAll() as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }

            $indexName = str_replace('"', '""', (string) ($index['name'] ?? ''));

            if ($indexName === '') {
                continue;
            }

            $columns = $pdo->query('PRAGMA index_info("' . $indexName . '")')->fetchAll(PDO::FETCH_COLUMN, 2);

            if ($columns === ['nome_turma']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{classes:int,students:int,dvas:int} */
    private static function moduleTwoCounts(PDO $pdo): array
    {
        return [
            'classes' => (int) $pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn(),
            'students' => (int) $pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn(),
            'dvas' => (int) $pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn(),
        ];
    }

    /** @return list<int> */
    private static function integerColumn(PDO $pdo, string $sql): array
    {
        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,int|null> */
    private static function studentClassRelationships(PDO $pdo): array
    {
        $relationships = [];

        foreach ($pdo->query('SELECT id, id_turma FROM alunos ORDER BY id')->fetchAll() as $row) {
            $relationships[(int) $row['id']] = $row['id_turma'] === null ? null : (int) $row['id_turma'];
        }

        return $relationships;
    }

    /** @return list<array<string,mixed>> */
    private static function foreignKeyViolations(PDO $pdo): array
    {
        return $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    }

    private static function ensureModuleTwoGuardsAndNotifications(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dva_notification_deliveries (
                notification_date TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                sent_at TEXT NULL,
                status TEXT NOT NULL CHECK (status IN ('processing', 'sent', 'failed')),
                last_error_code TEXT NULL,
                PRIMARY KEY (notification_date, user_id),
                FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
            )"
        );

        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_prevent_student_delete
             BEFORE DELETE ON alunos
             BEGIN
                SELECT RAISE(ABORT, 'student_physical_delete_forbidden');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_prevent_dva_delete
             BEFORE DELETE ON dvas
             BEGIN
                SELECT RAISE(ABORT, 'dva_physical_delete_forbidden');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_protect_class_with_active_students
             BEFORE UPDATE OF ativo ON turmas
             WHEN OLD.ativo = 1 AND NEW.ativo = 0
              AND EXISTS (SELECT 1 FROM alunos WHERE id_turma = OLD.id AND ativo = 1)
             BEGIN
                SELECT RAISE(ABORT, 'class_has_active_students');
             END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_student_active_insert
             BEFORE INSERT ON alunos WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_student_active'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_student_active_update
             BEFORE UPDATE OF ativo ON alunos WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_student_active'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_class_active_update
             BEFORE UPDATE OF ativo ON turmas WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_class_active'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_class_active_insert
             BEFORE INSERT ON turmas WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_class_active'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_dva_active_update
             BEFORE UPDATE OF ativo ON dvas WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_dva_active'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_validate_dva_active_insert
             BEFORE INSERT ON dvas WHEN NEW.ativo NOT IN (0, 1)
             BEGIN SELECT RAISE(ABORT, 'invalid_dva_active'); END"
        );
    }

    /** @return list<int> */
    private static function appliedVersions(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'schema_migrations')) {
            return [];
        }

        return array_map(
            static fn (mixed $value): int => (int) $value,
            $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll();

        return in_array($column, array_column($columns, 'name'), true);
    }

    private static function createValidatedBackup(PDO $pdo): ?string
    {
        $databases = $pdo->query('PRAGMA database_list')->fetchAll();
        $row = array_values(array_filter($databases, static fn (array $item): bool => ($item['name'] ?? '') === 'main'))[0] ?? null;
        $databasePath = is_array($row) ? (string) ($row['file'] ?? '') : '';

        if ($databasePath === '' || $databasePath === ':memory:' || !is_file($databasePath)) {
            return null;
        }

        $directory = dirname($databasePath) . DIRECTORY_SEPARATOR . 'backups';

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Nao foi possivel criar o diretorio seguro de backup da migracao.');
        }

        $name = pathinfo($databasePath, PATHINFO_FILENAME)
            . '-pre-migration-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        $backupPath = $directory . DIRECTORY_SEPARATOR . $name;
        $pdo->exec('VACUUM main INTO ' . $pdo->quote($backupPath));

        if (!is_file($backupPath) || filesize($backupPath) === 0) {
            throw new RuntimeException('O backup preventivo da migracao nao pode ser validado.');
        }

        $verification = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ($verification->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('O backup preventivo da migracao falhou na verificacao de integridade.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($directory, 0700);
            @chmod($backupPath, 0600);
        }

        return $backupPath;
    }

    private static function hasApplicationTables(PDO $pdo): bool
    {
        foreach (['usuarios', 'alunos', 'turmas', 'dvas'] as $table) {
            if (self::tableExists($pdo, $table)) {
                return true;
            }
        }

        return false;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
