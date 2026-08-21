<?php

declare(strict_types=1);

final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(16));
    }

    public static function sourceIp(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public static function isTrustedProxy(?string $ip = null): bool
    {
        $ip ??= self::sourceIp();

        foreach (Config::list('TRUSTED_PROXIES') as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function clientIp(): string
    {
        $source = self::sourceIp();

        if (!self::isTrustedProxy($source)) {
            return $source;
        }

        $chain = self::forwardedForChain();
        $chain[] = $source;

        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $candidate = $chain[$index];

            if (!self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return $chain[0];
    }

    public static function isHttps(): bool
    {
        if (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        ) {
            return true;
        }

        if (!self::isTrustedProxy()) {
            return false;
        }

        $proto = self::nearestForwardedProto();

        return $proto === 'https';
    }

    public static function requestHost(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/[\x00-\x20\x7f]/', '', $host) ?? '';

        if ($host === '' || preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?$/i', $host) !== 1) {
            return '';
        }

        return $host;
    }

    public static function isHostAllowed(): bool
    {
        if (!Config::isProduction()) {
            return self::requestHost() !== '';
        }

        $requestHost = self::hostWithoutPort(self::requestHost());
        $allowed = array_map([self::class, 'hostWithoutPort'], Config::list('APP_ALLOWED_HOSTS'));
        $appHost = parse_url(Config::appUrl(), PHP_URL_HOST);

        if (is_string($appHost) && $appHost !== '') {
            $allowed[] = strtolower($appHost);
        }

        return $requestHost !== '' && in_array($requestHost, array_unique($allowed), true);
    }

    public static function baseUrl(): string
    {
        if (Config::appUrl() !== '') {
            return Config::appUrl();
        }

        $host = self::requestHost();

        if ($host === '') {
            $host = 'localhost';
        }

        return (self::isHttps() ? 'https' : 'http') . '://' . $host;
    }

    public static function requestTarget(): string
    {
        $target = str_replace(["\r", "\n"], '', (string) ($_SERVER['REQUEST_URI'] ?? '/'));

        return str_starts_with($target, '/') ? $target : '/';
    }

    /** @return list<string> */
    private static function forwardedForChain(): array
    {
        $values = [];
        $header = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        foreach (explode(',', $header) as $part) {
            $candidate = trim($part, " \t\n\r\0\x0B\"");

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $values[] = $candidate;
            }
        }

        if ($values !== []) {
            return $values;
        }

        $forwarded = (string) ($_SERVER['HTTP_FORWARDED'] ?? '');

        foreach (explode(',', $forwarded) as $entry) {
            if (preg_match('/(?:^|;)\s*for=(?:"?\[?)([0-9a-f:.]+)(?:\]?"?)(?=;|$)/i', $entry, $match) === 1
                && filter_var($match[1], FILTER_VALIDATE_IP) !== false) {
                $values[] = $match[1];
            }
        }

        return $values;
    }

    private static function nearestForwardedProto(): ?string
    {
        $header = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        if ($header !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $header))));
            $candidate = end($parts);

            return in_array($candidate, ['http', 'https'], true) ? $candidate : null;
        }

        $entries = array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($_SERVER['HTTP_FORWARDED'] ?? ''))))));
        $nearest = end($entries);

        if (is_string($nearest) && preg_match('/(?:^|;)\s*proto=(https?)(?=;|$)/', $nearest, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private static function hostWithoutPort(string $host): string
    {
        if (str_starts_with($host, '[')) {
            return strtolower(trim(explode(']', $host, 2)[0], '[]'));
        }

        return strtolower(explode(':', $host, 2)[0]);
    }

    private static function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return hash_equals(strtolower($range), strtolower($ip));
        }

        [$network, $prefix] = explode('/', $range, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);

        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $bits = filter_var($prefix, FILTER_VALIDATE_INT);
        $maximum = strlen($ipBinary) * 8;

        if ($bits === false || $bits < 0 || $bits > $maximum) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
