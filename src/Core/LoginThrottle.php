<?php

declare(strict_types=1);

use src\Core\Database;
use src\Core\SqliteTransaction;

/** Admission budgets, reserved before hashing. No account-wide denial or worker sleeps. */
final class LoginThrottle
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    /** @return array{blocked:bool,failures:int,retry_after:int,account_blocked:bool,ip_blocked:bool,account_failures:int,ip_failures:int} */
    public function status(string $normalizedAccount, string $ipAddress, ?int $now = null): array
    {
        $now ??= time();
        $states = [];
        foreach ($this->keys($normalizedAccount, $ipAddress) as [$type, $hash, $limit, $window]) {
            $query = $this->connection()->prepare('SELECT failure_count, first_failure_at FROM login_attempts WHERE key_type=? AND key_hash=?');
            $query->execute([$type, $hash]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $expires = $row ? self::timestamp((string) $row['first_failure_at']) + $window : 0;
            $count = $expires > $now ? (int) $row['failure_count'] : 0;
            $states[$type] = ['count' => $count, 'retry' => $count >= $limit ? max(0, $expires - $now) : 0];
        }
        return [
            'blocked' => $states['account']['retry'] > 0 || $states['ip']['retry'] > 0,
            'failures' => max($states['account']['count'], $states['ip']['count']),
            'retry_after' => max($states['account']['retry'], $states['ip']['retry']),
            'account_blocked' => $states['account']['retry'] > 0,
            'ip_blocked' => $states['ip']['retry'] > 0,
            // Historical column/API names: these now count admitted attempts, including successes.
            'account_failures' => $states['account']['count'],
            'ip_failures' => $states['ip']['count'],
        ];
    }

    /**
     * Atomic reservation closes the parallel-request gap. Already rejected requests
     * only read the database: neither their counters nor their deadlines grow.
     * @return array{admitted:bool,retry_after:int,reached:list<array{scope:string,attempts:int,window:int}>}
     */
    public function reserve(string $account, string $ip, ?int $now = null): array
    {
        $now ??= time();
        $status = $this->status($account, $ip, $now);
        if ($status['blocked']) {
            return ['admitted' => false, 'retry_after' => $status['retry_after'], 'reached' => []];
        }
        return SqliteTransaction::immediate($this->connection(), function (PDO $pdo) use ($account, $ip, $now): array {
            $status = $this->status($account, $ip, $now);
            if ($status['blocked']) {
                return ['admitted' => false, 'retry_after' => $status['retry_after'], 'reached' => []];
            }
            $reached = [];
            foreach ($this->keys($account, $ip) as [$type, $hash, $limit, $window]) {
                $select = $pdo->prepare('SELECT failure_count,first_failure_at FROM login_attempts WHERE key_type=? AND key_hash=?');
                $select->execute([$type, $hash]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                $first = $row ? self::timestamp((string) $row['first_failure_at']) : 0;
                $count = $row && $first + $window > $now ? (int) $row['failure_count'] + 1 : 1;
                $first = $count === 1 ? $now : $first;
                $query = $pdo->prepare('INSERT INTO login_attempts(key_type,key_hash,failure_count,first_failure_at,last_failure_at,blocked_until)
                    VALUES(?,?,?,?,?,?) ON CONFLICT(key_type,key_hash) DO UPDATE SET failure_count=excluded.failure_count,
                    first_failure_at=excluded.first_failure_at,last_failure_at=excluded.last_failure_at,blocked_until=excluded.blocked_until');
                $query->execute([$type, $hash, $count, self::date($first), self::date($now), $count >= $limit ? self::date($first + $window) : null]);
                if ($count === $limit) {
                    $reached[] = ['scope' => $type === 'account' ? 'conta e origem' : 'origem', 'attempts' => $count, 'window' => $window];
                }
            }
            return ['admitted' => true, 'retry_after' => 0, 'reached' => $reached];
        }, false); // Anonymous admission must also work with a revoked login cookie.
    }

    /**
     * Compatibility for maintenance/tests; authentication reserves before verifying, never after.
     * @return array<string,mixed>
     */
    public function recordFailure(string $account, string $ip, ?int $now = null): array
    {
        $this->reserve($account, $ip, $now);
        return $this->status($account, $ip, $now) + ['delay_ms' => 0];
    }

    public function recordSuccess(string $account, string $ip): void
    {
        [$type, $hash] = $this->keys($account, $ip)[0];
        $this->connection()->prepare('DELETE FROM login_attempts WHERE key_type=? AND key_hash=?')->execute([$type, $hash]);
    }

    public function cleanup(?int $now = null): int
    {
        $now ??= time();
        $days = Config::int('LOGIN_RETENTION_DAYS', 7, 1, 90);
        $query = $this->connection()->prepare('DELETE FROM login_attempts WHERE last_failure_at < ?');
        $query->execute([self::date($now - $days * 86400)]);
        return $query->rowCount();
    }

    private function connection(): PDO { return $this->pdo ?? Database::getConnection(); }

    /** @return list<array{string,string,int,int}> */
    private function keys(string $account, string $ip): array
    {
        $key = DataProtection::keyring()['index'];
        $account = mb_strtolower(trim($account), 'UTF-8');
        // v2 deliberately ignores old account-wide blocks, including migrated ones.
        return [
            ['account', hash_hmac('sha256', 'login-v2-pair:' . hash('sha256', $account) . ':' . $ip, $key),
                Config::int('LOGIN_PAIR_MAX_ATTEMPTS', 5, 3, 20), Config::int('LOGIN_PAIR_WINDOW_SECONDS', 60, 10, 120)],
            ['ip', hash_hmac('sha256', 'login-v2-origin:' . $ip, $key),
                Config::int('LOGIN_ORIGIN_MAX_ATTEMPTS', 60, 30, 600), Config::int('LOGIN_ORIGIN_WINDOW_SECONDS', 10, 1, 30)],
        ];
    }

    private static function date(int $timestamp): string { return gmdate('Y-m-d H:i:s', $timestamp); }
    private static function timestamp(string $date): int { return $date === '' ? 0 : (strtotime($date . ' UTC') ?: 0); }
}
