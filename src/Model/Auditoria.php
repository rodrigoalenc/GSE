<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';

final class Auditoria extends Model
{
    /** @return array{items: array, total: int, page: int, pages: int} */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        [$where, $params] = $this->where($filters);
        $count = self::$pdo->prepare("SELECT COUNT(*) FROM security_audit {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $statement = self::$pdo->prepare(
            "SELECT id, occurred_at, action, result, actor_user_id, target_user_id, resource_type, resource_id,
                    ip_address, request_id, description
             FROM security_audit {$where}
             ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function cleanup(int $retentionDays, ?int $now = null): int
    {
        $retentionDays = max(90, min(3650, $retentionDays));
        $now ??= time();
        $statement = self::$pdo->prepare('DELETE FROM security_audit WHERE occurred_at < :cutoff');
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', $now - ($retentionDays * 86400))]);

        return $statement->rowCount();
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        $action = trim((string) ($filters['action'] ?? ''));
        $result = trim((string) ($filters['result'] ?? ''));
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $resourceType = trim((string) ($filters['resource_type'] ?? ''));

        if ($action !== '' && preg_match('/^[a-z0-9_.-]{1,80}$/i', $action) === 1) {
            $conditions[] = 'action = :action';
            $params['action'] = $action;
        }

        if (in_array($result, ['success', 'failure', 'blocked'], true)) {
            $conditions[] = 'result = :result';
            $params['result'] = $result;
        }

        if ($resourceType !== '' && preg_match('/^[a-z0-9_.-]{1,50}$/i', $resourceType) === 1) {
            $conditions[] = 'resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }

        if (self::validDate($from)) {
            $conditions[] = 'occurred_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }

        if (self::validDate($to)) {
            $conditions[] = 'occurred_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
