<?php

declare(strict_types=1);

namespace Tests\Support;

/** Gera somente pessoas fictícias em um PDF SGDE sem depender de arquivos reais. */
final class AlunoPdfFixture
{
    /** @param list<array{codigo_sgde:string,nome_completo:string,data_nascimento:string,situacao:string}> $rows */
    public static function write(string $path, array $rows = [], string $turma = '1º Ano A - EF - TURMA A'): string
    {
        if ($rows === []) {
            $rows = [
                ['codigo_sgde' => '9000001', 'nome_completo' => 'ALUNA FICTÍCIA ALFA', 'data_nascimento' => '10/03/2019', 'situacao' => 'Em curso'],
                ['codigo_sgde' => '9000002', 'nome_completo' => 'ALUNO FICTÍCIO BETA', 'data_nascimento' => '11/04/2019', 'situacao' => 'Em curso'],
                ['codigo_sgde' => '9000003', 'nome_completo' => 'ALUNA FICTÍCIA GAMA', 'data_nascimento' => '12/05/2019', 'situacao' => 'Transferido'],
            ];
        }

        return self::writePages($path, [['turma' => $turma, 'rows' => $rows]]);
    }

    /** @param list<array{turma:string,rows:list<array{codigo_sgde:string,nome_completo:string,data_nascimento:string,situacao:string}>}> $pages */
    public static function writePages(string $path, array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageId = 4 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = "{$pageId} 0 R";
            $content = self::text(99, 752, 'RELATÓRIO DE ESTUDANTES ALOCADOS');
            foreach ([[38, 'Cod. SGDE'], [183, 'Nome do Estudante'], [366, 'Dt. Nasc'], [418, 'Sexo'], [460, 'Situação'], [518, 'Nº Chamada']] as [$x, $label]) {
                $content .= self::text($x, 641, $label);
            }
            foreach ($page['rows'] as $rowIndex => $row) {
                $y = 623 - $rowIndex * 18;
                foreach ([[44, $row['codigo_sgde']], [94, $row['nome_completo']], [362, $row['data_nascimento']], [425, 'F'], [460, $row['situacao']], [534, (string) ($rowIndex + 1)]] as [$x, $value]) {
                    $content .= self::text($x, $y, $value);
                }
            }
            // O exportador real grava Turma depois da tabela no stream.
            $content .= self::text(30, 711, 'Turma: ') . self::text(63, 711, $page['turma']);
            $content .= self::text(30, 692, 'Turno: Matutino');
            $content .= self::text(32, 40, 'Data de Impressão: ') . self::text(104, 40, '15/10/2026 20:18');
            $content .= self::text(287, 40, 'Página ' . ($index + 1) . ' de ' . count($pages));
            $content .= self::text(493, 40, 'Fonte: SGDE');
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.276 841.89] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pages) . ' /Kids [' . implode(' ', $kids) . '] >>';

        return self::writeObjects($path, $objects);
    }

    public static function writeCompressedContent(string $path, string $content): string
    {
        $compressed = gzcompress($content);
        if ($compressed === false) {
            throw new \RuntimeException('Não foi possível comprimir o PDF fictício.');
        }

        return self::writeObjects($path, [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [4 0 R] >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.276 841.89] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            5 => '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream",
        ]);
    }

    /** Several individually admissible streams must still share one decode budget. */
    public static function writeCompressedStreams(string $path, int $count, int $bytes): string
    {
        $compressed = gzcompress(str_repeat(' ', $bytes));
        if ($compressed === false) { throw new \RuntimeException('PDF fixture compression failed.'); }
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents [4 0 R] >>'];
        for ($index = 0; $index < $count; $index++) {
            $objects[4 + $index] = '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
        }
        return self::writeObjects($path, $objects);
    }

    /** @param array<int,string> $objects */
    private static function writeObjects(string $path, array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . count($offsets) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $pdf .= 'trailer << /Size ' . count($offsets) . ' /Root 1 0 R >>' . "\nstartxref\n{$xrefOffset}\n%%EOF\n";
        if (file_put_contents($path, $pdf) === false) {
            throw new \RuntimeException('Não foi possível criar o PDF fictício.');
        }

        return $path;
    }

    private static function text(int $x, int $y, string $text): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        return "BT /F1 8 Tf 1 0 0 1 {$x} {$y} Tm ({$escaped}) Tj ET\n";
    }
}
