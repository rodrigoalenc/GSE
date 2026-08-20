<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}

require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SecurityHeaders.php';
require_once ROOT_PATH . '/src/Core/ErrorHandler.php';
require_once ROOT_PATH . '/src/Core/SessionManager.php';
require_once ROOT_PATH . '/src/Core/PasswordPolicy.php';
require_once ROOT_PATH . '/src/Core/DvaStatus.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/LoginThrottle.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/Controller.php';
require_once ROOT_PATH . '/src/Core/Auth.php';
require_once ROOT_PATH . '/src/Core/Router.php';
require_once ROOT_PATH . '/src/Model/Auditoria.php';

use src\Core\Database;
use src\Core\DatabaseInitializer;

ini_set('display_errors', Config::isDevelopment() ? '1' : '0');
ini_set('display_startup_errors', Config::isDevelopment() ? '1' : '0');
error_reporting(E_ALL);

TechnicalLogger::configure();
ErrorHandler::register();

$productionErrors = Config::productionErrors();

if ($productionErrors !== []) {
    TechnicalLogger::error('invalid_production_configuration', ['reason' => implode(' ', $productionErrors)]);
    ErrorHandler::render500();
}

$isHttps = RequestContext::isHttps();
SecurityHeaders::apply($isHttps);

if (!RequestContext::isHostAllowed()) {
    TechnicalLogger::error('invalid_host_rejected');
    render_http_error(400, 'Requisição inválida', 'O host informado não é permitido.');
}

if (Config::isProduction() && Config::bool('FORCE_HTTPS', true) && !$isHttps) {
    header('Location: ' . RequestContext::baseUrl() . RequestContext::requestTarget(), true, 308);
    exit;
}

define('BASE_URL', RequestContext::baseUrl());

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
    $staticFile = realpath(__DIR__ . $path);
    $publicRoot = realpath(__DIR__);

    if (
        $staticFile !== false
        && $publicRoot !== false
        && ($staticFile === $publicRoot || str_starts_with($staticFile, $publicRoot . DIRECTORY_SEPARATOR))
        && is_file($staticFile)
    ) {
        return false;
    }
}

SessionManager::start($isHttps);

try {
    $pdo = Database::getConnection();
    DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);
} catch (Throwable $exception) {
    throw new RuntimeException('Falha ao inicializar os serviços de persistência.', 0, $exception);
}

$expirationReason = SessionManager::expirationReason();

if ($expirationReason !== null) {
    Auth::expire($expirationReason);
    SessionManager::startFreshForFlash();
    definir_flash('warning', 'Sua sessão expirou. Faça login novamente.');
    redirect('login');
}

if (isset($_SESSION['usuario_id'])) {
    if (Auth::check()) {
        SessionManager::touchAndRenew();
    } else {
        SessionManager::startFreshForFlash();
    }
}

$url = $_GET['url'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
(new Router())->dispatch((string) $url);
