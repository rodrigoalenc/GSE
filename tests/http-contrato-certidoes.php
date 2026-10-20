<?php

declare(strict_types=1);

// Only the disposable HTTP test database receives these fictitious records.
$guardDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$guardDb->exec('PRAGMA foreign_keys=ON');
$guardNow = new DateTimeImmutable('today', new DateTimeZone($baseEnvironment['APP_TIMEZONE'] ?? 'America/Cuiaba'));
$guardYesterday = $guardNow->modify('-1 day')->format('Y-m-d');
$guardTomorrow = $guardNow->modify('+1 day')->format('Y-m-d');
$guardDb->prepare('INSERT INTO lista_fornecedores(nome,ativo) VALUES(?,1)')->execute(['Fornecedor bloqueado fictício']);
$guardSupplier = (int) $guardDb->lastInsertId();
$guardDb->prepare('INSERT INTO lista_fornecedores(nome,ativo) VALUES(?,1)')->execute(['Fornecedor regular fictício']);
$guardGoodSupplier = (int) $guardDb->lastInsertId();
$guardTypeName = 'Fiscal <script>certidao-teste</script>';
$guardDb->prepare('INSERT INTO lista_tipos_certidao(nome,ativo) VALUES(?,1)')->execute([$guardTypeName]);
$guardType = (int) $guardDb->lastInsertId();
$guardDb->prepare('INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento) VALUES(?,?,?,?)')
    ->execute([$guardSupplier, $guardType, $guardNow->modify('-30 days')->format('Y-m-d'), $guardTomorrow]);
$guardCertificate = (int) $guardDb->lastInsertId();
$guardAbsolute = static fn (string $path): string => str_starts_with($path, 'http') ? $path : $baseUrl . '/' . ltrim($path, '/');
$guardCreate = static function (string $title, int $supplier) use ($baseUrl, $cookieAdmin): array {
    $form = request('GET', $baseUrl . '/contrato/criar', $cookieAdmin);
    return request('POST', $baseUrl . '/contrato/criar', $cookieAdmin, [
        '_csrf_token' => csrf($form['body']), '_request_token' => hiddenValue($form['body'], '_request_token', 32),
        'titulo' => $title, 'valor' => '100,00', 'fornecedor' => (string) $supplier,
    ]);
};
$guardFirst = $guardCreate('Guarda certidão A', $guardSupplier);
$guardSecond = $guardCreate('Guarda certidão B', $guardGoodSupplier);
checkHttp(str_contains($guardFirst['headers']['location'] ?? '', '/contrato/detalhes/')
    && str_contains($guardSecond['headers']['location'] ?? '', '/contrato/detalhes/'), 'Pedidos são criados antes do vencimento da certidão');
$guardId = (int) basename(parse_url($guardFirst['headers']['location'], PHP_URL_PATH));
$guardGoodId = (int) basename(parse_url($guardSecond['headers']['location'], PHP_URL_PATH));
$guardSheet = (int) $guardDb->query('SELECT id FROM pedido_paginas WHERE id_pedido=' . $guardId)->fetchColumn();
$guardDb->prepare('UPDATE certidoes SET data_vencimento=? WHERE id=?')->execute([$guardYesterday, $guardCertificate]);
$guardState = static function () use ($guardDb): array {
    $state = [];
    foreach (['pedidos', 'pedido_paginas', 'pedido_produtos', 'modulo5_operacoes', 'request_receipts'] as $table) {
        $state[$table] = $guardDb->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    }
    return $state;
};
$guardAlertHidden = static function (string $html): bool {
    if (preg_match('/<section\b[^>]*data-contract-certificate-alert[^>]*>/s', $html, $match) !== 1) {
        throw new RuntimeException('Alerta de certidões não encontrado.');
    }
    return preg_match('/\bhidden\b/', $match[0]) === 1;
};
$guardBefore = $guardState();
$guardDetails = request('GET', $baseUrl . '/contrato/detalhes/' . $guardId, $cookieAdmin);
checkHttp($guardDetails['status'] === 200 && !$guardAlertHidden($guardDetails['body'])
    && str_contains($guardDetails['body'], 'Fornecedor bloqueado fictício')
    && str_contains($guardDetails['body'], $guardNow->modify('-1 day')->format('d/m/Y')),
    'Pedido e nota existentes exibem alerta após a certidão vencer');
checkHttp(str_contains($guardDetails['body'], 'Fiscal &lt;script&gt;certidao-teste&lt;/script&gt;')
    && !str_contains($guardDetails['body'], '<script>certidao-teste</script>'), 'Alerta identifica a certidão e escapa seu nome');
$guardDom = new DOMDocument();
@$guardDom->loadHTML($guardDetails['body']);
$guardXPath = new DOMXPath($guardDom);
checkHttp($guardXPath->query('//form[contains(@action,"/contrato/folha/")]//button')->length === 4
    && $guardXPath->query('//form[contains(@action,"/contrato/folha/")]//button[not(@disabled)]')->length === 0,
    'Novas notas e duplicações ficam desabilitadas somente no pedido afetado');
$guardListA = request('GET', $baseUrl . '/contrato?busca=' . rawurlencode('Guarda certidão A'), $cookieAdmin);
$guardListB = request('GET', $baseUrl . '/contrato?busca=' . rawurlencode('Guarda certidão B'), $cookieAdmin);
checkHttp(!$guardAlertHidden($guardListA['body']) && $guardAlertHidden($guardListB['body']), 'Lista de pedidos alerta somente sobre fornecedores dos pedidos exibidos');
$guardGoodDetails = request('GET', $baseUrl . '/contrato/detalhes/' . $guardGoodId, $cookieAdmin);
checkHttp($guardAlertHidden($guardGoodDetails['body']), 'Certidão vencida de outro fornecedor não alerta no pedido regular');
checkHttp($guardBefore === $guardState(), 'Consultar alertas não altera pedidos ou notas existentes');
$guardBlocked = $guardCreate('Pedido recusado por certidão', $guardSupplier);
checkHttp(str_ends_with($guardBlocked['headers']['location'] ?? '', '/contrato/criar') && $guardBefore === $guardState(),
    'POST direto também bloqueia pedido sem criar registros parciais');
$guardRetained = request('GET', $baseUrl . '/contrato/criar', $cookieAdmin);
checkHttp(!$guardAlertHidden($guardRetained['body']) && str_contains($guardRetained['body'], 'value="Pedido recusado por certidão"')
    && preg_match('/<button\b[^>]*data-contract-create-submit[^>]*\bdisabled\b/s', $guardRetained['body']) === 1,
    'Pedido recusado conserva preenchimento e apresenta certidões do fornecedor selecionado');
foreach ([false, true] as $guardCopy) {
    $guardResponse = request('POST', $baseUrl . '/contrato/folha/' . $guardId, $cookieAdmin, [
        '_csrf_token' => csrf($guardDetails['body']), 'observacao' => 'Nota recusada preservada', 'revisao' => '1',
        'copiar' => $guardCopy ? (string) $guardSheet : '', 'chave' => $guardCopy ? bin2hex(random_bytes(16)) : '',
    ]);
    checkHttp($guardResponse['status'] === 302 && $guardBefore === $guardState(),
        'Servidor bloqueia ' . ($guardCopy ? 'duplicação de nota' : 'nova nota') . ' sem alterar o pedido');
    $guardDraft = request('GET', $guardAbsolute($guardResponse['headers']['location']), $cookieAdmin);
    checkHttp(str_contains($guardDraft['body'], 'certidões vencidas')
        && ($guardCopy || str_contains($guardDraft['body'], 'Nota recusada preservada')),
        $guardCopy ? 'Recusa de duplicação explica o vencimento' : 'Recusa de nova nota explica o vencimento e mantém o preenchimento');
}
$guardAdditiveFields=contractDraftFields($guardDetails['body'],'new-additive');
$guardAdditiveFields['observacao']='Aditivo recusado preservado';
$guardAdditiveResponse=request('POST',$baseUrl.'/contrato/folha/'.$guardId,$cookieAdmin,$guardAdditiveFields);
$guardAdditiveRecovery=contractDraftRecovery($guardAdditiveResponse,$cookieAdmin);
checkHttp($guardBefore===$guardState() && str_contains($guardAdditiveRecovery['body'],'certidões vencidas')
    && str_ends_with($guardAdditiveResponse['headers']['location'] ?? '','#new-additive')
    && contractDraftFields($guardAdditiveRecovery['body'],'new-additive')['observacao']==='Aditivo recusado preservado',
    'Certidão vencida bloqueia POST de aditivo e preserva a observação no formulário correto');
$guardOtherNote = request('POST', $baseUrl . '/contrato/folha/' . $guardGoodId, $cookieAdmin, [
    '_csrf_token' => csrf($guardGoodDetails['body']), 'observacao' => 'Nota de fornecedor regular', 'revisao' => '1', 'copiar' => '', 'chave' => '',
]);
checkHttp($guardOtherNote['status'] === 302 && (int) $guardDb->query('SELECT COUNT(*) FROM pedido_paginas WHERE id_pedido=' . $guardGoodId)->fetchColumn() === 2,
    'Notas de outros fornecedores continuam permitidas');
$guardDb->prepare('UPDATE certidoes SET arquivado=1 WHERE id=?')->execute([$guardCertificate]);
$guardDb->prepare('INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,anterior_id) VALUES(?,?,?,?,?)')
    ->execute([$guardSupplier, $guardType, $guardNow->format('Y-m-d'), $guardTomorrow, $guardCertificate]);
$guardRegularized = request('GET', $baseUrl . '/contrato/detalhes/' . $guardId, $cookieAdmin);
checkHttp($guardAlertHidden($guardRegularized['body']), 'Regularizar a certidão remove automaticamente o alerta');
$guardAllowed = request('POST', $baseUrl . '/contrato/folha/' . $guardId, $cookieAdmin, [
    '_csrf_token' => csrf($guardRegularized['body']), 'observacao' => 'Nota após regularização', 'revisao' => '1', 'copiar' => '', 'chave' => '',
]);
checkHttp($guardAllowed['status'] === 302 && (int) $guardDb->query('SELECT COUNT(*) FROM pedido_paginas WHERE id_pedido=' . $guardId)->fetchColumn() === 2,
    'Criação de notas é liberada após regularização');
unset($guardCreate, $guardState, $guardDb);
