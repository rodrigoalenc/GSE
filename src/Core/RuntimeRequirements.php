<?php

declare(strict_types=1);

/** Checks the interpreter and its embedded SQLite without opening application data. */
final class RuntimeRequirements
{
    /** @return list<string> */
    public static function issues(string $phpVersion, int $integerBytes, string $sqliteVersion): array
    {
        $errors = [];
        $patchedPhp = false;
        foreach (['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'] as $branch => $minimum) {
            if (str_starts_with($phpVersion, $branch . '.') && version_compare($phpVersion, $minimum, '>=')) {
                $patchedPhp = true;
            }
        }
        if (!$patchedPhp || preg_match('/(?:alpha|beta|RC|dev)/i', $phpVersion) === 1) {
            $errors[] = 'PHP sem a correção de segurança exigida. Use 8.3.36+, 8.4.27+ ou 8.5.12+ na respectiva linha estável.';
        }
        if ($integerBytes < 8) {
            $errors[] = 'O GSE exige PHP de 64 bits para valores financeiros e datas históricas.';
        }
        $patchedSqlite = version_compare($sqliteVersion, '3.51.3', '>=')
            || (version_compare($sqliteVersion, '3.50.7', '>=') && version_compare($sqliteVersion, '3.51.0', '<'))
            || (version_compare($sqliteVersion, '3.44.6', '>=') && version_compare($sqliteVersion, '3.45.0', '<'));
        if (!$patchedSqlite) {
            $errors[] = 'O SQLite carregado pelo PDO precisa da correção WAL: 3.51.3+ ou backport 3.50.7/3.44.6 da mesma linha.';
        }
        return $errors;
    }

    /** @return array{php:string,bits:int,sqlite:string,errors:list<string>} */
    public static function inspect(): array
    {
        $sqlite = '0';
        $missing = [];
        foreach (['curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'sodium', 'xml', 'xmlwriter'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        if (extension_loaded('pdo_sqlite')) {
            try {
                $connection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $sqlite = (string) $connection->query('SELECT sqlite_version()')->fetchColumn();
            } catch (Throwable) {
                $missing[] = 'PDO SQLite funcional';
            }
        }
        $errors = self::issues(PHP_VERSION, PHP_INT_SIZE, $sqlite);
        if ($missing !== []) {
            $errors[] = 'Habilite as extensões: ' . implode(', ', $missing) . '.';
        }
        return ['php' => PHP_VERSION, 'bits' => PHP_INT_SIZE * 8, 'sqlite' => $sqlite, 'errors' => $errors];
    }

    public static function assertSupported(): void
    {
        static $verified = false;
        if ($verified) {
            return;
        }
        if (self::inspect()['errors'] !== []) {
            throw new RuntimeException('runtime.unsupported');
        }
        $verified = true;
    }
}
