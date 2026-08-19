<?php

declare(strict_types=1);

namespace src\Core;

use PDO;

final class Maintenance
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{login_attempts: int, security_audit: int} */
    public function run(?int $now = null): array
    {
        $now ??= time();

        return SqliteTransaction::immediate($this->pdo, function () use ($now): array {
            return [
                'login_attempts' => (new \LoginThrottle($this->pdo))->cleanup($now),
                'security_audit' => (new \Auditoria())->cleanup(
                    \Config::int('AUDIT_RETENTION_DAYS', 365, 90, 3650),
                    $now
                ),
            ];
        });
    }
}
