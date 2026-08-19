<?php

declare(strict_types=1);

final class SecurityHeaders
{
    /** @return array<string, string> */
    public static function values(bool $isHttps): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'",
            'X-Request-ID' => RequestContext::requestId(),
        ];

        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    public static function apply(bool $isHttps): void
    {
        foreach (self::values($isHttps) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
