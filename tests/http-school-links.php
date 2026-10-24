<?php

declare(strict_types=1);

// Included by the smoke runner only, against its disposable database and keys.
$schoolDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schoolDb->exec('PRAGMA foreign_keys=ON');
$schoolClassInsert = $schoolDb->prepare('INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES(?,?,2026)');
$schoolClassInsert->execute(['Turma escolar HTTP A', 'turma escolar http a']);
$schoolSource = (int) $schoolDb->lastInsertId();
$schoolClassInsert->execute(['Turma escolar HTTP B', 'turma escolar http b']);
$schoolTarget = (int) $schoolDb->lastInsertId();
$schoolDb->prepare('INSERT INTO turmas_series(id_turma,serie) VALUES(?,10),(?,10)')->execute([$schoolSource, $schoolTarget]);
$schoolDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(?,?,?,?)')
    ->execute(['Aluno vínculo escolar HTTP', 'aluno vinculo escolar http', '2010-05-10', $schoolSource]);
$schoolStudent = (int) $schoolDb->lastInsertId();
$schoolGetPath = '/aluno/transferir/' . $schoolStudent;
$schoolPreviewPath = '/aluno/transferir/preview/' . $schoolStudent;
$schoolEmployee = request('GET', $baseUrl . $schoolGetPath, $cookieEmployee);
checkHttp($schoolEmployee['status'] === 403, 'Funcionario nao abre transferencia escolar');
$schoolPage = request('GET', $baseUrl . $schoolGetPath, $cookieAdmin);
checkHttp($schoolPage['status'] === 200 && str_contains($schoolPage['body'], 'Gerar prévia'), 'Administrador escolhe turma para transferencia sem JavaScript');
$schoolMissingCsrf = request('POST', $baseUrl . $schoolPreviewPath, $cookieAdmin, ['destino' => (string) $schoolTarget]);
checkHttp($schoolMissingCsrf['status'] === 419, 'Previa escolar exige CSRF');
$schoolPreview = request('POST', $baseUrl . $schoolPreviewPath, $cookieAdmin, ['_csrf_token' => csrf($schoolPage['body']), 'destino' => (string) $schoolTarget]);
checkHttp($schoolPreview['status'] === 302 && str_contains($schoolPreview['headers']['location'] ?? '', 'preview='), 'Transferencia escolar produz previa guardada na sessao');
$schoolReview = request('GET', $schoolPreview['headers']['location'], $cookieAdmin);
$schoolFields = ['_csrf_token' => csrf($schoolReview['body']), 'preview_token' => hiddenValue($schoolReview['body'], 'preview_token'),
    '_request_token' => hiddenValue($schoolReview['body'], '_request_token', 32)];
$schoolNoConfirmation = request('POST', $baseUrl . '/aluno/vinculo/confirmar', $cookieAdmin, $schoolFields);
checkHttp($schoolNoConfirmation['status'] === 302 && (int) $schoolDb->query('SELECT id_turma FROM alunos WHERE id=' . $schoolStudent)->fetchColumn() === $schoolSource,
    'Previa sem confirmacao explicita nao transfere aluno');
$schoolFields['confirmar'] = '1';
$schoolDb->prepare('UPDATE turmas SET atualizado_em=atualizado_em WHERE id=?')->execute([$schoolTarget]);
$schoolStale = request('POST', $baseUrl . '/aluno/vinculo/confirmar', $cookieAdmin, $schoolFields);
checkHttp($schoolStale['status'] === 302 && (int) $schoolDb->query('SELECT id_turma FROM alunos WHERE id=' . $schoolStudent)->fetchColumn() === $schoolSource
    && (int) $schoolDb->query('SELECT COUNT(*) FROM alunos_vinculos_historico WHERE aluno_id=' . $schoolStudent)->fetchColumn() === 0, 'Alteracao concorrente de turma invalida previa escolar');
$schoolPreview = request('POST', $baseUrl . $schoolPreviewPath, $cookieAdmin, ['_csrf_token' => csrf($schoolPage['body']), 'destino' => (string) $schoolTarget]);
$schoolReview = request('GET', $schoolPreview['headers']['location'], $cookieAdmin);
$schoolFields = ['_csrf_token' => csrf($schoolReview['body']), 'preview_token' => hiddenValue($schoolReview['body'], 'preview_token'),
    '_request_token' => hiddenValue($schoolReview['body'], '_request_token', 32), 'confirmar' => '1'];
$schoolConfirmed = request('POST', $baseUrl . '/aluno/vinculo/confirmar', $cookieAdmin, $schoolFields);
$schoolProfile = request('GET', $baseUrl . '/aluno/perfil/' . $schoolStudent, $cookieAdmin);
checkHttp($schoolConfirmed['status'] === 302 && (int) $schoolDb->query('SELECT id_turma FROM alunos WHERE id=' . $schoolStudent)->fetchColumn() === $schoolTarget
    && str_contains($schoolProfile['body'], 'Transferência de turma'), 'Transferencia confirmada aparece no perfil e preserva serie');
request('POST', $baseUrl . '/aluno/vinculo/confirmar', $cookieAdmin, $schoolFields);
checkHttp((int) $schoolDb->query('SELECT COUNT(*) FROM alunos_vinculos_historico WHERE aluno_id=' . $schoolStudent)->fetchColumn() === 1, 'Reenvio HTTP nao duplica transferencia escolar');

$schoolDb->prepare('UPDATE alunos SET ativo=0 WHERE id=?')->execute([$schoolStudent]);
$schoolDb->prepare('INSERT INTO alunos_passivo(aluno_origem_id,nome_completo,nome_normalizado,data_nascimento,caixa,caixa_normalizada,numero,numero_normalizado)
    VALUES(?,?,?,?,?,?,?,?)')->execute([$schoolStudent, 'Aluno vínculo escolar HTTP', 'aluno vinculo escolar http', '2010-05-10', 'ESCOLAR HTTP', 'escolar http', '1', '1']);
$schoolPassive = (int) $schoolDb->lastInsertId();
$schoolReturnPath = '/passivo/retornar/' . $schoolPassive;
$schoolEmployee = request('GET', $baseUrl . $schoolReturnPath, $cookieEmployee);
checkHttp($schoolEmployee['status'] === 403, 'Funcionario nao abre retorno do passivo');
$schoolReturnPage = request('GET', $baseUrl . $schoolReturnPath, $cookieAdmin);
$schoolPreview = request('POST', $baseUrl . '/passivo/retornar/preview/' . $schoolPassive, $cookieAdmin,
    ['_csrf_token' => csrf($schoolReturnPage['body']), 'destino' => (string) $schoolSource]);
$schoolReview = request('GET', $schoolPreview['headers']['location'], $cookieAdmin);
$schoolReturn = request('POST', $baseUrl . '/aluno/vinculo/confirmar', $cookieAdmin,
    ['_csrf_token' => csrf($schoolReview['body']), 'preview_token' => hiddenValue($schoolReview['body'], 'preview_token'),
        '_request_token' => hiddenValue($schoolReview['body'], '_request_token', 32), 'confirmar' => '1']);
checkHttp($schoolReturn['status'] === 302 && (int) $schoolDb->query('SELECT ativo FROM alunos WHERE id=' . $schoolStudent)->fetchColumn() === 1
    && (int) $schoolDb->query('SELECT id_turma FROM alunos WHERE id=' . $schoolStudent)->fetchColumn() === $schoolSource
    && (int) $schoolDb->query('SELECT ativo FROM alunos_passivo WHERE id=' . $schoolPassive)->fetchColumn() === 0, 'Retorno HTTP reativa aluno e encerra vinculo passivo no mesmo ato');
$schoolProfile = request('GET', $baseUrl . '/aluno/perfil/' . $schoolStudent, $cookieAdmin);
checkHttp(str_contains($schoolProfile['body'], 'Retorno à escola') && str_contains($schoolProfile['body'], 'Transferência de turma'), 'Perfil preserva transferencias e retornos no historico');
$schoolClassInsert = null;
$schoolDb = null;
