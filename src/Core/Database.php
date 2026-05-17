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

        $dbPath = self::resolverCaminhoBanco();
        $directory = dirname($dbPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Nao foi possivel criar o diretorio do banco: {$directory}");
        }

        try {
            self::$connection = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$connection->exec('PRAGMA foreign_keys = ON;');
            self::$connection->exec('PRAGMA journal_mode = WAL;');

            return self::$connection;
        } catch (PDOException $exception) {
            throw new RuntimeException('Erro ao conectar ao banco de dados.', 0, $exception);
        }
    }

    public static function setConnection(?PDO $connection): void
    {
        self::$connection = $connection;
    }

    private static function resolverCaminhoBanco(): string
    {
        $dbPath = trim((string) ($_ENV['DB_PATH'] ?? getenv('DB_PATH') ?: ''));

        if ($dbPath === '') {
            throw new RuntimeException('A variavel DB_PATH nao foi configurada.');
        }

        $dbPath = str_replace('\\', '/', $dbPath);

        if (!preg_match('/\.(sqlite|sqlite3|db)$/i', $dbPath)) {
            throw new RuntimeException('DB_PATH deve apontar para um arquivo SQLite valido.');
        }

        return $dbPath;
    }
}
