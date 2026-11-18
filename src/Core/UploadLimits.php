<?php

declare(strict_types=1);

final class UploadLimits
{
    public static function fileBytes(int $applicationLimit): int
    {
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $limit = self::configuredBytes($setting);
            if ($limit > 0) { $applicationLimit = min($applicationLimit, $limit); }
        }
        return $applicationLimit;
    }

    public static function formTooLarge(int $contentLength): bool
    {
        $limit = self::configuredBytes('post_max_size');
        return $limit > 0 && $contentLength > $limit;
    }

    public static function label(int $bytes): string
    {
        if ($bytes >= 1048576 && $bytes % 1048576 === 0) { return intdiv($bytes, 1048576) . ' MiB'; }
        if ($bytes >= 1024 && $bytes % 1024 === 0) { return intdiv($bytes, 1024) . ' KiB'; }
        return number_format($bytes, 0, ',', '.') . ' bytes';
    }

    public static function pdfSizeError(int $applicationLimit): string
    {
        return 'O PDF ultrapassa o limite de envio. Selecione um arquivo de até '
            . self::label(self::fileBytes($applicationLimit)) . '.';
    }

    private static function configuredBytes(string $name): int
    {
        $value = ini_get($name);
        return $value === false ? 0 : ini_parse_quantity($value);
    }
}
