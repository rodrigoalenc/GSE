<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}

require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';

use src\Core\Database;
use src\Core\DatabaseInitializer;

try {
    TechnicalLogger::configure();
    $journalOnly = in_array('--journal-only', $argv, true);
    if ($journalOnly) {
        if (!in_array('--maintenance-confirmed', $argv, true)) {
            throw new RuntimeException('Pare HTTP e tarefas e use --journal-only --maintenance-confirmed.');
        }
        if (!is_file(Database::resolvePath())) {
            throw new RuntimeException('A preparação do modo exige um banco existente; nenhum arquivo foi criado.');
        }
    }
    $pdo = Database::getConnection(true);
    if ($journalOnly) {
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
            throw new RuntimeException('A integridade do banco exige revisão; mantenha a manutenção.');
        }
        echo "Modo de journal preparado; esquema e cadastros não foram migrados.\n";
    } else {
        DatabaseInitializer::initialize($pdo);
        echo "Banco SQLite inicializado com sucesso.\n";
    }
    echo 'Esquema: ' . (int) $pdo->query('PRAGMA user_version')->fetchColumn() . '; modo: ' . strtoupper((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) . ".\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha ao inicializar o banco: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
