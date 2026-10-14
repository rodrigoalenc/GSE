<?php

require_once ROOT_PATH . '/src/Core/Database.php';

use src\Core\Database;

class Model
{
    protected static ?PDO $pdo = null;

    public static function getConexao(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = Database::getConnection();
        }

        return self::$pdo;
    }

    public function __construct()
    {
        if (!self::$pdo) {
            self::$pdo = Database::getConnection();
        }
    }

    public static function setConexao(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        Database::setConnection($pdo);
    }
}
