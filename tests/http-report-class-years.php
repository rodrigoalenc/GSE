<?php

declare(strict_types=1);

// Included by http-smoke.php against its disposable database and authenticated session.
require_once dirname(__DIR__) . '/vendor/autoload.php';
$reportYearDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$reportYearDb->exec('PRAGMA foreign_keys=ON');
$reportYearClasses = [];
$reportYearClassInsert = $reportYearDb->prepare('INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES(?,?,?)');
$reportYearStudentInsert = $reportYearDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma,ativo) VALUES(?,?,?,?,0)');
$reportYearCsvRows = static function (string $body): array {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) { throw new RuntimeException('CSV temporário indisponível.'); }
    fwrite($stream, substr($body, 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) { $rows[] = $row; }
    fclose($stream);
    return $rows;
};
$reportYearPdfText = static function (string $body): string {
    return preg_replace('/\s+/u', ' ', (new \Smalot\PdfParser\Parser())->parseContent($body)->getText()) ?? '';
};

try {
    foreach ([2026, 2027, null] as $reportYear) {
        $reportYearName = $reportYear === null ? 'Legada HTTP' : '=Turma <HTTP>';
        $reportYearClassInsert->execute([$reportYearName, strtolower($reportYearName), $reportYear]);
        $reportYearClass = (int) $reportYearDb->lastInsertId();
        $reportYearClasses[$reportYear ?? 0] = $reportYearClass;
        $reportYearStudentInsert->execute(['Aluno turma HTTP ' . ($reportYear ?? 'legada'), 'aluno turma http ' . ($reportYear ?? 'legada'), '2010-01-01', $reportYearClass]);
    }
    $reportYearStudentInsert->execute(['Aluno sem turma HTTP', 'aluno sem turma http', '2010-01-01', null]);

    foreach ([2026, 2027, 0] as $reportYear) {
        $reportYearClass = $reportYearClasses[$reportYear];
        $reportYearLabel = $reportYear === 0 ? 'Legada HTTP — Ano não informado' : '=Turma <HTTP> — ' . $reportYear;
        $reportYearQuery = http_build_query(['turma' => $reportYearClass, 'ativo' => '0']);
        $reportYearPreview = request('GET', $baseUrl . '/relatorio?' . $reportYearQuery, $cookieAdmin);
        $reportYearEscaped = htmlspecialchars($reportYearLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        checkHttp($reportYearPreview['status'] === 200
            && str_contains($reportYearPreview['body'], 'value="' . $reportYearClass . '" selected>' . $reportYearEscaped . '</option>')
            && str_contains($reportYearPreview['body'], '<td>' . $reportYearEscaped . '</td>'),
            'Relatório distingue turma e ano no seletor e na prévia: ' . $reportYear);

        $reportYearCsv = request('GET', $baseUrl . '/relatorio/csv?' . $reportYearQuery, $cookieAdmin);
        $reportYearRows = $reportYearCsvRows($reportYearCsv['body']);
        $reportYearSafeLabel = ($reportYear === 0 ? '' : "'") . $reportYearLabel;
        checkHttp($reportYearCsv['status'] === 200 && str_starts_with($reportYearCsv['body'], "\xEF\xBB\xBF")
            && str_contains($reportYearCsv['headers']['content-type'] ?? '', 'text/csv')
            && str_contains($reportYearCsv['headers']['content-disposition'] ?? '', 'attachment;')
            && str_contains($reportYearCsv['headers']['cache-control'] ?? '', 'no-store')
            && ($reportYearRows[1][1] ?? '') === $reportYearSafeLabel
            && ($reportYearRows[2][1] ?? '') === '1'
            && ($reportYearRows[5][0] ?? '') === 'Aluno turma HTTP ' . ($reportYear ?: 'legada')
            && ($reportYearRows[5][1] ?? '') === $reportYearSafeLabel
            && count($reportYearRows[4] ?? []) === 6 && count($reportYearRows[5] ?? []) === 6,
            'CSV exporta apenas o ID selecionado, descreve ano e neutraliza fórmulas: ' . $reportYear);

        $reportYearPdf = request('GET', $baseUrl . '/relatorio/pdf?' . $reportYearQuery, $cookieAdmin);
        $reportYearText = $reportYearPdfText($reportYearPdf['body']);
        checkHttp($reportYearPdf['status'] === 200 && str_starts_with($reportYearPdf['body'], '%PDF-')
            && str_contains($reportYearPdf['headers']['content-type'] ?? '', 'application/pdf')
            && str_contains($reportYearPdf['headers']['content-disposition'] ?? '', 'inline;')
            && substr_count($reportYearText, $reportYearLabel) === 2
            && str_contains($reportYearText, 'Aluno turma HTTP ' . ($reportYear ?: 'legada'))
            && ($reportYear === 0 || !str_contains($reportYearText, 'Aluno turma HTTP ' . ($reportYear === 2026 ? 2027 : 2026))),
            'PDF real conserva nome/ano no filtro e na linha sem misturar homônimas: ' . $reportYear);

        foreach (['csv', 'pdf'] as $reportYearFormat) {
            $reportYearAudit = $reportYearDb->prepare('SELECT description FROM security_audit WHERE action=? ORDER BY id DESC LIMIT 1');
            $reportYearAudit->execute(['relatorio.' . $reportYearFormat . '_generated']);
            checkHttp(str_contains((string) $reportYearAudit->fetchColumn(), 'Filtros turma=' . $reportYearClass . ';'),
                'Auditoria técnica mantém ID em ' . $reportYearFormat . ': ' . $reportYear);
        }
    }

    $reportYearAllCsv = request('GET', $baseUrl . '/relatorio/csv?ativo=0', $cookieAdmin);
    $reportYearAllRows = $reportYearCsvRows($reportYearAllCsv['body']);
    $reportYearUnassigned = array_values(array_filter($reportYearAllRows, static fn (array $row): bool => ($row[0] ?? '') === 'Aluno sem turma HTTP'));
    checkHttp($reportYearAllCsv['status'] === 200 && ($reportYearAllRows[1][1] ?? '') === 'Todas'
        && ($reportYearUnassigned[0][1] ?? '') === 'Sem turma', 'CSV sem filtro descreve aluno sem turma');
    $reportYearAllPdf = request('GET', $baseUrl . '/relatorio/pdf?ativo=0', $cookieAdmin);
    $reportYearAllText = $reportYearPdfText($reportYearAllPdf['body']);
    checkHttp($reportYearAllPdf['status'] === 200 && str_contains($reportYearAllText, 'Aluno sem turma HTTP')
        && str_contains($reportYearAllText, 'Sem turma'), 'PDF real descreve aluno sem turma');
} finally {
    $reportYearStudentInsert = null;
    $reportYearClassInsert = null;
    $reportYearAudit = null;
    $reportYearDb = null;
}
