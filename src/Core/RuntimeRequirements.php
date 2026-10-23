<?php

declare(strict_types=1);

require_once __DIR__ . '/UbuntuPhpBackport.php';
require_once __DIR__ . '/SqliteJournalPolicy.php';

/** Checks the interpreter and its embedded SQLite without opening application data. */
final class RuntimeRequirements
{
    /** @return list<string> */
    public static function issues(string $phpVersion, int $integerBytes, string $sqliteVersion, bool $verifiedUbuntuBackport = false): array
    {
        $errors = [];
        $patchedPhp = false;
        foreach (['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'] as $branch => $minimum) {
            if (str_starts_with($phpVersion, $branch . '.') && version_compare($phpVersion, $minimum, '>=')) {
                $patchedPhp = true;
            }
        }
        $patchedPhp = $patchedPhp || ($phpVersion === '8.3.6' && $verifiedUbuntuBackport);
        if (!$patchedPhp || preg_match('/(?:alpha|beta|RC|dev)/i', $phpVersion) === 1) {
            $errors[] = 'PHP sem comprovação da correção de segurança exigida. Use 8.3.36+, 8.4.27+ ou 8.5.12+ na respectiva linha estável, ou pacote Ubuntu 24.04 corrigido e validado pelo verificador.';
        }
        if ($integerBytes < 8) {
            $errors[] = 'O GSE exige PHP de 64 bits para valores financeiros e datas históricas.';
        }
        if (!SqliteJournalPolicy::supported($sqliteVersion)) {
            $errors[] = 'SQLite incompatível. Use 3.45.1+ ou o backport 3.44.6+ da linha 3.44. Versões sem correção WAL usam obrigatoriamente o modo DELETE.';
        }
        return $errors;
    }

    /** @return array{php:string,bits:int,sqlite:string,errors:list<string>,php_backport:?string,sqlite_wal_safe:bool,sqlite_preferred_journal:string} */
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
        $backport = PHP_OS_FAMILY === 'Linux' ? UbuntuPhpBackport::inspect(PHP_VERSION, PHP_SAPI, PHP_BINARY) : null;
        $errors = self::issues(PHP_VERSION, PHP_INT_SIZE, $sqlite, $backport !== null);
        if ($missing !== []) {
            $errors[] = 'Habilite as extensões: ' . implode(', ', $missing) . '.';
        }
        return ['php' => PHP_VERSION, 'bits' => PHP_INT_SIZE * 8, 'sqlite' => $sqlite, 'errors' => $errors,
            'sqlite_wal_safe' => SqliteJournalPolicy::walSafe($sqlite),
            'sqlite_preferred_journal' => SqliteJournalPolicy::supported($sqlite) ? SqliteJournalPolicy::preferred($sqlite) : 'unsupported',
            'php_backport' => $backport === null ? null : $backport['package'] . ' ' . $backport['version'] . ' (USN-8948-1)'];
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
