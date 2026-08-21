<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Usuario.php';

final class Auth
{
    public const ATTEMPT_SUCCESS = 'success';
    public const ATTEMPT_INVALID = 'invalid';
    public const ATTEMPT_BLOCKED = 'blocked';

    private const DUMMY_DEFAULT_HASH = '$2y$12$yDxE4gqN7yBgWUscbzGRTe71RGrh8scVcZlOUEuvRjfFMf2xHaDDC';
    private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$VUt3Vk5uSmZZTW9Oc3VNcg$Hq5HJNxqjXzWoXzvucAn0Od2suQDlExvVELlnW9ijOE';
    private static string $lastAttemptStatus = self::ATTEMPT_INVALID;

    public static function attempt(string $email, string $senha): bool
    {
        self::$lastAttemptStatus = self::ATTEMPT_INVALID;
        $normalizedEmail = Usuario::normalizarEmail($email);
        $ip = RequestContext::clientIp();
        $throttle = new LoginThrottle();
        $status = $throttle->status($normalizedEmail, $ip);

        if ($status['blocked']) {
            password_verify('invalid-password-placeholder', self::dummyPasswordHash());
            self::$lastAttemptStatus = self::ATTEMPT_BLOCKED;
            LoginThrottle::delay(Config::int('LOGIN_DELAY_MAX_MS', 2000, 0, 10000));
            AuditLogger::record('login.blocked', AuditLogger::BLOCKED, null, null, 'Tentativa recusada pelo limite de autenticação.');

            return false;
        }

        $usuarios = new Usuario();
        $usuario = $usuarios->buscarPorEmail($normalizedEmail);
        $hash = is_array($usuario) ? (string) ($usuario['senha'] ?? self::dummyPasswordHash()) : self::dummyPasswordHash();
        $passwordLengthValid = mb_strlen($senha, 'UTF-8') <= PasswordPolicy::MAX_LENGTH && $senha !== '';
        $senhaValida = $passwordLengthValid ? password_verify($senha, $hash) : false;

        if (!$passwordLengthValid) {
            password_verify('invalid-password-placeholder', self::dummyPasswordHash());
        }
        $contaAtiva = is_array($usuario) && (int) ($usuario['ativo'] ?? 0) === 1;

        if (!$senhaValida || !$contaAtiva) {
            $failure = $throttle->recordFailure($normalizedEmail, $ip);
            LoginThrottle::delay($failure['delay_ms']);
            $targetId = is_array($usuario) ? (int) $usuario['id'] : null;
            AuditLogger::record('login.invalid', AuditLogger::FAILURE, null, $targetId, 'Falha de autenticação.');

            if ($failure['blocked']) {
                self::$lastAttemptStatus = self::ATTEMPT_BLOCKED;
                AuditLogger::record('login.blocked', AuditLogger::BLOCKED, null, $targetId, 'Limite temporário de autenticação aplicado.');
            }

            return false;
        }

        $throttle->recordSuccess($normalizedEmail, $ip);
        SessionManager::authenticate($usuario);
        self::$lastAttemptStatus = self::ATTEMPT_SUCCESS;
        AuditLogger::record(
            'login.success',
            AuditLogger::SUCCESS,
            (int) $usuario['id'],
            (int) $usuario['id'],
            'Autenticação concluída.'
        );

        if (PasswordPolicy::needsRehash((string) $usuario['senha'])) {
            $usuarios->atualizarSenhaHash((int) $usuario['id'], PasswordPolicy::hash($senha));
        }

        return true;
    }

    public static function lastAttemptStatus(): string
    {
        return self::$lastAttemptStatus;
    }

    private static function dummyPasswordHash(): string
    {
        return defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID_HASH : self::DUMMY_DEFAULT_HASH;
    }

    public static function check(): bool
    {
        $id = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id) {
            return false;
        }

        $usuario = (new Usuario())->buscarPorId((int) $id);
        $valid = is_array($usuario)
            && (int) ($usuario['ativo'] ?? 0) === 1
            && (int) ($usuario['session_version'] ?? 1) === (int) ($_SESSION['auth_version'] ?? 0);

        if (!$valid) {
            self::logout('session.invalidated', 'Sessão invalidada por alteração de segurança.');

            return false;
        }

        $_SESSION['usuario_nome'] = (string) $usuario['nome'];
        $_SESSION['usuario_tipo'] = (string) $usuario['tipo'];
        $_SESSION['must_change_password'] = (int) ($usuario['deve_alterar_senha'] ?? 0) === 1;

        return true;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $usuario = (new Usuario())->buscarPorId((int) $_SESSION['usuario_id']);

        return is_array($usuario) ? $usuario : null;
    }

    public static function isAdmin(): bool
    {
        return self::check() && ($_SESSION['usuario_tipo'] ?? '') === Usuario::PERFIL_ADMINISTRADOR;
    }

    public static function mustChangePassword(): bool
    {
        return self::check() && (bool) ($_SESSION['must_change_password'] ?? false);
    }

    public static function logout(string $action = 'logout', string $description = 'Sessão encerrada pelo usuário.'): void
    {
        $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);

        if ($userId) {
            AuditLogger::record($action, AuditLogger::SUCCESS, (int) $userId, (int) $userId, $description);
        }

        SessionManager::terminate();
    }

    public static function expire(string $reason): void
    {
        $description = $reason === 'absolute'
            ? 'Sessão expirada pelo limite absoluto.'
            : 'Sessão expirada por inatividade.';
        self::logout('session.expired', $description);
    }
}
