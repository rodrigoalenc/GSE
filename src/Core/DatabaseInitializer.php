<?php

declare(strict_types=1);

namespace src\Core;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/TextNormalizer.php';

final class DatabaseInitializer
{
    private const LATEST_VERSION = 11;

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
        $rebuildsNormalizedNames = $version === 11;
        $requiresForeignKeysOff = $rebuildsClasses || $rebuildsNormalizedNames;
        $foreignKeysBefore = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();

        if ($requiresForeignKeysOff) {
            // SQLite ignora mudancas de foreign_keys dentro de uma transacao. A
            // desativacao ocorre antes do BEGIN IMMEDIATE e e sempre restaurada.
            $pdo->exec('PRAGMA foreign_keys = OFF');

            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 0) {
                throw new RuntimeException('Nao foi possivel preparar a migracao estrutural segura.');
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
            if ($requiresForeignKeysOff) {
                $pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeysBefore === 1 ? 'ON' : 'OFF'));

                if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== $foreignKeysBefore) {
                    throw new RuntimeException('Nao foi possivel restaurar a protecao de chaves estrangeiras.');
                }
            }
        }

        if ($requiresForeignKeysOff && self::foreignKeyViolations($pdo) !== []) {
            throw new RuntimeException('A migracao estrutural deixou relacionamentos invalidos.');
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
            10 => self::normalizeModuleTwoNames($pdo),
            11 => self::enforceNormalizedNameSchema($pdo),
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

    /** @return list<string> */
    private static function integrityCheck(PDO $pdo): array
    {
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN)
        );
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

    private static function normalizeModuleTwoNames(PDO $pdo): void
    {
        $beforeCounts = self::moduleTwoCounts($pdo);
        $classIdsBefore = self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id');
        $studentIdsBefore = self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id');
        $dvaIdsBefore = self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id');
        $relationshipsBefore = self::studentClassRelationships($pdo);

        self::addColumnIfMissing($pdo, 'alunos', 'nome_normalizado', 'TEXT');
        self::addColumnIfMissing($pdo, 'turmas', 'nome_normalizado', 'TEXT');

        $updateStudent = $pdo->prepare(
            'UPDATE alunos SET nome_normalizado = :normalized WHERE id = :id'
        );

        foreach ($pdo->query('SELECT id, nome_completo FROM alunos ORDER BY id')->fetchAll() as $student) {
            $updateStudent->execute([
                'normalized' => TextNormalizer::comparisonKey((string) $student['nome_completo']),
                'id' => (int) $student['id'],
            ]);
        }

        $updateClass = $pdo->prepare(
            'UPDATE turmas SET nome_normalizado = :normalized WHERE id = :id'
        );

        foreach ($pdo->query('SELECT id, nome_turma FROM turmas ORDER BY id')->fetchAll() as $class) {
            $updateClass->execute([
                'normalized' => TextNormalizer::comparisonKey((string) $class['nome_turma']),
                'id' => (int) $class['id'],
            ]);
        }

        $collision = $pdo->query(
            "SELECT ano_letivo, nome_normalizado, GROUP_CONCAT(id, ',') AS ids
             FROM turmas
             WHERE ano_letivo IS NOT NULL
             GROUP BY nome_normalizado, ano_letivo
             HAVING COUNT(*) > 1
             ORDER BY ano_letivo, MIN(id)
             LIMIT 1"
        )->fetch();

        if (is_array($collision)) {
            throw new RuntimeException(sprintf(
                'A migracao Unicode foi interrompida: as turmas de IDs %s se tornam equivalentes no ano letivo %s. Corrija a colisao manualmente e execute novamente.',
                (string) $collision['ids'],
                (string) $collision['ano_letivo']
            ));
        }

        $pdo->exec('DROP INDEX IF EXISTS ux_turmas_nome_ano');
        $pdo->exec('DROP INDEX IF EXISTS idx_alunos_nome');
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_turmas_nome_normalizado_ano
             ON turmas (nome_normalizado, ano_letivo)
             WHERE ano_letivo IS NOT NULL'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_alunos_nome_normalizado
             ON alunos (nome_normalizado)'
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_require_student_normalized_name
             BEFORE INSERT ON alunos
             WHEN NEW.nome_normalizado IS NULL OR trim(NEW.nome_normalizado) = ''
             BEGIN SELECT RAISE(ABORT, 'student_normalized_name_required'); END"
        );
        $pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS trg_require_class_normalized_name
             BEFORE INSERT ON turmas
             WHEN NEW.nome_normalizado IS NULL OR trim(NEW.nome_normalizado) = ''
             BEGIN SELECT RAISE(ABORT, 'class_normalized_name_required'); END"
        );

        if (self::moduleTwoCounts($pdo) !== $beforeCounts
            || self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id') !== $classIdsBefore
            || self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id') !== $studentIdsBefore
            || self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id') !== $dvaIdsBefore
            || self::studentClassRelationships($pdo) !== $relationshipsBefore
            || self::foreignKeyViolations($pdo) !== []
            || self::integrityCheck($pdo) !== ['ok']) {
            throw new RuntimeException('A migracao Unicode nao preservou integralmente o banco de dados.');
        }
    }

    private static function enforceNormalizedNameSchema(PDO $pdo): void
    {
        foreach (['turmas_v11', 'alunos_v11'] as $temporaryTable) {
            if (self::tableExists($pdo, $temporaryTable)) {
                throw new RuntimeException('A migracao v11 encontrou uma estrutura temporaria inesperada.');
            }
        }

        $beforeCounts = self::moduleTwoCounts($pdo);
        $beforeClassIds = self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id');
        $beforeStudentIds = self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id');
        $beforeDvaIds = self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id');
        $beforeStudentClasses = self::studentClassRelationships($pdo);
        $beforeDvaStudents = self::dvaStudentRelationships($pdo);
        $beforeSequences = self::moduleTwoSequences($pdo);
        $beforeData = self::moduleTwoDataSnapshot($pdo);
        $migrationTimestamp = gmdate('Y-m-d H:i:s');

        $classRows = [];
        $classKeys = [];

        foreach ($beforeData['classes'] as $class) {
            $normalized = TextNormalizer::comparisonKey((string) $class['nome_turma']);

            if ($normalized === '') {
                throw new RuntimeException('A migracao v11 foi interrompida: existe uma turma com nome invalido.');
            }

            if ($class['ano_letivo'] !== null) {
                $collisionKey = (string) $class['ano_letivo'] . "\0" . $normalized;

                if (isset($classKeys[$collisionKey])) {
                    throw new RuntimeException(sprintf(
                        'A migracao Unicode foi interrompida: as turmas de IDs %d,%d se tornam equivalentes no mesmo ano letivo. Corrija a colisao em uma copia homologada e execute novamente.',
                        $classKeys[$collisionKey],
                        (int) $class['id']
                    ));
                }

                $classKeys[$collisionKey] = (int) $class['id'];
            }

            $class['nome_normalizado'] = $normalized;
            $class['criado_em'] ??= $migrationTimestamp;
            $class['atualizado_em'] ??= (string) $class['criado_em'];
            $classRows[] = $class;
        }

        $studentRows = [];

        foreach ($beforeData['students'] as $student) {
            $normalized = TextNormalizer::comparisonKey((string) $student['nome_completo']);

            if ($normalized === '') {
                throw new RuntimeException('A migracao v11 foi interrompida: existe um aluno com nome invalido.');
            }

            $student['nome_normalizado'] = $normalized;
            $student['criado_em'] ??= $migrationTimestamp;
            $student['atualizado_em'] ??= (string) $student['criado_em'];
            $studentRows[] = $student;
        }

        $pdo->exec(
            'CREATE TABLE turmas_v11 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome_turma TEXT NOT NULL,
                nome_normalizado TEXT NOT NULL,
                ano_letivo INTEGER NULL,
                ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'CREATE TABLE alunos_v11 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome_completo TEXT NOT NULL,
                nome_normalizado TEXT NOT NULL,
                data_nascimento TEXT NOT NULL,
                id_turma INTEGER NULL,
                telefone_aluno TEXT NULL,
                telefone_responsavel TEXT NULL,
                ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                inativado_em TEXT NULL,
                inativado_por INTEGER NULL,
                FOREIGN KEY (id_turma) REFERENCES turmas(id) ON DELETE SET NULL,
                FOREIGN KEY (inativado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            )'
        );

        $insertClass = $pdo->prepare(
            'INSERT INTO turmas_v11
                (id, nome_turma, nome_normalizado, ano_letivo, ativo, criado_em, atualizado_em)
             VALUES
                (:id, :name, :normalized_name, :school_year, :active, :created_at, :updated_at)'
        );

        foreach ($classRows as $class) {
            $insertClass->execute([
                'id' => $class['id'],
                'name' => $class['nome_turma'],
                'normalized_name' => $class['nome_normalizado'],
                'school_year' => $class['ano_letivo'],
                'active' => $class['ativo'],
                'created_at' => $class['criado_em'],
                'updated_at' => $class['atualizado_em'],
            ]);
        }

        $insertStudent = $pdo->prepare(
            'INSERT INTO alunos_v11
                (id, nome_completo, nome_normalizado, data_nascimento, id_turma,
                 telefone_aluno, telefone_responsavel, ativo, criado_em, atualizado_em,
                 inativado_em, inativado_por)
             VALUES
                (:id, :name, :normalized_name, :birth_date, :class_id,
                 :student_phone, :guardian_phone, :active, :created_at, :updated_at,
                 :deactivated_at, :deactivated_by)'
        );

        foreach ($studentRows as $student) {
            $insertStudent->execute([
                'id' => $student['id'],
                'name' => $student['nome_completo'],
                'normalized_name' => $student['nome_normalizado'],
                'birth_date' => $student['data_nascimento'],
                'class_id' => $student['id_turma'],
                'student_phone' => $student['telefone_aluno'],
                'guardian_phone' => $student['telefone_responsavel'],
                'active' => $student['ativo'],
                'created_at' => $student['criado_em'],
                'updated_at' => $student['atualizado_em'],
                'deactivated_at' => $student['inativado_em'],
                'deactivated_by' => $student['inativado_por'],
            ]);
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM turmas_v11')->fetchColumn() !== $beforeCounts['classes']
            || (int) $pdo->query('SELECT COUNT(*) FROM alunos_v11')->fetchColumn() !== $beforeCounts['students']) {
            throw new RuntimeException('A migracao v11 falhou na validacao das copias estruturais.');
        }

        // As tabelas novas referenciam os nomes definitivos. Com foreign_keys
        // temporariamente OFF, as tabelas antigas podem ser substituidas sem
        // reescrever a referencia externa de dvas para alunos.
        $pdo->exec('DROP TABLE alunos');
        $pdo->exec('DROP TABLE turmas');
        $pdo->exec('ALTER TABLE turmas_v11 RENAME TO turmas');
        $pdo->exec('ALTER TABLE alunos_v11 RENAME TO alunos');

        self::restoreModuleTwoSequences($pdo, $beforeSequences);
        self::ensureModuleTwoIndexes($pdo);
        self::ensureModuleTwoGuardsAndNotifications($pdo);
        self::ensureNormalizedNameGuards($pdo);

        $afterData = self::moduleTwoDataSnapshot($pdo);

        if (self::moduleTwoCounts($pdo) !== $beforeCounts
            || self::integerColumn($pdo, 'SELECT id FROM turmas ORDER BY id') !== $beforeClassIds
            || self::integerColumn($pdo, 'SELECT id FROM alunos ORDER BY id') !== $beforeStudentIds
            || self::integerColumn($pdo, 'SELECT id FROM dvas ORDER BY id') !== $beforeDvaIds
            || self::studentClassRelationships($pdo) !== $beforeStudentClasses
            || self::dvaStudentRelationships($pdo) !== $beforeDvaStudents
            || self::moduleTwoSequences($pdo) !== $beforeSequences
            || $afterData['classes'] !== self::withoutNormalizedNames($classRows)
            || $afterData['students'] !== self::withoutNormalizedNames($studentRows)
            || $afterData['dvas'] !== $beforeData['dvas']
            || self::foreignKeyViolations($pdo) !== []
            || self::integrityCheck($pdo) !== ['ok']) {
            throw new RuntimeException('A migracao v11 nao preservou integralmente os dados e relacionamentos.');
        }
    }

    private static function ensureModuleTwoIndexes(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_turmas_nome_normalizado_ano
             ON turmas (nome_normalizado, ano_letivo)
             WHERE ano_letivo IS NOT NULL'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_turmas_ativo_ano ON turmas (ativo, ano_letivo DESC, nome_turma)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_nome_normalizado ON alunos (nome_normalizado)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_turma_ativo ON alunos (id_turma, ativo)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alunos_nascimento ON alunos (data_nascimento)');
    }

    private static function ensureNormalizedNameGuards(PDO $pdo): void
    {
        $whitespaceOnly = "trim(NEW.nome_normalizado,
            char(9) || char(10) || char(11) || char(12) || char(13) || char(32) ||
            char(133) || char(160) || char(5760) || char(8192) || char(8193) ||
            char(8194) || char(8195) || char(8196) || char(8197) || char(8198) ||
            char(8199) || char(8200) || char(8201) || char(8202) || char(8232) ||
            char(8233) || char(8239) || char(8287) || char(12288)
        ) = ''";

        foreach ([
            ['trg_require_student_normalized_name_insert', 'INSERT', 'alunos', 'student_normalized_name_required'],
            ['trg_require_student_normalized_name_update', 'UPDATE OF nome_normalizado', 'alunos', 'student_normalized_name_required'],
            ['trg_require_class_normalized_name_insert', 'INSERT', 'turmas', 'class_normalized_name_required'],
            ['trg_require_class_normalized_name_update', 'UPDATE OF nome_normalizado', 'turmas', 'class_normalized_name_required'],
        ] as [$name, $operation, $table, $error]) {
            $pdo->exec(
                "CREATE TRIGGER IF NOT EXISTS {$name}
                 BEFORE {$operation} ON {$table}
                 WHEN NEW.nome_normalizado IS NULL OR {$whitespaceOnly}
                 BEGIN SELECT RAISE(ABORT, '{$error}'); END"
            );
        }
    }

    /** @return array<int,int> */
    private static function dvaStudentRelationships(PDO $pdo): array
    {
        $relationships = [];

        foreach ($pdo->query('SELECT id, id_aluno FROM dvas ORDER BY id')->fetchAll() as $row) {
            $relationships[(int) $row['id']] = (int) $row['id_aluno'];
        }

        return $relationships;
    }

    /** @return array<string,int> */
    private static function moduleTwoSequences(PDO $pdo): array
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

    /** @param array<string,int> $sequences */
    private static function restoreModuleTwoSequences(PDO $pdo, array $sequences): void
    {
        $pdo->exec(
            "DELETE FROM sqlite_sequence
             WHERE name IN ('turmas', 'alunos', 'turmas_v11', 'alunos_v11')"
        );
        $insert = $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :sequence)');

        foreach (['turmas', 'alunos'] as $table) {
            if (array_key_exists($table, $sequences)) {
                $insert->execute(['name' => $table, 'sequence' => $sequences[$table]]);
            }
        }
    }

    /**
     * @return array{
     *   classes:list<array<string,mixed>>,
     *   students:list<array<string,mixed>>,
     *   dvas:list<array<string,mixed>>
     * }
     */
    private static function moduleTwoDataSnapshot(PDO $pdo): array
    {
        return [
            'classes' => $pdo->query(
                'SELECT id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em
                 FROM turmas ORDER BY id'
            )->fetchAll(),
            'students' => $pdo->query(
                'SELECT id, nome_completo, data_nascimento, id_turma, telefone_aluno,
                        telefone_responsavel, ativo, criado_em, atualizado_em, inativado_em, inativado_por
                 FROM alunos ORDER BY id'
            )->fetchAll(),
            'dvas' => $pdo->query(
                'SELECT id, id_aluno, id_usuario_registro, data_vencimento, observacao,
                        ativo, criado_em, atualizado_em, substituido_em
                 FROM dvas ORDER BY id'
            )->fetchAll(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withoutNormalizedNames(array $rows): array
    {
        foreach ($rows as &$row) {
            unset($row['nome_normalizado']);
        }
        unset($row);

        return $rows;
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
