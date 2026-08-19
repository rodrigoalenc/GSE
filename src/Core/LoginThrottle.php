<?php

declare(strict_types=1);

use src\Core\Database;
use src\Core\SqliteTransaction;

final class LoginThrottle
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /**
     * @return array{
     *     blocked: bool,
     *     failures: int,
     *     retry_after: int,
     *     account_blocked: bool,
     *     ip_blocked: bool,
     *     account_failures: int,
     *     ip_failures: int
     * }
     */
    public function status(string $normalizedAccount, string $ipAddress, ?int $now = null): array
    {
        $now ??= time();
        $states = [];

        foreach ($this->keys($normalizedAccount, $ipAddress) as [$type, $hash]) {
            $statement = $this->connection()->prepare(
                'SELECT failure_count, blocked_until FROM login_attempts WHERE key_type = :type AND key_hash = :hash'
            );
            $statement->execute(['type' => $type, 'hash' => $hash]);
            $states[$type] = $statement->fetch() ?: [];
        }

        $accountFailures = (int) ($states['account']['failure_count'] ?? 0);
        $ipFailures = (int) ($states['ip']['failure_count'] ?? 0);
        $accountRetryAfter = max(
            0,
            self::timestamp((string) ($states['account']['blocked_until'] ?? '')) - $now
        );
        $ipRetryAfter = max(0, self::timestamp((string) ($states['ip']['blocked_until'] ?? '')) - $now);

        return [
            'blocked' => $accountRetryAfter > 0 || $ipRetryAfter > 0,
            'failures' => max($accountFailures, $ipFailures),
            'retry_after' => max($accountRetryAfter, $ipRetryAfter),
            'account_blocked' => $accountRetryAfter > 0,
            'ip_blocked' => $ipRetryAfter > 0,
            'account_failures' => $accountFailures,
            'ip_failures' => $ipFailures,
        ];
    }

    /**
     * @return array{
     *     blocked: bool,
     *     failures: int,
     *     retry_after: int,
     *     account_blocked: bool,
     *     ip_blocked: bool,
     *     account_failures: int,
     *     ip_failures: int,
     *     delay_ms: int
     * }
     */
    public function recordFailure(string $normalizedAccount, string $ipAddress, ?int $now = null): array
    {
        $now ??= time();
        $window = self::windowSeconds();
        $block = Config::int('LOGIN_BLOCK_SECONDS', 900, 60, 86400);
        $pdo = $this->connection();

        SqliteTransaction::immediate($pdo, function (PDO $pdo) use ($normalizedAccount, $ipAddress, $now, $window, $block): void {
            foreach ($this->keys($normalizedAccount, $ipAddress) as [$type, $hash]) {
                $statement = $pdo->prepare(
                    'SELECT failure_count, first_failure_at FROM login_attempts WHERE key_type = :type AND key_hash = :hash'
                );
                $statement->execute(['type' => $type, 'hash' => $hash]);
                $current = $statement->fetch() ?: [];
                $first = self::timestamp((string) ($current['first_failure_at'] ?? ''));
                $count = ($first === 0 || ($now - $first) >= $window) ? 1 : ((int) ($current['failure_count'] ?? 0) + 1);
                $first = $count === 1 ? $now : $first;
                $threshold = self::thresholdFor($type);
                $blockedUntil = $count >= $threshold ? $now + $block : null;

                $upsert = $pdo->prepare(
                    'INSERT INTO login_attempts
                        (key_type, key_hash, failure_count, first_failure_at, last_failure_at, blocked_until)
                     VALUES (:type, :hash, :count, :first, :last, :blocked)
                     ON CONFLICT(key_type, key_hash) DO UPDATE SET
                        failure_count = excluded.failure_count,
                        first_failure_at = excluded.first_failure_at,
                        last_failure_at = excluded.last_failure_at,
                        blocked_until = excluded.blocked_until'
                );
                $upsert->execute([
                    'type' => $type,
                    'hash' => $hash,
                    'count' => $count,
                    'first' => self::date($first),
                    'last' => self::date($now),
                    'blocked' => $blockedUntil === null ? null : self::date($blockedUntil),
                ]);
            }
        });

        $status = $this->status($normalizedAccount, $ipAddress, $now);
        $base = Config::int('LOGIN_DELAY_BASE_MS', 200, 0, 5000);
        $maximum = Config::int('LOGIN_DELAY_MAX_MS', 2000, 0, 10000);
        $exponent = max(0, min(10, $status['account_failures'] - 1));
        $status['delay_ms'] = min($maximum, $base * (2 ** $exponent));

        return $status;
    }

    public function recordSuccess(string $normalizedAccount, string $ipAddress): void
    {
        $statement = $this->connection()->prepare(
            'DELETE FROM login_attempts WHERE key_type = :account_type AND key_hash = :account_hash'
        );
        $keys = $this->keys($normalizedAccount, $ipAddress);
        $statement->execute([
            'account_type' => $keys[0][0],
            'account_hash' => $keys[0][1],
        ]);
    }

    public function cleanup(?int $now = null): int
    {
        $now ??= time();
        $days = Config::int('LOGIN_RETENTION_DAYS', 7, 1, 90);
        $statement = $this->connection()->prepare('DELETE FROM login_attempts WHERE last_failure_at < :cutoff');
        $statement->execute(['cutoff' => self::date($now - ($days * 86400))]);

        return $statement->rowCount();
    }

    public static function delay(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::getConnection();
    }

    /** @return list<array{0: string, 1: string}> */
    private function keys(string $account, string $ip): array
    {
        return [
            ['account', hash('sha256', mb_strtolower(trim($account), 'UTF-8'))],
            ['ip', hash('sha256', trim($ip))],
        ];
    }

    private static function thresholdFor(string $type): int
    {
        if ($type === 'ip') {
            return Config::int('LOGIN_IP_MAX_FAILURES', 40, 10, 1000);
        }

        if (Config::string('LOGIN_ACCOUNT_MAX_FAILURES') !== '') {
            return Config::int('LOGIN_ACCOUNT_MAX_FAILURES', 5, 3, 100);
        }

        return Config::int('LOGIN_MAX_FAILURES', 5, 3, 100);
    }

    private static function windowSeconds(): int
    {
        if (Config::string('LOGIN_THROTTLE_WINDOW_SECONDS') !== '') {
            return Config::int('LOGIN_THROTTLE_WINDOW_SECONDS', 900, 60, 86400);
        }

        return Config::int('LOGIN_WINDOW_SECONDS', 900, 60, 86400);
    }

    private static function date(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function timestamp(string $date): int
    {
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime($date . ' UTC');

        return $timestamp === false ? 0 : $timestamp;
    }
}
