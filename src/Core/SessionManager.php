<?php

declare(strict_types=1);

final class SessionManager
{
    public static function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_name('gse_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function authenticate(array $user, ?int $now = null): void
    {
        $now ??= time();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['usuario_id'] = (int) $user['id'];
        $_SESSION['usuario_nome'] = (string) $user['nome'];
        $_SESSION['usuario_tipo'] = (string) $user['tipo'];
        $_SESSION['auth_version'] = (int) ($user['session_version'] ?? 1);
        $_SESSION['must_change_password'] = (int) ($user['deve_alterar_senha'] ?? 0) === 1;
        $_SESSION['auth_started_at'] = $now;
        $_SESSION['last_activity'] = $now;
        $_SESSION['session_renewed_at'] = $now;
        csrf_renovar();
    }

    public static function expirationReason(?int $now = null): ?string
    {
        if (!isset($_SESSION['usuario_id'])) {
            return null;
        }

        $now ??= time();
        $idle = Config::int('SESSION_IDLE_TIMEOUT', 1800, 60, 86400);
        $absolute = Config::int('SESSION_ABSOLUTE_TIMEOUT', 28800, 300, 604800);
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        $started = (int) ($_SESSION['auth_started_at'] ?? 0);

        if ($lastActivity <= 0 || ($now - $lastActivity) > $idle) {
            return 'idle';
        }

        if ($started <= 0 || ($now - $started) > $absolute) {
            return 'absolute';
        }

        return null;
    }

    public static function touchAndRenew(?int $now = null): bool
    {
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }

        $now ??= time();
        $renewal = Config::int('SESSION_RENEWAL_INTERVAL', 900, 60, 86400);
        $lastRenewal = (int) ($_SESSION['session_renewed_at'] ?? 0);
        $renewed = false;

        if ($lastRenewal <= 0 || ($now - $lastRenewal) >= $renewal) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION['session_renewed_at'] = $now;
            csrf_renovar();
            $renewed = true;
        }

        $_SESSION['last_activity'] = $now;

        return $renewed;
    }

    public static function terminate(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function startFreshForFlash(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
    }
}
