<?php

declare(strict_types=1);

// Included by http-smoke.php; all writes target its disposable database.
require_once dirname(__DIR__) . '/src/Core/TextNormalizer.php';
$renumberDb = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$renumberDb->exec('PRAGMA foreign_keys=ON');
$renumberInsert = $renumberDb->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,caixa,caixa_normalizada,numero,numero_normalizado,ativo) VALUES(?,?,?,?,?,?,?)');
$renumberIds = [];
foreach ([
    ['ZETA TESTE', '1', 'CX-RENUMERAR', 1],
    ['ÉRICA TESTE', '2', 'CX-RENUMERAR', 1],
    ['ana teste', '7', 'CX-RENUMERAR', 1],
    ['ÁLVARO TESTE', '9', 'CX-RENUMERAR', 1],
    ['INATIVO TESTE', '3', 'CX-RENUMERAR', 0],
    ['OUTRA CAIXA TESTE', '1', 'CX-OUTRA-RENUMERAR', 1],
] as [$name, $number, $box, $active]) {
    $renumberInsert->execute([$name, src\Core\TextNormalizer::searchKey($name), $box,
        src\Core\TextNormalizer::searchKey($box), $number, $number, $active]);
    $renumberIds[$name] = (int) $renumberDb->lastInsertId();
}
$renumberBefore = $renumberDb->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll();
$renumberTools = request('GET', $baseUrl . '/passivo/ferramentas', $cookieAdmin);
checkHttp(str_contains($renumberTools['body'], 'Ordenar e renumerar a caixa'), 'Ferramenta explica renumeração de todos os registros ativos');
$renumberResponse = request('POST', $baseUrl . '/passivo/ferramentas/enumerar/preview', $cookieAdmin, [
    '_csrf_token' => csrf($renumberTools['body']), 'caixa' => 'CX-RENUMERAR',
]);
$renumberLocation = $renumberResponse['headers']['location'] ?? '';
checkHttp($renumberResponse['status'] === 302 && str_contains($renumberLocation, 'preview='), 'Caixa totalmente numerada também gera prévia');
$renumberUrl = str_starts_with($renumberLocation, 'http') ? $renumberLocation : $baseUrl . '/' . ltrim($renumberLocation, '/');
$renumberPage = request('GET', $renumberUrl, $cookieAdmin);
checkHttp($renumberPage['status'] === 200 && str_contains($renumberPage['body'], 'Número atual')
    && str_contains($renumberPage['body'], 'Novo número') && str_contains($renumberPage['body'], 'Confirmar renumeração'),
    'Prévia mostra números atuais e novos antes de confirmar');
checkHttp(strpos($renumberPage['body'], 'ÁLVARO TESTE') < strpos($renumberPage['body'], 'ana teste')
    && strpos($renumberPage['body'], 'ana teste') < strpos($renumberPage['body'], 'ÉRICA TESTE')
    && strpos($renumberPage['body'], 'ÉRICA TESTE') < strpos($renumberPage['body'], 'ZETA TESTE'),
    'Prévia segue ordem alfabética sem diferenciar acentos ou maiúsculas');
checkHttp($renumberBefore === $renumberDb->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll(), 'Prévia de renumeração não altera o banco');
$renumberToken = hiddenValue($renumberPage['body'], 'preview_token');
$renumberConfirmed = request('POST', $baseUrl . '/passivo/ferramentas/enumerar/confirmar', $cookieAdmin, [
    '_csrf_token' => csrf($renumberPage['body']), 'preview_token' => $renumberToken,
]);
checkHttp($renumberConfirmed['status'] === 302 && str_contains($renumberConfirmed['headers']['location'] ?? '', 'ordem=numero'),
    'Confirmação abre caixa ordenada pela nova numeração');
$renumberRows = $renumberDb->query("SELECT nome_completo,numero FROM alunos_passivo WHERE caixa_normalizada='cx-renumerar' AND ativo=1 ORDER BY CAST(numero AS INTEGER)")->fetchAll();
checkHttp(array_column($renumberRows, 'nome_completo') === ['ÁLVARO TESTE', 'ana teste', 'ÉRICA TESTE', 'ZETA TESTE']
    && array_column($renumberRows, 'numero') === ['1', '2', '3', '4'], 'Renumeração substitui posições anteriores por sequência completa a partir de 1');
$renumberUnchanged = $renumberDb->prepare('SELECT numero FROM alunos_passivo WHERE id=?');
$renumberUnchanged->execute([$renumberIds['INATIVO TESTE']]);
checkHttp($renumberUnchanged->fetchColumn() === '3', 'Renumeração preserva registro inativo da caixa');
$renumberUnchanged->execute([$renumberIds['OUTRA CAIXA TESTE']]);
checkHttp($renumberUnchanged->fetchColumn() === '1', 'Renumeração preserva outras caixas');
$renumberTxt = request('POST', $baseUrl . '/passivo/exportar', $cookieAdmin, [
    '_csrf_token' => csrf($renumberPage['body']), 'caixa' => 'CX-RENUMERAR',
]);
checkHttp($renumberTxt['status'] === 200 && $renumberTxt['body'] === "1 - ÁLVARO TESTE\r\n2 - ana teste\r\n3 - ÉRICA TESTE\r\n4 - ZETA TESTE\r\n",
    'TXT usa a sequência alfabética confirmada');
request('POST', $baseUrl . '/passivo/ferramentas/enumerar/confirmar', $cookieAdmin, [
    '_csrf_token' => csrf($renumberPage['body']), 'preview_token' => $renumberToken,
]);
checkHttp($renumberRows === $renumberDb->query("SELECT nome_completo,numero FROM alunos_passivo WHERE caixa_normalizada='cx-renumerar' AND ativo=1 ORDER BY CAST(numero AS INTEGER)")->fetchAll(),
    'Repetir confirmação não altera novamente os registros');
unset($renumberInsert, $renumberUnchanged);
$renumberDb = null;
