<?php

declare(strict_types=1);

require_once __DIR__ . '/SqliteTransaction.php';
require_once __DIR__ . '/DataProtection.php';

final class ActionLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Muitas tentativas. Aguarde antes de tentar novamente.');
    }
}

final class ActionBudget
{
    public static function consume(PDO $pdo, int $user, string $purpose, bool $confirmation = true): void
    {
        $prefix = $confirmation ? 'CONFIRM' : 'EXPENSIVE';
        $maximum = Config::int($prefix . '_MAX_ATTEMPTS', $confirmation ? 10 : 60, 1, 1000);
        $window = Config::int($prefix . '_WINDOW_SECONDS', 300, 1, 86400);
        $ip = class_exists(RequestContext::class, false) ? RequestContext::clientIp() : (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $key = DataProtection::keyring()['index'];
        $buckets = [hash_hmac('sha256', "$prefix:user:$user:$purpose", $key) => $maximum];
        if ($confirmation) {
            $buckets[hash_hmac('sha256', "$prefix:ip:$ip", $key)] = Config::int('CONFIRM_IP_MAX_ATTEMPTS', 80, 1, 10000);
        }
        $retry = \src\Core\SqliteTransaction::immediate($pdo, static function (PDO $pdo) use ($buckets, $window): int {
            $now = time();
            $pdo->prepare('DELETE FROM action_budgets WHERE expires <= ?')->execute([$now]);
            $select = $pdo->prepare('SELECT attempts, expires FROM action_budgets WHERE bucket = ?');
            $retry = 0;
            foreach ($buckets as $bucket => $limit) {
                $select->execute([$bucket]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if ($row !== false && (int) $row['attempts'] >= $limit) { $retry = max($retry, (int) $row['expires'] - $now); }
            }
            if ($retry > 0) { return $retry; }
            $insert = $pdo->prepare('INSERT INTO action_budgets(bucket,attempts,expires) VALUES(?,1,?)
                ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1');
            foreach ($buckets as $bucket => $limit) { $insert->execute([$bucket, $now + $window]); }
            return 0;
        });
        if ($retry > 0) { throw new ActionLimitExceeded($retry); }
    }
}
