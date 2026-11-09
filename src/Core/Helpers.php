<?php

require_once __DIR__ . '/UiPresentation.php';

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

/** Small, local line icons shared by the institutional interface. */
function gse_icon(string $name): string
{
    $paths = [
        'layout' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'user-plus' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2m3-13v6m-3-3h6"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m9 13 2 2 4-4"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8m-8 4h6"/>',
        'archive' => '<rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v13h14V7m-10 4h6"/>',
        'certificate' => '<circle cx="12" cy="8" r="5"/><path d="m8 12-2 9 6-3 6 3-2-9"/>',
        'orders' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8m-8 4h8m-8 4h5"/>',
        'box' => '<path d="m12 3 9 5-9 5-9-5Zm-9 5v10l9 5 9-5V8m-9 5v10M7.5 5.5l9 5"/>',
        'chart' => '<path d="M3 3v18h18M7 16v-4m5 4V7m5 9v-7"/>',
        'school' => '<path d="m2 9 10-6 10 6-10 6Zm4 3v6l6 3 6-3v-6m4-3v8"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 10-8 10S4 17 4 12V6Zm-3 9 2 2 4-4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'key' => '<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-4-4 3-3m-6 0 3-3"/>',
        'logout' => '<path d="M9 21H4V3h5m6 5 5 4-5 4m-8-4h13"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 4h2m4 0h2"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13"/><path d="M12 8H8a3 3 0 1 1 3-3Zm0 0h4a3 3 0 1 0-3-3Z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'arrow-right' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
    ];
    return '<svg class="gse-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['file']) . '</svg>';
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_valido(mixed $token): bool
{
    return is_string($token)
        && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1
        && is_string($_SESSION['_csrf_token'] ?? null)
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function list_state_fields(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="_list_state_action" value="apply">';
}

/** @param array<mixed> $values */
function list_state_hidden_fields(array $values, string $prefix = ''): string
{
    $html = '';
    foreach ($values as $name => $value) {
        $field = $prefix === '' ? (string) $name : $prefix . '[' . $name . ']';
        if (is_array($value)) { $html .= list_state_hidden_fields($value, $field); continue; }
        if (is_scalar($value)) { $html .= '<input type="hidden" name="' . e($field) . '" value="' . e((string) $value) . '">'; }
    }
    return $html;
}

function input_string(mixed $value): string
{
    if (!is_string($value) && PHP_SAPI !== 'cli') {
        render_http_error(400, 'Requisição inválida', 'O formato de um campo não é aceito.');
    }
    return is_string($value) && mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") ? $value : '';
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
