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

    /**
     * Gera uma chave destinada exclusivamente a pesquisas tolerantes a acentos.
     * A semantica de comparisonKey() permanece inalterada para os Modulos 1 e 2.
     */
    public static function searchKey(string $value): string
    {
        $display = self::displayName($value);
        $decomposed = Normalizer::normalize($display, Normalizer::FORM_D);

        if ($decomposed === false) {
            throw new RuntimeException('Nao foi possivel decompor o texto Unicode.');
        }

        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $decomposed);

        if ($withoutMarks === null) {
            throw new RuntimeException('O texto informado nao possui uma codificacao UTF-8 valida.');
        }

        $recomposed = Normalizer::normalize($withoutMarks, Normalizer::FORM_C);

        if ($recomposed === false) {
            throw new RuntimeException('Nao foi possivel recompor o texto Unicode.');
        }

        return mb_strtolower($recomposed, 'UTF-8');
    }
}
