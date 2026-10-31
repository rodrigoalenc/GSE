<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Core/TextNormalizer.php';

use Smalot\PdfParser\Config as PdfConfig;
use src\Core\TextNormalizer;

require_once __DIR__ . '/AlunoPdfWorker.php';
require_once __DIR__ . '/BoundedPdfParser.php';

/**
 * Lê o relatório SGDE pela posição dos textos, independentemente da ordem dos streams.
 * Exige colunas repetidas em cada página. Continuações sem Turma só são aceitas
 * quando o documento inteiro identifica uma única turma explícita.
 *
 * @phpstan-type PdfCell array{x:float,y:float,text:string}
 * @phpstan-type StudentRow array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int}
 * @phpstan-type ParsedReport array{rows:list<StudentRow>,pages:int,total:int,eligible:int,ignored:int,statuses:array<string,int>}
 */
final class AlunoPdfParser
{
    public const MAX_FILE_SIZE = 10_485_760;
    public const MAX_PAGES = 200;
    public const MAX_ROWS = 5_000;
    private const MAX_PAGE_CELLS = 20_000;
    public const MAX_STREAM_BYTES = 8_388_608;
    public const MAX_DECODED_BYTES = 33_554_432;
    public const MAX_OBJECTS = 20_000;
    private const MAX_TOTAL_CELLS = 60_000;
    private const MAX_TEXT_BYTES = 4_194_304;

    /** @return ParsedReport */
    public function parseFile(string $path): array
    {
        $this->validateFile($path);
        /** @var ParsedReport $report */
        $report = (new AlunoPdfWorker())->parse($path);
        return $report;
    }

    private function validateFile(string $path): void
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new DomainException('Não foi possível ler o PDF enviado.');
        }

        $size = filesize($path);
        if ($size === false || $size < 8) {
            throw new DomainException('O PDF está vazio ou incompleto.');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new DomainException('O PDF ultrapassa o limite de 10 MiB.');
        }

        $header = file_get_contents($path, false, null, 0, 8);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($header) || preg_match('/\A%PDF-\d\.\d/', $header) !== 1 || $mime !== 'application/pdf') {
            throw new DomainException('O conteúdo enviado não é um arquivo PDF válido.');
        }
    }

    /** @internal Only the standalone, resource-limited CLI child may parse bytes.
     * @return ParsedReport */
    public function parseFileInWorker(string $path): array
    {
        if (PHP_SAPI !== 'cli' || !defined('GSE_PDF_WORKER')) {
            throw new LogicException('PDF parsing requires the isolated worker.');
        }
        $this->validateFile($path);

        $config = new PdfConfig();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_STREAM_BYTES);
        $config->setMaxNestingDepth(128);

        try {
            $document = (new BoundedPdfParser($config))->parseFile($path);
            // O fallback Flate da biblioteca pode devolver exatamente o limite truncado.
            // Rejeita esse resultado antes de analisar linhas para evitar importações parciais.
            foreach ($document->getObjects() as $object) {
                if (strlen((string) $object->getContent()) >= self::MAX_STREAM_BYTES) {
                    throw new DomainException('O PDF ultrapassa o limite de expansão de conteúdo. Exporte o relatório em arquivos menores.');
                }
            }
            $pages = $document->getPages();
            if (count($pages) > self::MAX_PAGES) {
                throw new DomainException('O PDF ultrapassa o limite de 200 páginas.');
            }

            $positionedPages = [];
            $cellCount = 0;
            $textBytes = 0;
            foreach ($pages as $page) {
                $data = $page->getDataTm();
                $cellCount += count($data);
                if (count($data) > self::MAX_PAGE_CELLS || $cellCount > self::MAX_TOTAL_CELLS) {
                    throw new DomainException('O PDF contém uma página com conteúdo excessivo.');
                }
                $cells = [];
                foreach ($data as $item) {
                    if (!isset($item[0][4], $item[0][5], $item[1]) || !is_string($item[1])
                        || !is_numeric($item[0][4]) || !is_numeric($item[0][5])) {
                        throw new DomainException('Não foi possível reconhecer a posição dos textos do PDF.');
                    }
                    $textBytes += strlen($item[1]);
                    if ($textBytes > self::MAX_TEXT_BYTES) { throw new DomainException('O PDF contém texto em excesso. Exporte arquivos menores.'); }
                    $cells[] = ['x' => (float) $item[0][4], 'y' => (float) $item[0][5], 'text' => $item[1]];
                }
                $positionedPages[] = $cells;
            }

            return $this->parsePages($positionedPages);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $protected = preg_match('/secur|encrypt|password/i', $exception->getMessage()) === 1;
            throw new DomainException($protected
                ? 'O PDF está protegido por senha. Exporte um relatório sem proteção.'
                : 'Não foi possível ler este PDF. Envie o relatório original exportado pelo SGDE.', 0, $exception);
        }
    }

    /**
     * Ponto de teste com o mesmo formato de posições produzido por getDataTm().
     *
     * @param list<list<PdfCell>> $pages
     * @return ParsedReport
     */
    public function parsePages(array $pages): array
    {
        if ($pages === []) {
            throw new DomainException('O PDF não contém páginas legíveis.');
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new DomainException('O PDF ultrapassa o limite de 200 páginas.');
        }

        $rows = [];
        $statuses = [];
        $eligible = 0;
        $hasTitle = false;
        $normalizedPages = [];
        $pageClasses = [];
        $classes = [];
        foreach ($pages as $pageIndex => $page) {
            $cells = $this->normalizeCells($page);
            $normalizedPages[] = $cells;
            $className = $this->className($cells, $pageIndex + 1);
            $pageClasses[] = $className;
            if ($className !== null) {
                $classes[$className] = true;
            }
            foreach ($cells as $cell) {
                if ($this->label($cell['text']) === 'relatorio de estudantes alocados') {
                    $hasTitle = true;
                }
            }
        }
        $soleClass = count($classes) === 1 ? (string) array_key_first($classes) : null;
        foreach ($normalizedPages as $pageIndex => $cells) {
            // Uma continuação só pode herdar a turma se todo o arquivo identifica uma única turma.
            $className = $pageClasses[$pageIndex] ?? $soleClass;
            if ($className === null) {
                $pageNumber = $pageIndex + 1;
                throw new DomainException("Não foi possível identificar uma única turma na página {$pageNumber}.");
            }
            foreach ($this->parsePage($cells, $pageIndex + 1, $className) as $row) {
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw new DomainException('O PDF ultrapassa o limite de 5.000 alunos.');
                }
                $statuses[$row['situacao']] = ($statuses[$row['situacao']] ?? 0) + 1;
                if (TextNormalizer::comparisonKey($row['situacao']) === 'em curso') {
                    $eligible++;
                }
            }
        }

        if (!$hasTitle) {
            throw new DomainException('O PDF não corresponde ao Relatório de Estudantes Alocados do SGDE.');
        }
        if ($rows === []) {
            throw new DomainException('O relatório não contém alunos para analisar.');
        }

        return [
            'rows' => $rows,
            'pages' => count($pages),
            'total' => count($rows),
            'eligible' => $eligible,
            'ignored' => count($rows) - $eligible,
            'statuses' => $statuses,
        ];
    }

    /**
     * @param list<PdfCell> $page
     * @return list<PdfCell>
     */
    private function normalizeCells(array $page): array
    {
        if (count($page) > self::MAX_PAGE_CELLS) {
            throw new DomainException('O PDF contém uma página com conteúdo excessivo.');
        }
        $cells = [];
        foreach ($page as $cell) {
            if (!is_finite($cell['x']) || !is_finite($cell['y'])) {
                throw new DomainException('O PDF contém posições de texto inválidas.');
            }
            try {
                $text = TextNormalizer::displayName($cell['text']);
            } catch (RuntimeException $exception) {
                throw new DomainException('O PDF contém texto com codificação inválida.', 0, $exception);
            }
            if ($text !== '') {
                $cells[] = ['x' => $cell['x'], 'y' => $cell['y'], 'text' => $text];
            }
        }
        if ($cells === []) {
            throw new DomainException('O PDF não contém texto legível. PDFs digitalizados como imagem não são aceitos.');
        }
        usort($cells, static fn (array $a, array $b): int => abs($a['y'] - $b['y']) > 1.5
            ? ($a['y'] < $b['y'] ? 1 : -1) : ($a['x'] <=> $b['x']));

        return $cells;
    }

    /**
     * @param list<PdfCell> $cells
     * @return list<StudentRow>
     */
    private function parsePage(array $cells, int $number, string $className): array
    {
        $headers = $this->tableHeaders($cells, $number);
        $firstHeader = $headers[0];
        $dataTop = $firstHeader['bottom'] - 1.5;
        $leftLimit = ($firstHeader['code_x'] + $firstHeader['name_x']) / 2;
        $body = [];
        $anchors = [];
        foreach ($cells as $cell) {
            if ($cell['y'] >= $dataTop || $this->isFooter($cell['text'])) {
                continue;
            }
            $inHeader = false;
            foreach ($headers as $header) {
                if ($cell['y'] >= $header['bottom'] - 1.5 && $cell['y'] <= $header['top'] + 1.5) {
                    $inHeader = true;
                    break;
                }
            }
            if ($inHeader) {
                continue;
            }
            $body[] = $cell;
            if ($cell['x'] < $leftLimit && preg_match('/^\d{1,20}$/D', $cell['text']) === 1) {
                $anchors[] = $cell;
            }
        }
        if ($anchors === []) {
            throw new DomainException("Não foi possível reconhecer as linhas de alunos na página {$number}.");
        }

        usort($anchors, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);
        $buckets = array_fill(0, count($anchors), []);
        $nameAssignments = $this->wrappedNameAssignments($body, $anchors, $number);
        foreach ($body as $cellIndex => $cell) {
            $index = $nameAssignments[$cellIndex] ?? null;
            if ($index === null) {
                $index = 0;
                while (isset($anchors[$index + 1]) && $cell['y'] < ($anchors[$index]['y'] + $anchors[$index + 1]['y']) / 2) {
                    $index++;
                }
            }
            $buckets[$index][] = $cell;
        }

        $rows = [];
        foreach ($anchors as $index => $anchor) {
            if (isset($anchors[$index + 1]) && abs($anchor['y'] - $anchors[$index + 1]['y']) < 3) {
                throw new DomainException("Há uma linha de aluno ambígua na página {$number}.");
            }
            $rows[] = $this->studentRow($buckets[$index], $anchor, $className, $number);
        }

        return $rows;
    }

    /** @param list<PdfCell> $cells */
    private function className(array $cells, int $page): ?string
    {
        $classes = [];
        foreach ($cells as $cell) {
            if (preg_match('/^Turma\s*:\s*(.*)$/iu', $cell['text'], $match) !== 1) {
                continue;
            }
            $name = $match[1];
            foreach ($cells as $other) {
                if (abs($cell['y'] - $other['y']) <= 2 && $other['x'] > $cell['x'] + 1) {
                    $name .= ' ' . $other['text'];
                }
            }
            $name = TextNormalizer::displayName($name);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || preg_match('/\p{L}/u', $name) !== 1) {
                throw new DomainException("A turma da página {$page} está ausente ou inválida.");
            }
            $classes[$name] = true;
        }
        if ($classes === []) {
            return null;
        }
        if (count($classes) > 1) {
            throw new DomainException("Não foi possível identificar uma única turma na página {$page}.");
        }

        return (string) array_key_first($classes);
    }

    /**
     * Mantém juntas as linhas de um nome, inclusive quando o código se alinha ao topo.
     *
     * @param list<PdfCell> $body
     * @param list<PdfCell> $anchors
     * @return array<int,int>
     */
    private function wrappedNameAssignments(array $body, array $anchors, int $page): array
    {
        $datePositions = [];
        foreach ($body as $cell) {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $cell['text']) === 1) {
                $datePositions[] = $cell['x'];
            }
        }
        if ($datePositions === []) {
            return [];
        }
        $codeX = max(array_column($anchors, 'x'));
        $dateX = min($datePositions);
        $groups = [];
        $previousY = null;
        foreach ($body as $index => $cell) {
            if ($cell['x'] <= $codeX + 1 || $cell['x'] >= $dateX - 1) {
                continue;
            }
            // Neste modelo as linhas internas usam até 12,5 pt; linhas de alunos têm margem adicional.
            if ($previousY === null || $previousY - $cell['y'] > 12.5) {
                $groups[] = [];
            }
            $groups[count($groups) - 1][$index] = $cell;
            $previousY = $cell['y'];
        }
        $assignments = [];
        foreach ($groups as $group) {
            $ys = array_column($group, 'y');
            $matches = [];
            foreach ($anchors as $anchorIndex => $anchor) {
                if ($anchor['y'] <= max($ys) + 2 && $anchor['y'] >= min($ys) - 2) {
                    $matches[] = $anchorIndex;
                }
            }
            foreach ($group as $cellIndex => $cell) {
                if (count($matches) === 1) {
                    $assignments[$cellIndex] = $matches[0];
                    continue;
                }
                $aligned = array_values(array_filter($matches, static fn (int $index): bool => abs($anchors[$index]['y'] - $cell['y']) <= 2));
                if (count($aligned) !== 1) {
                    throw new DomainException("As linhas de um nome estão incompletas ou ambíguas na página {$page}.");
                }
                $assignments[$cellIndex] = $aligned[0];
            }
        }

        return $assignments;
    }

    /**
     * @param list<PdfCell> $cells
     * @return list<array{top:float,bottom:float,code_x:float,name_x:float}>
     */
    private function tableHeaders(array $cells, int $page): array
    {
        $headers = [];
        foreach ($cells as $cell) {
            if ($this->label($cell['text']) !== 'nome do estudante') {
                continue;
            }
            $found = [];
            $top = $cell['y'];
            $bottom = $cell['y'];
            foreach ($cells as $other) {
                if (abs($other['y'] - $cell['y']) > 14) {
                    continue;
                }
                $label = $this->label($other['text']);
                $key = match ($label) {
                    'cod sgde' => 'code',
                    'nome do estudante' => 'name',
                    'dt nasc', 'data de nascimento' => 'birth',
                    'sexo' => 'sex',
                    'situacao' => 'status',
                    'n', 'chamada', 'n chamada', 'no chamada' => 'number',
                    default => null,
                };
                if ($key !== null) {
                    $found[$key] = $other['x'];
                    $top = max($top, $other['y']);
                    $bottom = min($bottom, $other['y']);
                }
            }
            if (count($found) !== 6
                || !($found['code'] < $found['name'] && $found['name'] < $found['birth']
                    && $found['birth'] < $found['sex'] && $found['sex'] < $found['status']
                    && $found['status'] < $found['number'])) {
                throw new DomainException("As colunas do relatório estão ausentes ou fora do padrão na página {$page}.");
            }
            $headers[] = ['top' => $top, 'bottom' => $bottom, 'code_x' => $found['code'], 'name_x' => $found['name']];
        }
        if ($headers === []) {
            throw new DomainException("O cabeçalho dos alunos não foi reconhecido na página {$page}.");
        }
        usort($headers, static fn (array $a, array $b): int => $b['top'] <=> $a['top']);

        return $headers;
    }

    /**
     * @param list<PdfCell> $cells
     * @param PdfCell $anchor
     * @return StudentRow
     */
    private function studentRow(array $cells, array $anchor, string $className, int $page): array
    {
        $births = array_values(array_filter($cells, static fn (array $cell): bool => preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $cell['text']) === 1));
        if (count($births) !== 1) {
            throw new DomainException("Uma linha de aluno está incompleta ou ambígua na página {$page}. Confira a data de nascimento.");
        }
        $birth = $births[0];
        $sexes = array_values(array_filter($cells, static fn (array $cell): bool => $cell['x'] > $birth['x'] && in_array($cell['text'], ['M', 'F'], true)));
        $numbers = array_values(array_filter($cells, static fn (array $cell): bool => $cell['x'] > $birth['x'] && preg_match('/^\d{1,5}$/D', $cell['text']) === 1));
        if (count($sexes) !== 1 || count($numbers) !== 1 || $sexes[0]['x'] >= $numbers[0]['x']) {
            throw new DomainException("Uma linha de aluno está incompleta ou fora do padrão na página {$page}.");
        }

        $names = [];
        $statuses = [];
        foreach ($cells as $cell) {
            if ($cell === $anchor || $cell === $birth || $cell === $sexes[0] || $cell === $numbers[0]) {
                continue;
            }
            if ($cell['x'] > $anchor['x'] + 1 && $cell['x'] < $birth['x'] - 1) {
                $names[] = $cell['text'];
            } elseif ($cell['x'] > $sexes[0]['x'] + 1 && $cell['x'] < $numbers[0]['x'] - 1) {
                $statuses[] = $cell['text'];
            } else {
                throw new DomainException("Uma linha de aluno possui campos não reconhecidos na página {$page}.");
            }
        }
        $name = TextNormalizer::displayName(implode(' ', $names));
        $status = TextNormalizer::displayName(implode(' ', $statuses));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || preg_match('/\p{L}/u', $name) !== 1
            || $status === '' || mb_strlen($status, 'UTF-8') > 60 || preg_match('/\p{L}/u', $status) !== 1) {
            throw new DomainException("Uma linha de aluno possui nome ou situação ausente na página {$page}.");
        }
        [$day, $month, $year] = array_map('intval', explode('/', $birth['text']));
        if (!checkdate($month, $day, $year)) {
            throw new DomainException("Uma linha de aluno possui data de nascimento inválida na página {$page}.");
        }

        return [
            'nome_completo' => $name,
            'data_nascimento' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'turma' => $className,
            'codigo_sgde' => $anchor['text'],
            'situacao' => $status,
            'pagina' => $page,
        ];
    }

    private function label(string $text): string
    {
        $text = str_replace(['º', '°'], '', TextNormalizer::searchKey($text));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    private function isFooter(string $text): bool
    {
        $label = $this->label($text);

        return str_starts_with($label, 'data de impressao') || str_starts_with($label, 'fonte ')
            || preg_match('/^pagina \d+ de \d+$/D', $label) === 1
            || preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}$/D', $text) === 1;
    }
}
