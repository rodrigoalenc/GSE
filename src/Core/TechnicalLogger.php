<?php

declare(strict_types=1);

final class TechnicalLogger
{
    private static ?string $path = null;

    public static function configure(): void
    {
        $default = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/logs/php_errors.log';
        $path = Config::string('LOG_PATH', $default);
        $path = self::absolutePath($path);
        $unsafeConfiguredPath = Config::isProduction() && self::isWithinPublic($path);

        if ($unsafeConfiguredPath) {
            $path = $default;
        }
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de logs técnicos.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($directory, 0700);
        }

        self::$path = $path;
        ini_set('log_errors', '1');
        ini_set('error_log', $path);

        if ($unsafeConfiguredPath) {
            self::error('unsafe_log_path_rejected');
        }
    }

    public static function error(string $event, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => 'error',
            'event' => self::clean($event, 80),
            'request_id' => class_exists(RequestContext::class, false) ? RequestContext::requestId() : null,
        ];

        foreach ($context as $key => $value) {
            if (!is_string($key) || preg_match('/pass|secret|token|cookie|session|authorization/i', $key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $record[$key] = self::clean((string) $value, 500);
            }
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false && self::$path !== null) {
            error_log($line . PHP_EOL, 3, self::$path);

            if (DIRECTORY_SEPARATOR === '/' && is_file(self::$path)) {
                @chmod(self::$path, 0600);
            }
        }
    }

    public static function path(): ?string
    {
        return self::$path;
    }

    private static function absolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $absolute = str_starts_with($normalized, '/') || str_starts_with($normalized, '//')
            || preg_match('/^[a-z]:\//i', $normalized) === 1;

        return $absolute ? $path : (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/' . $path;
    }

    private static function clean(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
        $value = preg_replace(
            '/\b(password|senha|token|cookie|authorization|secret)\b\s*[:=]\s*[^\s,;]+/iu',
            '$1=[REDACTED]',
            $value
        ) ?? '';

        return mb_substr(trim($value), 0, $limit);
    }

    private static function isWithinPublic(string $path): bool
    {
        $public = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/public';
        $path = rtrim(strtolower(str_replace('\\', '/', $path)), '/');
        $public = rtrim(strtolower(str_replace('\\', '/', $public)), '/');

        return $path === $public || str_starts_with($path, $public . '/');
    }
}
