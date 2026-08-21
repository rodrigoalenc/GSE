<?php

declare(strict_types=1);

namespace src\Core;

use Normalizer;
use RuntimeException;

final class TextNormalizer
{
    public static function displayName(string $value): string
    {
        $collapsed = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        if ($collapsed === null) {
            throw new RuntimeException('O texto informado nao possui uma codificacao UTF-8 valida.');
        }

        $collapsed = trim($collapsed);

        $normalized = Normalizer::normalize($collapsed, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new RuntimeException('Nao foi possivel normalizar o texto Unicode.');
        }

        return $normalized;
    }

    public static function comparisonKey(string $value): string
    {
        return mb_strtolower(self::displayName($value), 'UTF-8');
    }
}
