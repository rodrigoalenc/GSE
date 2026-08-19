<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$_ENV['APP_ENV'] = 'testing';
$_ENV['LOGIN_DELAY_BASE_MS'] = '0';
$_ENV['LOGIN_DELAY_MAX_MS'] = '0';
$_ENV['LOGIN_ACCOUNT_MAX_FAILURES'] = '5';
$_ENV['LOGIN_IP_MAX_FAILURES'] = '40';
$_ENV['LOGIN_THROTTLE_WINDOW_SECONDS'] = '900';
putenv('APP_ENV=testing');
putenv('LOGIN_DELAY_BASE_MS=0');
putenv('LOGIN_DELAY_MAX_MS=0');
putenv('LOGIN_ACCOUNT_MAX_FAILURES=5');
putenv('LOGIN_IP_MAX_FAILURES=40');
putenv('LOGIN_THROTTLE_WINDOW_SECONDS=900');

require_once ROOT_PATH . '/src/Core/Helpers.php';
require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SecurityHeaders.php';
require_once ROOT_PATH . '/src/Core/SessionManager.php';
require_once ROOT_PATH . '/src/Core/PasswordPolicy.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/LoginThrottle.php';
require_once ROOT_PATH . '/src/Core/Maintenance.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/Controller.php';
require_once ROOT_PATH . '/src/Core/Router.php';
require_once ROOT_PATH . '/src/Model/Usuario.php';
require_once ROOT_PATH . '/src/Core/Auth.php';
require_once ROOT_PATH . '/src/Model/Auditoria.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Model/Painel.php';
require_once ROOT_PATH . '/src/Model/Pedido.php';
require_once ROOT_PATH . '/src/Model/Certidao.php';
require_once ROOT_PATH . '/src/Model/Passivo.php';
require_once ROOT_PATH . '/src/Model/Relatorio.php';
require_once ROOT_PATH . '/src/Model/Sistema.php';
require_once ROOT_PATH . '/tests/Support/DatabaseTestCase.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

if (!isset($_SESSION)) {
    $_SESSION = [];
}
