<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}

require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/DvaStatus.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Mail/MailTransport.php';
require_once ROOT_PATH . '/src/Mail/PhpMailerTransport.php';
require_once ROOT_PATH . '/src/Services/DvaNotificationService.php';

use src\Core\Database;
use src\Core\DatabaseInitializer;

try {
    TechnicalLogger::configure();

    if (!Config::bool('MAIL_ENABLED', false)) {
        fwrite(STDOUT, "Notificações por e-mail estão desabilitadas.\n");
        exit(0);
    }

    $pdo = Database::getConnection();
    DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);
    $result = (new DvaNotificationService($pdo, PhpMailerTransport::fromConfig()))->notify();

    fwrite(
        STDOUT,
        sprintf(
            "Notificação concluída: %d aviso(s), %d enviado(s), %d ignorado(s), %d falha(s).\n",
            $result['warnings'],
            $result['sent'],
            $result['skipped'],
            $result['failed']
        )
    );
    exit($result['failed'] > 0 ? 2 : 0);
} catch (Throwable $exception) {
    TechnicalLogger::error('dva_notification_command_failed', ['exception' => $exception::class]);
    fwrite(STDERR, "Falha ao executar as notificações. Consulte o log técnico.\n");
    exit(1);
}
