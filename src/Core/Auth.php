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
    private static int $lastRetryAfter = 0;

    public static function attempt(string $email, string $senha): bool
    {
        self::$lastAttemptStatus = self::ATTEMPT_INVALID;
        self::$lastRetryAfter = 0;
        $normalizedEmail = Usuario::normalizarEmail($email);
        $ip = RequestContext::clientIp();
        $throttle = new LoginThrottle();
        $reservation = $throttle->reserve($normalizedEmail, $ip);

        if (!$reservation['admitted']) {
            self::$lastAttemptStatus = self::ATTEMPT_BLOCKED;
            self::$lastRetryAfter = max(1, $reservation['retry_after']);
            return false;
        }
        foreach ($reservation['reached'] as $limit) {
            AuditLogger::record('login.blocked', AuditLogger::BLOCKED, null, null,
                sprintf('Orçamento de autenticação esgotado (%s): %d tentativas em %d segundos.', $limit['scope'], $limit['attempts'], $limit['window']));
        }

        $usuarios = new Usuario();
        $usuario = $usuarios->buscarPorEmail($normalizedEmail);
        $hash = is_array($usuario) ? (string) ($usuario['senha'] ?? self::dummyPasswordHash()) : self::dummyPasswordHash();
        $passwordLengthValid = PasswordPolicy::inputValid($senha)
            && !(preg_match('/^\$2[aby]\$/', $hash) === 1 && strlen($senha) > 72);
        $senhaValida = $passwordLengthValid && PasswordPolicy::verify($senha, $hash);

        if (!$passwordLengthValid) {
            password_verify('invalid-password-placeholder', self::dummyPasswordHash());
        }
        $contaAtiva = is_array($usuario) && (int) ($usuario['ativo'] ?? 0) === 1;

        if (!$senhaValida || !$contaAtiva) {
            $targetId = is_array($usuario) ? (int) $usuario['id'] : null;
            AuditLogger::record('login.invalid', AuditLogger::FAILURE, null, $targetId, 'Falha de autenticação.');

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
            try {
                $usuarios->atualizarSenhaHash((int) $usuario['id'], PasswordPolicy::hash($senha), $hash);
            } catch (PasswordHashException) {
                // Opportunistic upgrade: keep the verified hash and authenticated session.
            }
        }

        return true;
    }

    public static function lastAttemptStatus(): string
    {
        return self::$lastAttemptStatus;
    }

    public static function retryAfter(): int { return self::$lastRetryAfter; }

    private static function dummyPasswordHash(): string
    {
        return PasswordPolicy::argon2idAvailable() ? self::DUMMY_ARGON2ID_HASH : self::DUMMY_DEFAULT_HASH;
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
