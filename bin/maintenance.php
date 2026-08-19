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
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/LoginThrottle.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/Maintenance.php';
require_once ROOT_PATH . '/src/Model/Auditoria.php';

use src\Core\Database;
use src\Core\DatabaseInitializer;
use src\Core\Maintenance;

try {
    TechnicalLogger::configure();
    $pdo = Database::getConnection();
    DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);
    $result = (new Maintenance($pdo))->run();

    fwrite(
        STDOUT,
        sprintf(
            "Manutenção concluída: %d tentativa(s) de login e %d evento(s) de auditoria removido(s).\n",
            $result['login_attempts'],
            $result['security_audit']
        )
    );
} catch (Throwable $exception) {
    TechnicalLogger::error('maintenance_failed', ['exception' => $exception::class]);
    fwrite(STDERR, "Falha ao executar a manutenção. Consulte o log técnico.\n");
    exit(1);
}
