<?php

declare(strict_types=1);

/** DVA access follows the user's role. Other student permissions use named grants. */
final class AccessPolicy
{
    public const STAFF_PERMISSIONS = [
        'student.manage' => 'Cadastrar e editar alunos',
        'student.export' => 'Emitir relatórios e exportar dados',
    ];

    private const LEGACY_GRANTS = [
        'student.contacts' => 'ACCESS_CONTACT_STAFF_IDS',
        'student.export' => 'ACCESS_EXPORT_STAFF_IDS',
        'student.manage' => 'ACCESS_MANAGE_STAFF_IDS',
    ];

    public static function allows(string $permission): bool
    {
        if (!in_array($permission, ['student.health', 'student.contacts'], true)
            && !isset(self::STAFF_PERMISSIONS[$permission])) { return false; }
        $user = Auth::user();
        if ($user === null) { return false; }
        if (($user['tipo'] ?? '') === Usuario::PERFIL_ADMINISTRADOR) { return true; }
        if (($user['tipo'] ?? '') !== Usuario::PERFIL_FUNCIONARIO) { return false; }
        if ($permission === 'student.health') { return true; }
        if (($user['staff_grants'] ?? null) === null) {
            $grants = self::legacyGrants((string) $user['id']);
        } else {
            $grants = self::staffGrants($user);
            if ($permission === 'student.contacts') { $permission = 'student.manage'; }
        }

        return isset($grants[$permission]) && $grants[$permission]['until'] >= gmdate('Y-m-d');
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,array{reference:string,until:string}>
     */
    public static function staffGrants(array $user): array
    {
        if (($user['tipo'] ?? '') !== Usuario::PERFIL_FUNCIONARIO) { return []; }

        $stored = $user['staff_grants'] ?? null;
        if ($stored === null) {
            return array_intersect_key(self::legacyGrants((string) $user['id']), self::STAFF_PERMISSIONS);
        }

        $grants = [];
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        foreach (self::STAFF_PERMISSIONS as $permission => $label) {
            $grant = is_array($decoded) ? ($decoded[$permission] ?? null) : null;
            if (is_array($grant) && self::validApproval($grant['reference'] ?? null, $grant['until'] ?? null)) {
                $grants[$permission] = ['reference' => $grant['reference'], 'until' => $grant['until']];
            }
        }

        return $grants;
    }

    /** @return array<string,array{reference:string,until:string}> */
    private static function legacyGrants(string $id): array
    {
        $reference = Config::string('ACCESS_APPROVAL_REFERENCE');
        $until = Config::string('ACCESS_APPROVAL_VALID_UNTIL');
        if (!self::validApproval($reference, $until) || strlen($reference) > 200) { return []; }

        $grants = [];
        foreach (self::LEGACY_GRANTS as $permission => $setting) {
            if (in_array($id, Config::list($setting), true)) {
                $grants[$permission] = ['reference' => $reference, 'until' => $until];
            }
        }
        if (!in_array($id, Config::list('ACCESS_HEALTH_STAFF_IDS'), true)) {
            unset($grants['student.export']);
        }
        if (!isset($grants['student.contacts'])) {
            unset($grants['student.manage']);
        }

        return $grants;
    }

    /** @param array<string,mixed> $user */
    public static function grantsRevision(array $user): string
    {
        return hash('sha256', json_encode([
            $user['id'], $user['tipo'], $user['staff_grants'] ?? null, self::staffGrants($user),
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,array{reference:string,until:string}> $grants
     * @param array<string,array{reference:string,until:string}> $previous
     * @return list<string>
     */
    public static function validateGrants(array $grants, array $previous = []): array
    {
        $errors = [];
        foreach ($grants as $permission => $grant) {
            $label = self::STAFF_PERMISSIONS[$permission] ?? null;
            if ($label === null) {
                $errors[] = 'Foi enviada uma permissão inválida.';
            } elseif (!self::validApproval($grant['reference'], $grant['until'])) {
                $errors[] = $label . ' — informe a referência da aprovação e uma data de validade válida.';
            } elseif ($grant['until'] < gmdate('Y-m-d') && ($previous[$permission] ?? null) !== $grant) {
                $errors[] = $label . ' — informe uma validade a partir de hoje.';
            }
        }
        return $errors;
    }

    public static function require(string $permission, ?string $operation = null): void
    {
        if (self::allows($permission)) { return; }
        AuditLogger::record('authorization.data_denied', AuditLogger::BLOCKED,
            (int) ($_SESSION['usuario_id'] ?? 0) ?: null, null,
            $operation === null ? 'Acesso a dados restritos negado pela política vigente.'
                : 'Operação solicitada: ' . $operation . '. Motivo: A permissão exigida está ausente ou fora de vigência.');
        render_http_error(403, 'Acesso não autorizado', 'Esta função exige autorização específica vigente.', 'dashboard');
    }

    private static function validApproval(mixed $reference, mixed $until): bool
    {
        if (!is_string($reference) || trim($reference) === '' || !mb_check_encoding($reference, 'UTF-8')
            || mb_strlen($reference, 'UTF-8') > 200 || !is_string($until)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $until;
    }
}
