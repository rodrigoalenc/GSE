<?php

declare(strict_types=1);

use src\Core\Database;

final class AuditLogger
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const BLOCKED = 'blocked';

    public static function record(
        string $action,
        string $result,
        ?int $actorUserId = null,
        ?int $targetUserId = null,
        string $description = '',
        ?string $resourceType = null,
        ?int $resourceId = null
    ): void {
        try {
            $action = preg_replace('/[^a-z0-9_.-]/i', '_', $action) ?? 'unknown';
            $result = in_array($result, [self::SUCCESS, self::FAILURE, self::BLOCKED], true)
                ? $result
                : self::FAILURE;
            $description = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $description) ?? '';
            $resourceType = $resourceType === null
                ? null
                : (preg_replace('/[^a-z0-9_.-]/i', '_', $resourceType) ?? null);
            $resourceId = $resourceId !== null && $resourceId > 0 ? $resourceId : null;

            $statement = Database::getConnection()->prepare(
                'INSERT INTO security_audit
                    (occurred_at, action, result, actor_user_id, target_user_id, resource_type,
                     resource_id, ip_address, request_id, description)
                 VALUES (:occurred_at, :action, :result, :actor, :target, :resource_type,
                         :resource_id, :ip, :request_id, :description)'
            );
            $statement->execute([
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'action' => mb_substr($action, 0, 80),
                'result' => $result,
                'actor' => $actorUserId,
                'target' => $targetUserId,
                'resource_type' => $resourceType === null ? null : mb_substr($resourceType, 0, 50),
                'resource_id' => $resourceId,
                'ip' => mb_substr(RequestContext::clientIp(), 0, 45),
                'request_id' => RequestContext::requestId(),
                'description' => mb_substr(trim($description), 0, 300),
            ]);
        } catch (Throwable $exception) {
            TechnicalLogger::error('security_audit_write_failed', ['exception' => $exception::class]);
        }
    }
}
