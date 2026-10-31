<?php

declare(strict_types=1);

namespace src\Core;

use PDO;
require_once dirname(__DIR__) . '/Services/PassivoCsvService.php';

final class Maintenance
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{login_attempts:int,security_audit:int,passive_previews:int} */
    public function run(?int $now = null, bool $cleanupLegacyApproved = false): array
    {
        $now ??= time();

        $csv = new \PassivoCsvService();
        $previews = $csv->cleanupExpiredFiles($now) + $csv->cleanupLegacyExpiredFiles($now, $cleanupLegacyApproved);
        return SqliteTransaction::immediate($this->pdo, function () use ($now, $previews): array {
            return [
                'login_attempts' => (new \LoginThrottle($this->pdo))->cleanup($now),
                'security_audit' => (new \Auditoria())->cleanup(
                    \Config::int('AUDIT_RETENTION_DAYS', 365, 90, 3650),
                    $now
                ),
                'passive_previews' => $previews,
            ];
        });
    }
}
