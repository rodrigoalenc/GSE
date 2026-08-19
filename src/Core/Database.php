<?php

namespace src\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dbPath = self::resolvePath();
        $directory = dirname($dbPath);
        $createdDirectory = false;

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Não foi possível criar o diretório do banco de dados.');
            }

            $createdDirectory = true;
        }

        if ($createdDirectory || (class_exists('\Config', false) && \Config::isProduction())) {
            self::protectDirectory($directory);
        }

        try {
            self::$connection = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$connection->exec('PRAGMA foreign_keys = ON;');
            self::$connection->exec('PRAGMA journal_mode = WAL;');
            self::$connection->exec('PRAGMA busy_timeout = 5000;');

            self::protectFiles($dbPath);

            return self::$connection;
        } catch (PDOException $exception) {
            throw new RuntimeException('Erro ao conectar ao banco de dados.', 0, $exception);
        }
    }

    public static function setConnection(?PDO $connection): void
    {
        self::$connection = $connection;
    }

    public static function resolvePath(): string
    {
        $dbPath = trim((string) ($_ENV['DB_PATH'] ?? getenv('DB_PATH') ?: ''));

        if ($dbPath === '') {
            throw new RuntimeException('A variável DB_PATH não foi configurada.');
        }

        $dbPath = str_replace('\\', '/', $dbPath);

        if (!preg_match('/\.(sqlite|sqlite3|db)$/i', $dbPath)) {
            throw new RuntimeException('DB_PATH deve apontar para um arquivo SQLite válido.');
        }

        $caminhoAbsoluto = str_starts_with($dbPath, '/')
            || str_starts_with($dbPath, '//')
            || (bool) preg_match('/^[a-zA-Z]:\//', $dbPath);

        if (!$caminhoAbsoluto) {
            $raiz = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            $dbPath = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . ltrim($dbPath, '/');
        }

        $dbPath = self::normalizeAbsolutePath($dbPath);

        if (class_exists('\Config', false) && \Config::isProduction()) {
            $public = self::normalizeAbsolutePath((defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/public');

            if (self::isWithin($dbPath, $public)) {
                throw new RuntimeException('Em produção, DB_PATH deve permanecer fora do diretório public.');
            }
        }

        return $dbPath;
    }

    public static function protectFiles(?string $dbPath = null): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $dbPath ??= self::resolvePath();
        $mode = self::configuredMode('DB_FILE_MODE', 0600, [0600, 0640]);

        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $path) {
            if (is_file($path)) {
                @chmod($path, $mode);
            }
        }
    }

    private static function protectDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        @chmod($directory, self::configuredMode('DB_DIRECTORY_MODE', 0700, [0700, 0750]));
    }

    /** @param list<int> $allowed */
    private static function configuredMode(string $key, int $default, array $allowed): int
    {
        $raw = class_exists('\Config', false) ? \Config::string($key) : '';
        $mode = $raw !== '' && preg_match('/^0?[0-7]{3}$/', $raw) === 1 ? intval($raw, 8) : $default;

        return in_array($mode, $allowed, true) ? $mode : $default;
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[a-z]:/i', $path, $match) === 1) {
            $prefix = strtoupper($match[0]);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return match ($prefix) {
            '/' => '/' . implode('/', $segments),
            '//' => '//' . implode('/', $segments),
            default => $prefix . '/' . implode('/', $segments),
        };
    }

    private static function isWithin(string $path, string $directory): bool
    {
        $path = rtrim(strtolower(str_replace('\\', '/', $path)), '/');
        $directory = rtrim(strtolower(str_replace('\\', '/', $directory)), '/');

        return $path === $directory || str_starts_with($path, $directory . '/');
    }
}
