<?php
declare(strict_types=1);

// Included only by the smoke runner, against its disposable database.
$movementEditDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$movementEditDb->exec('PRAGMA foreign_keys=ON');
$movementEditClass = $movementEditDb->prepare('INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES(?,?,2027)');
$movementEditClass->execute(['Primeiro teste edicao', 'primeiro teste edicao']);
$movementEditSource = (int) $movementEditDb->lastInsertId();
$movementEditClass->execute(['Segundo teste edicao', 'segundo teste edicao']);
$movementEditTarget = (int) $movementEditDb->lastInsertId();
$movementEditDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(?,?,?,?)')
    ->execute(['Aluno HTTP movimentacao edicao', 'aluno http movimentacao edicao', '2010-05-10', $movementEditSource]);
$movementEditStudent = (int) $movementEditDb->lastInsertId();
$movementEditUrl = $baseUrl . '/aluno/editar/' . $movementEditStudent;
$movementEditPage = request('GET', $movementEditUrl, $cookieAdmin);
checkHttp(str_contains($movementEditPage['body'], '<select id="id_turma"'), 'Cadastro sem movimentacao permite corrigir turma inicial');
$movementEditFields = [
    '_csrf_token' => csrf($movementEditPage['body']), 'revisao' => formRevision($movementEditPage['body']),
    'nome_completo' => 'Aluno HTTP movimentacao edicao', 'data_nascimento' => '2010-05-10',
    'id_turma' => (string) $movementEditTarget, 'telefone_aluno' => '', 'telefone_responsavel' => '',
];
$movementEditInitial = request('POST', $movementEditUrl, $cookieAdmin, $movementEditFields);
checkHttp($movementEditInitial['status'] === 302
    && (int) $movementEditDb->query('SELECT id_turma FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn() === $movementEditTarget,
    'Correcao de turma inicial sem historico permanece permitida pelo HTTP');

// Seed a coherent historical progression; the integration regression exercises
// the real annual service, while this check focuses on the HTTP form boundary.
$movementEditActor = (int) $movementEditDb->query("SELECT id FROM usuarios WHERE email_lookup='" . DataProtection::emailIndex('admin@example.test') . "'")->fetchColumn();
$movementEditDb->prepare("INSERT INTO alunos_movimentacao_lotes(tipo,ano,realizado_por) VALUES('progressao',2027,?)")->execute([$movementEditActor]);
$movementEditBatch = (int) $movementEditDb->lastInsertId();
$movementEditDb->prepare('INSERT INTO alunos_movimentacoes(lote_id,aluno_id,origem_id,destino_id,origem_nome,destino_nome,origem_ano,destino_ano,origem_serie,destino_serie) VALUES(?,?,?,?,?,?,2027,2027,10,11)')
    ->execute([$movementEditBatch, $movementEditStudent, $movementEditSource, $movementEditTarget, 'Primeiro teste edicao', 'Segundo teste edicao']);
$movementEditPage = request('GET', $movementEditUrl, $cookieAdmin);
checkHttp(!str_contains($movementEditPage['body'], '<select id="id_turma"')
    && str_contains($movementEditPage['body'], 'id="turma_atual"')
    && str_contains($movementEditPage['body'], 'readonly aria-describedby="turma-movement-help"')
    && str_contains($movementEditPage['body'], 'name="id_turma" value="' . $movementEditTarget . '"')
    && str_contains($movementEditPage['body'], '>Movimentar alunos</a>'),
    'Aluno com historico mostra turma atual somente para consulta e orienta Movimentar alunos');
$movementEditBefore = $movementEditDb->query('SELECT * FROM alunos WHERE id=' . $movementEditStudent)->fetch();
$movementEditHistory = $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll();
$movementEditFields['_csrf_token'] = csrf($movementEditPage['body']);
$movementEditFields['revisao'] = formRevision($movementEditPage['body']);
$movementEditFields['id_turma'] = (string) $movementEditSource;
$movementEditFields['telefone_aluno'] = '65999996666';
$movementEditRejected = request('POST', $movementEditUrl, $cookieAdmin, $movementEditFields);
checkHttp($movementEditRejected['status'] === 422
    && str_contains($movementEditRejected['body'], 'Para alterar a turma, use Movimentar alunos')
    && formRevision($movementEditRejected['body']) === $movementEditFields['revisao']
    && $movementEditBefore === $movementEditDb->query('SELECT * FROM alunos WHERE id=' . $movementEditStudent)->fetch()
    && $movementEditHistory === $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll(),
    'POST com revisao atual nao altera turma movimentada nem grava os demais campos da tentativa');
$movementEditFields['id_turma'] = (string) $movementEditTarget;
$movementEditContact = request('POST', $movementEditUrl, $cookieAdmin, $movementEditFields);
checkHttp($movementEditContact['status'] === 302
    && $movementEditDb->query('SELECT telefone_aluno FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn() === '65999996666'
    && (int) $movementEditDb->query('SELECT id_turma FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn() === $movementEditTarget
    && $movementEditHistory === $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll(),
    'Editar contato depois da movimentacao preserva a turma e todo o historico');
$movementEditEmployeeCookie = $tempRoot . DIRECTORY_SEPARATOR . 'movement-edit-employee.cookies';
$movementEditEmployeePassword = 'Frase funcionario movimentacao 2027';
$movementEditEmployeeId = test_insert_user($movementEditDb, ['nome' => 'Funcionario edicao movimentacao', 'email' => 'movement-edit@example.test',
    'senha' => password_hash($movementEditEmployeePassword, PASSWORD_DEFAULT), 'tipo' => 'funcionario']);
$movementEditEmployeeLogin = request('GET', $baseUrl . '/login', $movementEditEmployeeCookie);
$movementEditEmployeeAuth = request('POST', $baseUrl . '/login/entrar', $movementEditEmployeeCookie, [
    '_csrf_token' => csrf($movementEditEmployeeLogin['body']), 'email' => 'movement-edit@example.test', 'senha' => $movementEditEmployeePassword,
]);
$movementEditEmployeePage = request('GET', $movementEditUrl, $movementEditEmployeeCookie);
checkHttp($movementEditEmployeeAuth['status'] === 302 && $movementEditEmployeePage['status'] === 403
    && !str_contains($movementEditEmployeePage['body'], '65999996666'),
    'Funcionario sem concessao nao abre edicao nem recebe contatos do aluno');
$movementEditEmployeeProfile = request('GET', $baseUrl . '/aluno/perfil/' . $movementEditStudent, $movementEditEmployeeCookie);
$movementEditFields['_csrf_token'] = csrf($movementEditEmployeeProfile['body']);
$movementEditFields['revisao'] = (string) $movementEditDb->query('SELECT revisao FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn();
$movementEditFields['id_turma'] = (string) $movementEditSource;
$movementEditBefore = $movementEditDb->query('SELECT * FROM alunos WHERE id=' . $movementEditStudent)->fetch();
$movementEditEmployeeRejected = request('POST', $movementEditUrl, $movementEditEmployeeCookie, $movementEditFields);
checkHttp($movementEditEmployeeRejected['status'] === 403
    && $movementEditBefore === $movementEditDb->query('SELECT * FROM alunos WHERE id=' . $movementEditStudent)->fetch()
    && $movementEditHistory === $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll(),
    'POST direto sem concessao preserva integralmente cadastro e historico mesmo com revisao e CSRF validos');

// Preserve the delegated-edit workflow under an explicit, expiring synthetic approval.
$movementEditGrantProcess = null;
$movementEditGrantBase = 'http://127.0.0.1:' . ($port + 5);
$movementEditGrantEnvironment = array_merge($baseEnvironment, [
    'ACCESS_HEALTH_STAFF_IDS' => ' ', 'ACCESS_EXPORT_STAFF_IDS' => ' ',
    'ACCESS_CONTACT_STAFF_IDS' => (string) $movementEditEmployeeId,
    'ACCESS_MANAGE_STAFF_IDS' => (string) $movementEditEmployeeId,
    'ACCESS_APPROVAL_REFERENCE' => 'REGRESSAO SINTETICA EDICAO DE CONTATOS',
    'ACCESS_APPROVAL_VALID_UNTIL' => gmdate('Y-m-d', time() + 86400),
]);
try {
    $movementEditGrantProcess = startServer($port + 5, $movementEditGrantEnvironment, $serverOut, $serverError);
    waitForServer($movementEditGrantBase . '/login', $movementEditEmployeeCookie);
    $movementEditGrantUrl = $movementEditGrantBase . '/aluno/editar/' . $movementEditStudent;
    $movementEditEmployeePage = request('GET', $movementEditGrantUrl, $movementEditEmployeeCookie);
    checkHttp($movementEditEmployeePage['status'] === 200
        && str_contains($movementEditEmployeePage['body'], 'solicite a um administrador')
        && !str_contains($movementEditEmployeePage['body'], '>Movimentar alunos</a>')
        && !str_contains($movementEditEmployeePage['body'], 'Acessar renovação de DVA'),
        'Concessao de contatos e edicao mantem orientacao administrativa sem ampliar acesso a movimentacao ou saude');
    $movementEditFields['_csrf_token'] = csrf($movementEditEmployeePage['body']);
    $movementEditFields['revisao'] = formRevision($movementEditEmployeePage['body']);
    $movementEditEmployeeRejected = request('POST', $movementEditGrantUrl, $movementEditEmployeeCookie, $movementEditFields);
    checkHttp($movementEditEmployeeRejected['status'] === 422
        && str_contains($movementEditEmployeeRejected['body'], 'solicite a um administrador')
        && str_contains($movementEditEmployeeRejected['body'], 'href="#turma_atual"')
        && str_contains($movementEditEmployeeRejected['body'], 'id="turma_atual-error"')
        && !str_contains($movementEditEmployeeRejected['body'], '>Movimentar alunos</a>')
        && $movementEditBefore === $movementEditDb->query('SELECT * FROM alunos WHERE id=' . $movementEditStudent)->fetch()
        && $movementEditHistory === $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll(),
        'Concessao de edicao nao permite contornar movimentacao e preserva cadastro e historico diante da recusa');
    $movementEditFields['_csrf_token'] = csrf($movementEditEmployeeRejected['body']);
    $movementEditFields['revisao'] = formRevision($movementEditEmployeeRejected['body']);
    $movementEditFields['id_turma'] = (string) $movementEditTarget;
    $movementEditFields['telefone_aluno'] = '65999997777';
    $movementEditGrantedSave = request('POST', $movementEditGrantUrl, $movementEditEmployeeCookie, $movementEditFields);
    checkHttp($movementEditGrantedSave['status'] === 302
        && $movementEditDb->query('SELECT telefone_aluno FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn() === '65999997777'
        && (int) $movementEditDb->query('SELECT id_turma FROM alunos WHERE id=' . $movementEditStudent)->fetchColumn() === $movementEditTarget
        && $movementEditHistory === $movementEditDb->query('SELECT * FROM alunos_movimentacoes WHERE aluno_id=' . $movementEditStudent)->fetchAll(),
        'Concessao explicita permite editar contato preservando turma e historico');
} finally { stopServer($movementEditGrantProcess); }
checkHttp(request('GET', $movementEditUrl, $movementEditEmployeeCookie)['status'] === 403,
    'Concessao do servidor de teste nao altera a politica restritiva do servidor principal');
$movementEditClass = null;
$movementEditDb = null;
