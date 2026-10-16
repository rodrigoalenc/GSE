<?php

declare(strict_types=1);

// Included by http-smoke.php; all names and PDFs below are synthetic fixtures.
require_once dirname(__DIR__) . '/vendor/autoload.php';

$importDb = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$importDb->exec('PRAGMA foreign_keys=ON');
$importClassName = '1º Ano A - EF - TURMA A';
$importYear = (int) date('Y');
$importCounts = static fn (): array => [
    'alunos' => (int) $importDb->query('SELECT COUNT(*) FROM alunos')->fetchColumn(),
    'turmas' => (int) $importDb->query('SELECT COUNT(*) FROM turmas')->fetchColumn(),
    'dvas' => (int) $importDb->query('SELECT COUNT(*) FROM dvas')->fetchColumn(),
];
$importInitialCounts = $importCounts();
$importMakePdf = static function (string $path, array $rows) use ($tempRoot, $importClassName): void {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('tempDir', $tempRoot);
    $options->set('fontCache', $tempRoot . DIRECTORY_SEPARATOR . 'import-font-cache');
    $html = '<!DOCTYPE html><html lang="pt-BR"><meta charset="UTF-8"><style>'
        . '@page { margin: 24px; } body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; } '
        . 'table { border-collapse: collapse; width: 660px; } '
        . 'th,td { padding: 4px 2px; text-align: left; } '
        . '</style><body><h1 style="font-size: 11pt">RELATÓRIO DE ESTUDANTES ALOCADOS</h1>'
        . '<p>Turma: ' . htmlspecialchars($importClassName, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<table><thead><tr><th style="width:86px">Cod. SGDE</th>'
        . '<th style="width:236px">Nome do Estudante</th><th style="width:76px">Dt. Nasc</th>'
        . '<th style="width:40px">Sexo</th><th style="width:90px">Situação</th>'
        . '<th style="width:108px">Nº Chamada</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setPaper('A4');
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    file_put_contents($path, $pdf->output());
};
$importPdf = $tempRoot . DIRECTORY_SEPARATOR . 'alunos-alocados-ficticios.pdf';
$importMakePdf($importPdf, [
    ['910001', 'ALUNA IMPORTACAO HTTP UM', '10/05/2011', 'F', 'Em curso', '1'],
    ['910002', 'ALUNO IMPORTACAO HTTP DOIS', '20/06/2011', 'M', 'Em curso', '2'],
    ['910003', 'ALUNO TRANSFERIDO HTTP', '30/07/2011', 'M', 'Transferido', '3'],
]);
$importPage = request('GET', $baseUrl . '/aluno/importar', $cookieAdmin);
$importList = request('GET', $baseUrl . '/aluno', $cookieAdmin);
checkHttp($importPage['status'] === 200
    && str_contains($importPage['body'], 'name="arquivo_pdf"')
    && str_contains($importPage['body'], 'name="ano_letivo"')
    && str_contains($importList['body'], '/aluno/importar'),
    'Administrador acessa importação de alunos e informa PDF e ano letivo');
$importCsrf = csrf($importPage['body']);
foreach (['', '/preview', '/confirmar'] as $importOperation) {
    $importMethod = $importOperation === '' ? 'GET' : 'POST';
    $importDenied = request($importMethod, $baseUrl . '/aluno/importar' . $importOperation, $cookieEmployee, [
        '_csrf_token' => csrf(request('GET', $baseUrl . '/aluno', $cookieEmployee)['body']),
        'preview_token' => str_repeat('a', 64),
    ]);
    checkHttp($importDenied['status'] === 403, 'Funcionário não executa importação de alunos: ' . $importMethod . $importOperation);
    $importGuest = request($importMethod, $baseUrl . '/aluno/importar' . $importOperation, $cookieGuest);
    checkHttp($importGuest['status'] === 302
        && str_contains($importGuest['headers']['location'] ?? '', '/login'),
        'Visitante não executa importação de alunos: ' . $importMethod . $importOperation);
}
checkHttp(!str_contains(request('GET', $baseUrl . '/aluno', $cookieEmployee)['body'], '/aluno/importar'),
    'Acesso à importação de alunos fica oculto para funcionário');
foreach (['preview', 'confirmar'] as $importOperation) {
    checkHttp(request('GET', $baseUrl . '/aluno/importar/' . $importOperation, $cookieAdmin)['status'] === 405,
        'GET não executa etapa ' . $importOperation . ' da importação de alunos');
    checkHttp(request('POST', $baseUrl . '/aluno/importar/' . $importOperation, $cookieAdmin, [
        '_csrf_token' => 'invalid', 'preview_token' => str_repeat('a', 64),
    ])['status'] === 419, 'CSRF protege etapa ' . $importOperation . ' da importação de alunos');
}
$importUpload = static fn (string $path, string $year): array => requestMultipart(
    $baseUrl . '/aluno/importar/preview',
    $cookieAdmin,
    ['_csrf_token' => $importCsrf, 'ano_letivo' => $year,
        'arquivo_pdf' => new CURLFile($path, 'application/pdf', basename($path))]
);
$importMissing = request('POST', $baseUrl . '/aluno/importar/preview', $cookieAdmin, [
    '_csrf_token' => $importCsrf, 'ano_letivo' => (string) $importYear,
]);
checkHttp(in_array($importMissing['status'], [302, 422], true) && $importCounts() === $importInitialCounts,
    'Importação sem PDF não altera alunos, turmas nem DVAs');
$importInvalidPdf = $tempRoot . DIRECTORY_SEPARATOR . 'alunos-invalidos.pdf';
file_put_contents($importInvalidPdf, 'Este arquivo não é um PDF.');
$importInvalid = $importUpload($importInvalidPdf, (string) $importYear);
checkHttp(in_array($importInvalid['status'], [302, 422], true) && $importCounts() === $importInitialCounts,
    'Arquivo com extensão PDF e conteúdo inválido é recusado sem gravação');
$importBadYear = $importUpload($importPdf, 'ano-invalido');
checkHttp(in_array($importBadYear['status'], [302, 422], true) && $importCounts() === $importInitialCounts,
    'Ano letivo inválido impede a importação sem gravação');
$importWithoutPreview = request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
    '_csrf_token' => $importCsrf, 'preview_token' => str_repeat('a', 64),
]);
checkHttp(in_array($importWithoutPreview['status'], [302, 409, 422], true) && $importCounts() === $importInitialCounts,
    'Confirmação exige uma prévia válida da sessão');
$importPreviewPage = static function (array $response) use ($baseUrl, $cookieAdmin): array {
    if ($response['status'] === 302) {
        $location = $response['headers']['location'] ?? '';
        $url = str_starts_with($location, 'http') ? $location : $baseUrl . '/' . ltrim($location, '/');
        return request('GET', $url, $cookieAdmin);
    }
    return $response;
};
$importPreview = $importPreviewPage($importUpload($importPdf, (string) $importYear));
checkHttp($importPreview['status'] === 200
    && str_contains($importPreview['body'], 'ALUNA IMPORTACAO HTTP UM')
    && str_contains($importPreview['body'], 'ALUNO IMPORTACAO HTTP DOIS')
    && preg_match('/transferid/iu', $importPreview['body']) === 1,
    'Prévia identifica alunos em curso e informa que transferidos serão ignorados');
checkHttp($importCounts() === $importInitialCounts, 'Prévia do PDF não cria alunos nem turmas');
$importPreviewToken = hiddenValue($importPreview['body'], 'preview_token');
$importConfirmed = request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
    '_csrf_token' => csrf($importPreview['body']), 'preview_token' => $importPreviewToken,
    'ano_letivo' => '1900', 'nome_completo' => 'PAYLOAD ADULTERADO HTTP',
]);
checkHttp($importConfirmed['status'] === 302, 'Confirmação da importação usa redirecionamento');
$importStudents = $importDb->query("SELECT a.nome_completo,a.data_nascimento,a.ativo,t.nome_turma,t.ano_letivo
    FROM alunos a LEFT JOIN turmas t ON t.id=a.id_turma WHERE a.nome_completo LIKE '%IMPORTACAO HTTP%' ORDER BY a.nome_completo")->fetchAll();
checkHttp(count($importStudents) === 2
    && array_column($importStudents, 'data_nascimento') === ['2011-05-10', '2011-06-20']
    && array_column($importStudents, 'ativo') === [1, 1]
    && array_column($importStudents, 'nome_turma') === [$importClassName, $importClassName]
    && array_column($importStudents, 'ano_letivo') === [$importYear, $importYear],
    'Confirmação importa somente os alunos em curso com nascimento e turma do ano escolhido');
checkHttp($importCounts() === [
    'alunos' => $importInitialCounts['alunos'] + 2,
    'turmas' => $importInitialCounts['turmas'] + 1,
    'dvas' => $importInitialCounts['dvas'],
] && (int) $importDb->query("SELECT COUNT(*) FROM alunos WHERE nome_completo IN ('ALUNO TRANSFERIDO HTTP','PAYLOAD ADULTERADO HTTP')")->fetchColumn() === 0,
    'Importação cria uma turma ausente, ignora transferido e não fabrica DVAs');
$importAfterFirst = $importCounts();
$importReplayed = request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
    '_csrf_token' => $importCsrf, 'preview_token' => $importPreviewToken,
]);
checkHttp(in_array($importReplayed['status'], [302, 409, 422], true) && $importCounts() === $importAfterFirst,
    'Token da prévia não executa a mesma importação duas vezes');
$importDuplicate = $importPreviewPage($importUpload($importPdf, (string) $importYear));
checkHttp($importDuplicate['status'] === 200
    && preg_match('/duplicad|cadastrad|existente/iu', $importDuplicate['body']) === 1
    && $importCounts() === $importAfterFirst,
    'Reenvio do mesmo relatório identifica alunos já cadastrados');
if (preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $importDuplicate['body'], $importDuplicateToken) === 1) {
    request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
        '_csrf_token' => csrf($importDuplicate['body']), 'preview_token' => $importDuplicateToken[1],
    ]);
    checkHttp($importCounts() === $importAfterFirst, 'Confirmação de relatório repetido não duplica alunos nem turma');
}
$importBeforeOtherYear = $importDb->query('SELECT * FROM alunos ORDER BY id')->fetchAll();
$importOtherYearDuplicate = $importPreviewPage($importUpload($importPdf, (string) ($importYear + 1)));
if (preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $importOtherYearDuplicate['body'], $importOtherYearToken) === 1) {
    request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
        '_csrf_token' => csrf($importOtherYearDuplicate['body']), 'preview_token' => $importOtherYearToken[1],
    ]);
}
checkHttp($importOtherYearDuplicate['status'] === 200 && $importCounts() === $importAfterFirst
    && $importDb->query('SELECT * FROM alunos ORDER BY id')->fetchAll() === $importBeforeOtherYear,
    'Reenvio para outro ano mantém alunos existentes e não cria turma sem alunos novos');
$importMakePdf($importPdf, [['910004', 'ALUNA IMPORTACAO HTTP TRES', '15/08/2011', 'F', 'Em curso', '4']]);
$importExistingClassPreview = $importPreviewPage($importUpload($importPdf, (string) $importYear));
request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
    '_csrf_token' => csrf($importExistingClassPreview['body']),
    'preview_token' => hiddenValue($importExistingClassPreview['body'], 'preview_token'),
]);
checkHttp($importCounts() === [
    'alunos' => $importInitialCounts['alunos'] + 3,
    'turmas' => $importInitialCounts['turmas'] + 1,
    'dvas' => $importInitialCounts['dvas'],
], 'Nova importação reutiliza turma existente do mesmo ano letivo');
$importMakePdf($importPdf, [['910005', 'ALUNA IMPORTACAO HTTP OUTRO ANO', '25/09/2011', 'F', 'Em curso', '1']]);
$importNextYearPreview = $importPreviewPage($importUpload($importPdf, (string) ($importYear + 1)));
request('POST', $baseUrl . '/aluno/importar/confirmar', $cookieAdmin, [
    '_csrf_token' => csrf($importNextYearPreview['body']),
    'preview_token' => hiddenValue($importNextYearPreview['body'], 'preview_token'),
]);
$importYearsStatement = $importDb->prepare('SELECT ano_letivo FROM turmas WHERE nome_turma=? ORDER BY ano_letivo');
$importYearsStatement->execute([$importClassName]);
checkHttp($importYearsStatement->fetchAll(PDO::FETCH_COLUMN) === [$importYear, $importYear + 1]
    && $importCounts()['alunos'] === $importInitialCounts['alunos'] + 4,
    'Turmas de mesmo nome em anos letivos diferentes são criadas separadamente');
unset($importCounts, $importYearsStatement, $importMakePdf, $importUpload, $importPreviewPage);
$importDb = null;
