<?php

declare(strict_types=1);

namespace src\Core;

require_once __DIR__ . '/TextNormalizer.php';

final class CorrespondenciaTurma
{
    /** Compara a identificação da turma independentemente da série e da etapa.
     * @return array{grupo:?string,turno:?string}
     */
    public static function identificar(string $nome): array
    {
        $nome = TextNormalizer::searchKey($nome);
        $nome = (string) preg_replace('/\bens\.?(?=\s+(?:fundamental|medio)\b)/u', 'ensino', $nome);
        $nome = (string) preg_replace('/\be\s*\.\s*([mf])\s*\./u', 'e$1', $nome);
        $nome = (string) preg_replace('/\b(\d+[º°ª]?)(anos?|series?)\b/u', '$1 $2', $nome);
        $nome = (string) preg_replace('/\b(ensino|medio|fundamental|em|ef|anos?|series?|turma|turno|tempo)\b/u', ' ', $nome);
        $nome = (string) preg_replace('/[º°ª]/u', ' ', $nome);
        $ordinal = 'primeir[oa]|segund[oa]|terceir[oa]|quart[oa]|quint[oa]|sext[oa]|setim[oa]|oitav[oa]|non[oa]';
        $nome = (string) preg_replace('/^[\s\p{P}]*(?:[1-9]|' . $ordinal . ')(?=$|[\s\p{P}]|[a-z]\b)/u', ' ', $nome, 1);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $groups = [];
        $shifts = [];
        foreach ($tokens as $token) {
            $shift = match ($token) {
                'manha', 'matutino', 'matutina' => 'matutino',
                'tarde', 'vespertino', 'vespertina' => 'vespertino',
                'noite', 'noturno', 'noturna' => 'noturno',
                'integral' => 'integral',
                default => null,
            };
            if ($shift !== null) {
                $shifts[$shift] = $shift;
            } else {
                $groups[$token] = $token;
            }
        }
        sort($groups, SORT_STRING);
        sort($shifts, SORT_STRING);
        return ['grupo' => $groups === [] ? null : implode(' ', $groups),
            'turno' => $shifts === [] ? null : implode(' ', $shifts)];
    }
}
