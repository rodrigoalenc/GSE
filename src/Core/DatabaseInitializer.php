<?php

declare(strict_types=1);

namespace src\Core;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseInitializer
{
    private const LATEST_VERSION = 4;

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

        $hasMigrations = self::tableExists($pdo, 'schema_migrations');
        $currentVersion = $hasMigrations
            ? (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn()
            : 0;
        $hasUsers = self::tableExists($pdo, 'usuarios');
        $backupPath = null;

        if ($currentVersion >= self::LATEST_VERSION) {
            Database::protectFiles();

            return;
        }

        if ($hasUsers && $currentVersion < self::LATEST_VERSION) {
            $backupPath = self::createValidatedBackup($pdo);
        }

        try {
            SqliteTransaction::immediate($pdo, static function (PDO $pdo) use ($schema): void {
                $pdo->exec($schema);
                self::migrateLegacyUsers($pdo);
                self::ensureCaseInsensitiveEmailUniqueness($pdo);
                self::ensureLastAdminTrigger($pdo);

                $insert = $pdo->prepare(
                    'INSERT OR IGNORE INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );

                for ($version = 1; $version <= self::LATEST_VERSION; $version++) {
                    $insert->execute(['version' => $version, 'applied_at' => gmdate('Y-m-d H:i:s')]);
                }

                $pdo->exec('PRAGMA user_version = ' . self::LATEST_VERSION);
            });
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

    private static function migrateLegacyUsers(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(usuarios)')->fetchAll();
        $columnNames = array_column($columns, 'name');

        $columns = [
            'ativo' => 'INTEGER NOT NULL DEFAULT 1',
            'atualizado_em' => 'TEXT',
            'session_version' => 'INTEGER NOT NULL DEFAULT 1',
            'deve_alterar_senha' => 'INTEGER NOT NULL DEFAULT 0',
            'password_changed_at' => 'TEXT',
        ];

        foreach ($columns as $name => $definition) {
            if (!in_array($name, $columnNames, true)) {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN {$name} {$definition}");
            }
        }

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
                'A migração foi interrompida: existem e-mails duplicados que diferem apenas por maiúsculas e minúsculas.'
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
            throw new RuntimeException('Não foi possível criar o diretório seguro de backup da migração.');
        }

        $name = pathinfo($databasePath, PATHINFO_FILENAME)
            . '-pre-migration-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        $backupPath = $directory . DIRECTORY_SEPARATOR . $name;
        $pdo->exec('VACUUM main INTO ' . $pdo->quote($backupPath));

        if (!is_file($backupPath) || filesize($backupPath) === 0) {
            throw new RuntimeException('O backup preventivo da migração não pôde ser validado.');
        }

        $verification = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ($verification->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('O backup preventivo da migração falhou na verificação de integridade.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($directory, 0700);
            @chmod($backupPath, 0600);
        }

        return $backupPath;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
