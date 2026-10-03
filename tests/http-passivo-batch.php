<?php

declare(strict_types=1);

// Included by http-smoke.php: fixtures and writes use only its temporary database.
$batchDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$batchDb->exec('PRAGMA foreign_keys=ON');
$batchActor = (int) $batchDb->query("SELECT id FROM usuarios WHERE email='admin@example.test'")->fetchColumn();
$batchInsertStudent = static function (string $name, bool $active = true) use ($batchDb, $batchActor): int {
    $batchDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,ativo) VALUES(?,?,?,?)')
        ->execute([$name, strtolower($name), '2010-05-10', $active ? 1 : 0]);
    $id = (int) $batchDb->lastInsertId();
    $batchDb->prepare('INSERT INTO dvas(id_aluno,id_usuario_registro,data_vencimento,observacao) VALUES(?,?,?,?)')
        ->execute([$id, $batchActor, '2027-06-01', 'DVA ficticia do teste de lote']);

    return $id;
};
$batchInsertPassive = static function (string $name, string $box, string $number, bool $active = true) use ($batchDb): void {
    $batchDb->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,numero,numero_normalizado,caixa,caixa_normalizada,ativo) VALUES(?,?,?,?,?,?,?)')
        ->execute([$name, strtolower($name), $number, strtolower($number), $box, strtolower($box), $active ? 1 : 0]);
};
$batchPassiveCount = static fn (): int => (int) $batchDb->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn();
$batchStudentStates = static function (array $ids) use ($batchDb): array {
    $statement = $batchDb->prepare('SELECT id,ativo,nome_completo,data_nascimento FROM alunos WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $statement->execute($ids);

    return $statement->fetchAll();
};
$batchResolveLocation = static fn (string $location): string => str_starts_with($location, 'http') ? $location : $baseUrl . '/' . ltrim($location, '/');
$batchPrepare = static function (array $ids, string $box, string $kind) use ($baseUrl, $cookieAdmin, $batchResolveLocation): array {
    $list = request('GET', $baseUrl . '/aluno', $cookieAdmin);
    $selected = request('POST', $baseUrl . '/aluno/arquivar-lote/selecionar', $cookieAdmin, [
        '_csrf_token' => csrf($list['body']), 'alunos' => array_map('strval', $ids),
    ]);
    $location = $selected['headers']['location'] ?? '';
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $selection = $query['selecao'] ?? '';
    checkHttp($selected['status'] === 302 && is_string($selection) && preg_match('/^[a-f0-9]{64}$/D', $selection) === 1, 'Lote cria selecao opaca em sessao');
    $url = $batchResolveLocation($location);
    $form = request('GET', $url, $cookieAdmin);
    checkHttp($form['status'] === 200 && str_contains($form['body'], 'name="tipo_caixa"'), 'Formulario do lote oferece escolha de caixa');
    $preview = request('POST', $baseUrl . '/aluno/arquivar-lote/preview', $cookieAdmin, [
        '_csrf_token' => csrf($form['body']), 'selecao' => $selection, 'tipo_caixa' => $kind,
        'caixa_existente' => $kind === 'existente' ? $box : '', 'caixa_nova' => $kind === 'nova' ? $box : '',
    ]);
    checkHttp($preview['status'] === 302, 'Previa de lote usa POST e redireciona para consulta');
    $ready = request('GET', $url, $cookieAdmin);
    checkHttp($ready['status'] === 200, 'Previa do lote pode ser consultada apos redirecionamento');

    return ['selecao' => $selection, 'url' => $url, 'body' => $ready['body'], 'preview_token' => hiddenValue($ready['body'], 'preview_token')];
};

$batchZeta = $batchInsertStudent('Lote HTTP Zeta');
$batchAna = $batchInsertStudent('Lote HTTP Ana <script>teste</script>', false);
$batchBia = $batchInsertStudent('Lote HTTP Bia');
$batchOutsider = $batchInsertStudent('Lote HTTP Aluno Fora Da Selecao');
$batchIds = [$batchZeta, $batchAna, $batchBia];
$batchInitialStates = $batchStudentStates($batchIds);
$batchDvas = $batchDb->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
$batchInitialCount = $batchPassiveCount();
$batchList = request('GET', $baseUrl . '/aluno', $cookieAdmin);
checkHttp($batchList['status'] === 200 && str_contains($batchList['body'], 'name="alunos[]"') && str_contains($batchList['body'], '/aluno/arquivar-lote/selecionar'), 'Administrador seleciona alunos na lista para arquivamento em lote');
$batchCsrf = csrf($batchList['body']);
checkHttp(request('POST', $baseUrl . '/aluno/arquivar-lote/selecionar', $cookieAdmin, ['_csrf_token' => 'invalid', 'alunos' => $batchIds])['status'] === 419, 'CSRF protege selecao do lote');
foreach (['selecionar', 'preview', 'confirmar'] as $operation) {
    checkHttp(request('GET', $baseUrl . '/aluno/arquivar-lote/' . $operation, $cookieAdmin)['status'] === 405, 'GET nao executa etapa ' . $operation . ' do lote');
}
foreach ([[], ['1x'], [[$batchZeta]], array_map('strval', range(1, 201))] as $invalidIds) {
    $invalid = request('POST', $baseUrl . '/aluno/arquivar-lote/selecionar', $cookieAdmin, ['_csrf_token' => $batchCsrf, 'alunos' => $invalidIds]);
    checkHttp(in_array($invalid['status'], [302, 422], true) && $batchPassiveCount() === $batchInitialCount, 'Selecao invalida nao arquiva alunos nem cria lote parcial');
}

// Certificate smoke checks deliberately terminate the employee session; sign in again.
$batchEmployeeLogin = request('GET', $baseUrl . '/login', $cookieEmployee);
$batchEmployeeAuth = request('POST', $baseUrl . '/login/entrar', $cookieEmployee, [
    '_csrf_token' => csrf($batchEmployeeLogin['body']), 'email' => 'employee@example.test', 'senha' => $employeePassword,
]);
checkHttp($batchEmployeeAuth['status'] === 302 && str_contains($batchEmployeeAuth['headers']['location'] ?? '', '/dashboard'), 'Funcionario reassume sessao para checar permissoes do lote');
$batchEmployeePage = request('GET', $baseUrl . '/aluno', $cookieEmployee);
checkHttp(!str_contains($batchEmployeePage['body'], '/aluno/arquivar-lote/selecionar'), 'Funcionario nao recebe selecao administrativa do lote');
$batchEmployeeCsrf = csrf($batchEmployeePage['body']);
checkHttp(request('GET', $baseUrl . '/aluno/arquivar-lote?selecao=' . str_repeat('a', 64), $cookieEmployee)['status'] === 403, 'Funcionario nao abre formulario administrativo do lote');
foreach (['selecionar', 'preview', 'confirmar'] as $operation) {
    checkHttp(request('POST', $baseUrl . '/aluno/arquivar-lote/' . $operation, $cookieEmployee, ['_csrf_token' => $batchEmployeeCsrf, 'alunos' => $batchIds, 'selecao' => str_repeat('a', 64), 'preview_token' => str_repeat('b', 64), 'confirmar' => '1'])['status'] === 403, 'Funcionario bloqueado na etapa ' . $operation . ' do lote');
}
checkHttp(request('GET', $baseUrl . '/aluno/arquivar-lote?selecao=' . str_repeat('a', 64), $cookieGuest)['status'] === 302, 'Visitante nao acessa selecao do lote');

$batchNew = $batchPrepare($batchIds, 'CX-LOTE-HTTP', 'nova');
checkHttp(str_contains($batchNew['body'], '&lt;script&gt;') && !str_contains($batchNew['body'], '<script>teste</script>'), 'Previa de lote escapa nomes dos alunos');
checkHttp($batchStudentStates($batchIds) === $batchInitialStates && $batchPassiveCount() === $batchInitialCount, 'Previa HTTP nao inativa nem arquiva alunos');
checkHttp(request('POST', $baseUrl . '/aluno/arquivar-lote/preview', $cookieAdmin, ['_csrf_token' => 'invalid', 'selecao' => $batchNew['selecao'], 'tipo_caixa' => 'nova', 'caixa_nova' => 'CX-LOTE-HTTP'])['status'] === 419, 'CSRF protege previa do lote');
checkHttp(request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $cookieAdmin, ['_csrf_token' => 'invalid', 'selecao' => $batchNew['selecao'], 'preview_token' => $batchNew['preview_token'], 'confirmar' => '1'])['status'] === 419, 'CSRF protege confirmacao do lote');

// Opaque selections and preview tokens belong to one authenticated session.
$batchOtherCookie = $tempRoot . DIRECTORY_SEPARATOR . 'batch-other-admin.cookies';
$batchOtherLogin = request('GET', $baseUrl . '/login', $batchOtherCookie);
$batchOtherAuth = request('POST', $baseUrl . '/login/entrar', $batchOtherCookie, ['_csrf_token' => csrf($batchOtherLogin['body']), 'email' => 'admin@example.test', 'senha' => $permanentPassword]);
checkHttp($batchOtherAuth['status'] === 302 && str_contains($batchOtherAuth['headers']['location'] ?? '', '/dashboard'), 'Segunda sessao administrativa de teste autenticada');
$batchForeignForm = request('GET', $batchNew['url'], $batchOtherCookie);
checkHttp(in_array($batchForeignForm['status'], [302, 404], true), 'Selecao opaca nao pode ser aberta por outra sessao');
$batchOtherDashboard = request('GET', $baseUrl . '/dashboard', $batchOtherCookie);
$batchForeignConfirm = request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $batchOtherCookie, ['_csrf_token' => csrf($batchOtherDashboard['body']), 'selecao' => $batchNew['selecao'], 'preview_token' => $batchNew['preview_token'], 'confirmar' => '1']);
checkHttp(in_array($batchForeignConfirm['status'], [302, 404, 409, 422], true) && $batchPassiveCount() === $batchInitialCount, 'Outra sessao nao confirma selecao alheia');

$batchConfirmation = ['_csrf_token' => csrf($batchNew['body']), 'selecao' => $batchNew['selecao'], 'preview_token' => $batchNew['preview_token'], 'confirmar' => '1', 'alunos' => [(string) $batchOutsider], 'tipo_caixa' => 'nova', 'caixa_nova' => 'CX-FORJADA-HTTP', 'caixa_existente' => 'CX-FORJADA-HTTP'];
$batchSaved = request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $cookieAdmin, $batchConfirmation);
checkHttp($batchSaved['status'] === 302 && str_contains($batchSaved['headers']['location'] ?? '', '/passivo') && str_contains($batchSaved['headers']['location'] ?? '', 'ordem=numero'), 'Confirmacao redireciona ao arquivo passivo com ordem de numeracao');
$batchArchives = $batchDb->query("SELECT aluno_origem_id,numero FROM alunos_passivo WHERE caixa_normalizada='cx-lote-http' ORDER BY CAST(numero AS INTEGER)")->fetchAll();
checkHttp(array_column($batchArchives, 'aluno_origem_id') === [$batchAna, $batchBia, $batchZeta] && array_column($batchArchives, 'numero') === ['1', '2', '3'], 'Lote novo atribui posicoes consecutivas por nome');
checkHttp(array_column($batchStudentStates($batchIds), 'ativo') === [0, 0, 0] && (int) $batchDb->query('SELECT ativo FROM alunos WHERE id=' . $batchOutsider)->fetchColumn() === 1, 'Confirmacao inativa selecionados e ignora IDs forjados no POST');
checkHttp((int) $batchDb->query("SELECT COUNT(*) FROM alunos_passivo WHERE caixa_normalizada='cx-forjada-http'")->fetchColumn() === 0, 'Confirmacao usa somente a caixa armazenada na previa');
checkHttp($batchDb->query('SELECT * FROM dvas ORDER BY id')->fetchAll() === $batchDvas, 'Arquivamento HTTP preserva integralmente DVAs e historico');
$batchAuditAfter = (int) $batchDb->query("SELECT COUNT(*) FROM security_audit WHERE action='passive.batch_archived'")->fetchColumn();
checkHttp($batchAuditAfter === 1 && (int) $batchDb->query("SELECT COUNT(*) FROM security_audit WHERE action='passive.student_archived' AND actor_user_id=" . $batchActor . ' AND resource_id IN (' . implode(',', array_column($batchDb->query("SELECT id FROM alunos_passivo WHERE caixa_normalizada='cx-lote-http'")->fetchAll(), 'id')) . ')')->fetchColumn() === 3, 'Lote grava auditorias por aluno e resumo com autoria');
$batchCountAfter = $batchPassiveCount();
$batchRepeated = request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $cookieAdmin, $batchConfirmation);
checkHttp($batchRepeated['status'] === 302 && $batchPassiveCount() === $batchCountAfter && (int) $batchDb->query("SELECT COUNT(*) FROM security_audit WHERE action='passive.batch_archived'")->fetchColumn() === $batchAuditAfter, 'Reenvio da mesma confirmacao nao duplica alunos nem auditoria');

$batchInsertPassive('Arquivo inativo numerado ficticio', 'CX-LOTE-HTTP', '00009', false);
$batchExistingBia = $batchInsertStudent('Lote Existente HTTP Bia');
$batchExistingAna = $batchInsertStudent('Lote Existente HTTP Ana');
$batchExisting = $batchPrepare([$batchExistingBia, $batchExistingAna], 'CX-LOTE-HTTP', 'existente');
$batchExistingConfirm = request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $cookieAdmin, ['_csrf_token' => csrf($batchExisting['body']), 'selecao' => $batchExisting['selecao'], 'preview_token' => $batchExisting['preview_token'], 'confirmar' => '1']);
$batchExistingNumbers = $batchDb->query('SELECT numero FROM alunos_passivo WHERE aluno_origem_id IN (' . $batchExistingAna . ',' . $batchExistingBia . ') ORDER BY CAST(numero AS INTEGER)')->fetchAll();
checkHttp($batchExistingConfirm['status'] === 302 && array_column($batchExistingNumbers, 'numero') === ['10', '11'], 'Caixa existente continua depois do maior numero incluindo arquivo inativo');

$batchStaleAna = $batchInsertStudent('Lote Concorrente HTTP Ana');
$batchStaleBia = $batchInsertStudent('Lote Concorrente HTTP Bia');
$batchStaleIds = [$batchStaleAna, $batchStaleBia];
$batchStale = $batchPrepare($batchStaleIds, 'CX-LOTE-HTTP', 'existente');
$batchInsertPassive('Arquivo concorrente ficticio', 'CX-LOTE-HTTP', '12');
$batchBeforeStale = $batchPassiveCount();
$batchStaleStates = $batchStudentStates($batchStaleIds);
$batchStaleConfirm = request('POST', $baseUrl . '/aluno/arquivar-lote/confirmar', $cookieAdmin, ['_csrf_token' => csrf($batchStale['body']), 'selecao' => $batchStale['selecao'], 'preview_token' => $batchStale['preview_token'], 'confirmar' => '1']);
checkHttp($batchStaleConfirm['status'] === 302 && $batchPassiveCount() === $batchBeforeStale && $batchStudentStates($batchStaleIds) === $batchStaleStates, 'Mudanca na caixa apos previa rejeita todo lote sem inativacao parcial');
unset($batchInsertStudent, $batchInsertPassive, $batchPassiveCount, $batchStudentStates, $batchPrepare, $batchResolveLocation);
$batchDb = null;
