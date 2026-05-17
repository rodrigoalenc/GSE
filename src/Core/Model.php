<?php

require_once ROOT_PATH . '/src/Core/Database.php';

use src\Core\Database;

class Model
{
    protected static $pdo;

    public static function getConexao()
    {
        if (!self::$pdo) {
            self::$pdo = Database::getConnection();
        }

        return self::$pdo;
    }

    public function __construct()
    {
        if (!self::$pdo) {
            try {
                self::$pdo = Database::getConnection();
            } catch (Throwable $e) {
                error_log('CRITICO (Model): Erro de conexao com o banco - ' . $e->getMessage());
                self::mostrarErroGenerico();
            }
        }
    }

    public static function setConexao(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        Database::setConnection($pdo);
    }

    private static function mostrarErroGenerico()
    {
        header('HTTP/1.1 500 Internal Server Error');
        echo "<div style='font-family: sans-serif; text-align: center; margin-top: 10%; color: #334155;'>";
        echo '<h1>Servico Indisponivel</h1>';
        echo '<p>Nao foi possivel acessar a base de dados neste momento. Por favor, tente novamente mais tarde.</p>';
        echo '</div>';
        exit;
    }
}
