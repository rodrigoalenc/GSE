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

        $_ENV[$chave] = $valor;
        putenv("{$chave}={$valor}");
    }
}

function aplicar_headers_seguranca(bool $isHttps): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function redirect(string $caminho): void
{
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . rtrim($baseUrl, '/') . '/' . ltrim($caminho, '/'), true, 302);
    exit;
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
