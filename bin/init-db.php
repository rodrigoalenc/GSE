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
    $pdo = Database::getConnection();
    DatabaseInitializer::initialize($pdo);
    echo "Banco SQLite inicializado com sucesso.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha ao inicializar o banco: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
