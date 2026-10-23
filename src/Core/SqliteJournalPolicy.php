<?php

declare(strict_types=1);

/** Selects durable journaling without enabling WAL on affected SQLite releases. */
final class SqliteJournalPolicy
{
    public static function walSafe(string $version): bool
    {
        return self::validVersion($version) && (
            version_compare($version, '3.51.3', '>=')
            || (version_compare($version, '3.50.7', '>=') && version_compare($version, '3.51.0', '<'))
            || (version_compare($version, '3.44.6', '>=') && version_compare($version, '3.45.0', '<'))
        );
    }

    public static function supported(string $version): bool
    {
        return self::validVersion($version) && (
            version_compare($version, '3.45.1', '>=')
            || (version_compare($version, '3.44.6', '>=') && version_compare($version, '3.45.0', '<'))
        );
    }

    public static function preferred(string $version, string $requested = 'auto'): string
    {
        $requested = strtolower(trim($requested));
        if (!in_array($requested, ['auto', 'delete'], true)) {
            throw new InvalidArgumentException('DB_JOURNAL_MODE aceita apenas auto ou delete.');
        }
        if (!self::supported($version)) {
            throw new RuntimeException('sqlite.unsupported_version');
        }

        return $requested === 'auto' && self::walSafe($version) ? 'WAL' : 'DELETE';
    }

    public static function allowed(string $version, string $mode): bool
    {
        if (!self::supported($version)) {
            return false;
        }
        return match (strtoupper(trim($mode))) {
            'DELETE' => true,
            'WAL' => self::walSafe($version),
            default => false,
        };
    }

    public static function configure(PDO $connection, bool $maintenance, string $requested = 'auto'): string
    {
        if ($connection->inTransaction()) {
            throw new RuntimeException('sqlite.journal_transaction_active');
        }

        $version = (string) $connection->query('SELECT sqlite_version()')->fetchColumn();
        $desired = self::preferred($version, $requested);
        $current = strtoupper((string) $connection->query('PRAGMA main.journal_mode')->fetchColumn());

        if ($current === 'MEMORY') {
            foreach ($connection->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
                if ($database['name'] === 'main' && $database['file'] === '') {
                    return 'MEMORY';
                }
            }
        }

        if (!$maintenance) {
            if (!self::allowed($version, $current) || ($desired === 'DELETE' && $current !== 'DELETE')) {
                throw new RuntimeException('sqlite.journal_maintenance_required: Pare HTTP e tarefas e execute php bin/init-db.php em manutenção.');
            }
            return $current;
        }

        if ($current !== $desired) {
            // $desired is selected exclusively from the literals WAL and DELETE above.
            $actual = strtoupper((string) $connection->query('PRAGMA main.journal_mode = ' . $desired)->fetchColumn());
            if ($actual !== $desired) {
                throw new RuntimeException('sqlite.journal_mode_change_failed');
            }
        }
        if (strtoupper((string) $connection->query('PRAGMA main.journal_mode')->fetchColumn()) !== $desired) {
            throw new RuntimeException('sqlite.journal_mode_verification_failed');
        }

        return $desired;
    }

    private static function validVersion(string $version): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) === 1;
    }
}
