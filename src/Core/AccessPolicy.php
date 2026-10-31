<?php

declare(strict_types=1);

/** Conservative defaults until the school approves named, time-limited grants. */
final class AccessPolicy
{
    private const GRANTS = [
        'student.health' => 'ACCESS_HEALTH_STAFF_IDS',
        'student.contacts' => 'ACCESS_CONTACT_STAFF_IDS',
        'student.export' => 'ACCESS_EXPORT_STAFF_IDS',
        'student.manage' => 'ACCESS_MANAGE_STAFF_IDS',
    ];

    public static function allows(string $permission): bool
    {
        if (!isset(self::GRANTS[$permission])) { return false; }
        $user = Auth::user();
        if ($user === null) { return false; }
        if (($user['tipo'] ?? '') === Usuario::PERFIL_ADMINISTRADOR) { return true; }
        if (($user['tipo'] ?? '') !== Usuario::PERFIL_FUNCIONARIO || !self::approvalCurrent()) { return false; }
        $id = (string) $user['id'];
        if (!in_array($id, Config::list(self::GRANTS[$permission]), true)) { return false; }
        // Exports contain DVA; editing a full record also exposes its contacts.
        return match ($permission) {
            'student.export' => in_array($id, Config::list(self::GRANTS['student.health']), true),
            'student.manage' => in_array($id, Config::list(self::GRANTS['student.contacts']), true),
            default => true,
        };
    }

    public static function require(string $permission): void
    {
        if (self::allows($permission)) { return; }
        AuditLogger::record('authorization.data_denied', AuditLogger::BLOCKED,
            (int) ($_SESSION['usuario_id'] ?? 0) ?: null, null,
            'Acesso a dados restritos negado pela política vigente.');
        render_http_error(403, 'Acesso não autorizado', 'Esta função exige autorização específica vigente.', 'dashboard');
    }

    private static function approvalCurrent(): bool
    {
        $reference = Config::string('ACCESS_APPROVAL_REFERENCE');
        $until = Config::string('ACCESS_APPROVAL_VALID_UNTIL');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC'));
        return $reference !== '' && strlen($reference) <= 200 && $date !== false
            && $date->format('Y-m-d') === $until && $until >= gmdate('Y-m-d');
    }
}
