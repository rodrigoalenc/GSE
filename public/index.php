<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
);

aplicar_headers_seguranca($isHttps);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

$appUrl = trim((string) ($_ENV['APP_URL'] ?? ''));
$hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostSeguro = preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/i', $hostHeader) ? $hostHeader : 'localhost';
$scheme = $isHttps ? 'https' : 'http';

define('BASE_URL', $appUrl !== '' ? rtrim($appUrl, '/') : ($scheme . '://' . $hostSeguro));

$ambiente = $_ENV['APP_ENV'] ?? 'production';

if ($ambiente === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');
    error_reporting(E_ALL);
}

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $staticFile = realpath(__DIR__ . $path);

    if ($staticFile !== false && str_starts_with($staticFile, __DIR__) && is_file($staticFile)) {
        return false;
    }
}

require_once ROOT_PATH . '/src/Core/Router.php';
require_once ROOT_PATH . '/src/Core/Model.php';

$dbPath = trim((string) ($_ENV['DB_PATH'] ?? ''));

if ($dbPath === '') {
    error_log('CRITICO: a variavel DB_PATH nao foi definida no arquivo .env.');

    http_response_code(500);
    echo "<div style='font-family: sans-serif; text-align: center; margin-top: 10%; color: #334155;'>";
    echo '<h1>Erro Interno do Servidor</h1>';
    echo '<p>O sistema esta temporariamente indisponivel. Por favor, tente novamente mais tarde.</p>';
    echo '</div>';
    exit;
}

putenv("DB_PATH={$dbPath}");

$url = $_GET['url'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
$url = trim((string) $url, '/');

if ($url === '') {
    $url = 'login';
}

$rotasPublicas = ['login', 'recuperar'];
$prefixoPublico = explode('/', $url)[0] ?? 'login';
$rotaPublica = in_array($prefixoPublico, $rotasPublicas, true);

if (isset($_SESSION['usuario_id']) && !isset($_SESSION['_session_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['_session_regenerated'] = true;
}

if (!isset($_SESSION['usuario_id']) && !$rotaPublica) {
    $_SESSION['mensagem_erro'] = 'Faca login para acessar esta pagina.';
    redirect('login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rotaPublica && !csrf_valido($_POST['_csrf_token'] ?? null)) {
    http_response_code(419);
    echo "<div style='font-family: sans-serif; text-align: center; margin-top: 10%; color: #334155;'>";
    echo '<h1>Sessao expirada</h1>';
    echo '<p>Atualize a pagina e tente enviar o formulario novamente.</p>';
    echo '</div>';
    exit;
}

if (isset($_SESSION['usuario_id']) && !$rotaPublica) {
    $timeoutSegundos = 1800;
    $ultimaAtividade = $_SESSION['last_activity'] ?? time();

    if ((time() - $ultimaAtividade) > $timeoutSegundos) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        session_start();
        $_SESSION['mensagem_erro'] = 'Sua sessao expirou por inatividade. Faca login novamente.';
        redirect('login');
    }

    $_SESSION['last_activity'] = time();
}

$router = new Router();
$router->dispatch($url);
