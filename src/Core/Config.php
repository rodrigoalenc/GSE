<?php

declare(strict_types=1);

final class Config
{
    private const ENVIRONMENTS = ['development', 'testing', 'production'];

    public static function environment(): string
    {
        $value = strtolower(trim(self::string('APP_ENV', 'production')));

        return in_array($value, self::ENVIRONMENTS, true) ? $value : 'production';
    }

    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    public static function isDevelopment(): bool
    {
        return self::environment() === 'development';
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return $value === false || $value === null ? $default : trim((string) $value);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::string($key);

        if ($value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    public static function int(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var(self::string($key), FILTER_VALIDATE_INT);

        if ($value === false) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $items = array_map('trim', explode(',', self::string($key)));

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    public static function appUrl(): string
    {
        return rtrim(self::string('APP_URL'), '/');
    }

    /** @return list<string> */
    public static function productionErrors(): array
    {
        if (!self::isProduction()) {
            return [];
        }

        $url = self::appUrl();
        $parts = $url === '' ? false : parse_url($url);
        $errors = [];

        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !self::validHost((string) ($parts['host'] ?? ''))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
        ) {
            $errors[] = 'APP_URL deve ser uma URL HTTP(S) absoluta, válida e sem subcaminho em produção.';
        }

        if (self::bool('FORCE_HTTPS', true) && is_array($parts) && ($parts['scheme'] ?? '') !== 'https') {
            $errors[] = 'APP_URL deve usar https quando FORCE_HTTPS estiver habilitado.';
        }

        if (self::bool('MAIL_ENABLED', false)) {
            $encryption = strtolower(self::string('MAIL_ENCRYPTION', 'tls'));

            if (self::string('MAIL_HOST') === ''
                || filter_var(self::string('MAIL_FROM_ADDRESS'), FILTER_VALIDATE_EMAIL) === false
                || !in_array($encryption, ['', 'none', 'tls', 'smtps'], true)
                || (self::string('MAIL_USERNAME') !== '' && self::string('MAIL_PASSWORD') === '')) {
                $errors[] = 'A configuração de e-mail está incompleta ou inválida.';
            }
        }

        return $errors;
    }

    private static function validHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
