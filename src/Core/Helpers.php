<?php

function carregar_env(string $arquivo): void
{
    if (!is_readable($arquivo)) {
        return;
    }

    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($linhas as $linha) {
        $linha = trim($linha);

        if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
            continue;
        }

        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        $valor = trim(trim($valor), "\"'");

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $chave)) {
            continue;
        }

        $valorExistente = getenv($chave);

        if ($valorExistente !== false && $valorExistente !== '') {
            $_ENV[$chave] = $valorExistente;
            continue;
        }

        $_ENV[$chave] = $valor;
        putenv("{$chave}={$valor}");
    }
}

function aplicar_headers_seguranca(bool $isHttps): void
{
    SecurityHeaders::apply($isHttps);
}

function redirect(string $caminho): never
{
    $caminho = trim(str_replace(["\r", "\n", "\0"], '', $caminho));

    if ($caminho === '' || str_starts_with($caminho, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $caminho) === 1) {
        $caminho = 'login';
    }

    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . rtrim($baseUrl, '/') . '/' . ltrim($caminho, '/'), true, 302);
    exit;
}

function url(string $caminho = ''): string
{
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';

    return rtrim($baseUrl, '/') . '/' . ltrim($caminho, '/');
}

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_valido(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function csrf_renovar(): string
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

    return $_SESSION['_csrf_token'];
}

function definir_flash(string $tipo, string $mensagem): void
{
    $_SESSION['_flash'] = [
        'tipo' => $tipo,
        'mensagem' => $mensagem,
    ];
}

/** @return array<string,mixed>|null */
function consumir_flash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($flash) ? $flash : null;
}

function nome_perfil(string $perfil): string
{
    return $perfil === 'administrador' ? 'Administrador' : 'Funcionário';
}

function formatar_data_hora(?string $data): string
{
    if (!$data) {
        return '—';
    }

    $timestamp = strtotime($data);

    return $timestamp === false ? '—' : date('d/m/Y H:i', $timestamp);
}

function render_http_error(
    int $statusCode,
    string $heading,
    string $message,
    string $returnPath = 'login'
): never {
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    $requestId = class_exists('RequestContext', false) ? RequestContext::requestId() : '';
    $technicalDetail = null;
    require ROOT_PATH . '/src/Views/errors/standalone.php';
    exit;
}

function env_int(string $key, int $default, int $minimum, int $maximum): int
{
    return Config::int($key, $default, $minimum, $maximum);
}
