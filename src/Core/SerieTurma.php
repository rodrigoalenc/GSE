<?php

declare(strict_types=1);

namespace src\Core;

require_once __DIR__ . '/TextNormalizer.php';

final class SerieTurma
{
    /** Retorna 1–9 para fundamental e 10–12 para médio; nomes ambíguos exigem identificação manual. */
    public static function identificar(string $nome): ?int
    {
        $nome = TextNormalizer::searchKey($nome);
        $nome = (string) preg_replace('/\bens\.?(?=\s+(?:fundamental|medio)\b)/u', 'ensino', $nome);
        if (preg_match('/\b(eja|e\s*\.\s*j\s*\.\s*a\s*\.?|educacao\s+de\s+jovens\s+e\s+adultos|infantil|maternal|multisseriad\w*|etapa|ciclo|modulo|semestre|periodo|aee)\b/u', $nome) === 1) {
            return null;
        }

        // Aceita as siglas usuais, inclusive E.M. e E.F., sem alterar o cadastro.
        $nome = (string) preg_replace('/\be\s*\.\s*([mf])\s*\./u', 'e$1', $nome);
        // A sigla EM precisa estar isolada; a preposição em "em tempo integral"
        // não identifica a etapa de ensino.
        $medio = preg_match('/\bmedio\b|(?:^|[-–—:\/]\s*)em\b|\bem\b(?=\s*(?:$|[-–—:\/]|[1-9](?:\s|[º°ª]|[a-z]\b|$)|[a-z](?:\s|$)|matutino\b|vespertino\b|noturno\b))/u', $nome) === 1;
        $fundamental = preg_match('/\b(ef|fundamental)\b/u', $nome) === 1;
        if ($medio && $fundamental) {
            return null;
        }
        $ordinais = ['primeir' => 1, 'segund' => 2, 'terceir' => 3, 'quart' => 4,
            'quint' => 5, 'sext' => 6, 'setim' => 7, 'oitav' => 8, 'non' => 9];
        foreach ($ordinais as $palavra => $numero) {
            $nome = (string) preg_replace('/\b' . $palavra . '[oa]\b/u', (string) $numero, $nome);
        }
        $nome = (string) preg_replace('/\b(\d+[º°ª]?)(anos?|series?)\b/u', '$1 $2', $nome);
        $nome = (string) preg_replace('/\b([1-9][º°ª]?)([a-z])\b/u', '$1 $2', $nome);

        // Só o início da identificação da turma pode fornecer a série. Números de sala,
        // anos letivos e turmas com mais de uma série nunca servem como evidência.
        $etapa = '(?:ensino\s+)?(?:medio|fundamental)|em|ef';
        if (preg_match('/^(?:turma\s*[:\-]?\s*)?(?:(?:' . $etapa . ')\s*[-–—:]?\s*)?([1-9])\s*[º°ª]?(?=$|[\s\p{P}])/u', $nome, $match) !== 1
            || preg_match_all('/(?<![\p{L}\d])(?:\d+\s*(?:[º°ª]|(?:anos?|series?)\b)|[1-9](?=$|[\s\p{P}]))/u', $nome) !== 1) {
            return null;
        }
        $serie = (int) $match[1];
        if ($medio) {
            return $serie <= 3 ? $serie + 9 : null;
        }
        $marcador = preg_match('/[º°ª]|\b(ano|serie)\b/u', $nome) === 1;
        return $fundamental || ($serie >= 4 && $marcador) ? $serie : null;
    }
}
