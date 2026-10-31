<?php
declare(strict_types=1);
// Included by http-smoke.php; only its disposable database, accounts and server.
$privacyDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$privacyDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,telefone_aluno,telefone_responsavel) VALUES(?,?,?,?,?)')
    ->execute(['Aluno política HTTP','aluno politica http','2010-05-10','65988881234','65988884321']);
$privacyStudent = (int)$privacyDb->lastInsertId();
$privacyDb->prepare('INSERT INTO dvas(id_aluno,data_vencimento,observacao) VALUES(?,?,?)')
    ->execute([$privacyStudent,'2037-12-31','OBSERVACAO_RESTRITA_HTTP']);
$privacyProfile = request('GET',$baseUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
checkHttp($privacyProfile['status']===200 && str_contains($privacyProfile['body'],'Aluno política HTTP'), 'Funcionário consulta cadastro básico autorizado');
foreach (['/aluno?q='.rawurlencode('política'),'/dashboard?q='.rawurlencode('política'),'/aluno/perfil/'.$privacyStudent] as $privacyPath) {
    $privacyPage=request('GET',$baseUrl.$privacyPath,$cookieEmployee);
    checkHttp($privacyPage['status']===200 && !preg_match('/OBSERVACAO_RESTRITA_HTTP|65988881234|65988884321|2037-12-31|31\/12\/2037|name="dva"|dva-badge|Situação atual da DVA/',$privacyPage['body']), 'Cadastro básico não revela saúde ou contatos: '.$privacyPath);
}
foreach (['/dva','/aluno/dva/'.$privacyStudent,'/aluno/criar','/aluno/editar/'.$privacyStudent,
    '/relatorio','/relatorio/csv','/relatorio/pdf','/aluno?dva=vencida','/dashboard?dva=vencida',
    '/formulario/rascunho?form=aluno/dva/'.$privacyStudent] as $privacyPath) {
    checkHttp(request('GET',$baseUrl.$privacyPath,$cookieEmployee)['status']===403, 'Requisição direta sem autorização específica retorna403: '.$privacyPath);
}
$privacyCsrf=csrf($privacyProfile['body']);
$privacyBefore=(int)$privacyDb->query('SELECT COUNT(*) FROM dvas WHERE id_aluno='.$privacyStudent)->fetchColumn();
checkHttp(request('POST',$baseUrl.'/aluno/dva/'.$privacyStudent,$cookieEmployee,
    ['_csrf_token'=>$privacyCsrf,'data_vencimento'=>'2038-12-31','observacao'=>'tentativa'])['status']===403
    && (int)$privacyDb->query('SELECT COUNT(*) FROM dvas WHERE id_aluno='.$privacyStudent)->fetchColumn()===$privacyBefore,
    'POST direto de funcionário não altera informação de saúde');
checkHttp(request('POST',$baseUrl.'/passivo/exportar',$cookieEmployee,['_csrf_token'=>$privacyCsrf,'caixa'=>'CX-HTTP'])['status']===403,
    'POST direto não permite exportação ampla de funcionário');
$privacyAdmin=request('GET',$baseUrl.'/aluno/perfil/'.$privacyStudent,$cookieAdmin);
checkHttp($privacyAdmin['status']===200 && str_contains($privacyAdmin['body'],'OBSERVACAO_RESTRITA_HTTP'), 'Administrador autorizado mantém consulta de saúde');
$privacyActorQuery=$privacyDb->prepare('SELECT id FROM usuarios WHERE email_lookup=?');
$privacyActorQuery->execute([DataProtection::emailIndex('employee@example.test')]);
$privacyActor=(int)$privacyActorQuery->fetchColumn();
checkHttp($privacyActor>0,'Concessões de teste pertencem apenas ao funcionário sintético');
$privacyGrantProcess=null;
$privacyGrantUrl='http://127.0.0.1:'.($port+4);
$privacyGrantEnvironment=array_merge($baseEnvironment,[
    'ACCESS_HEALTH_STAFF_IDS'=>(string)$privacyActor,
    'ACCESS_CONTACT_STAFF_IDS'=>' ', 'ACCESS_MANAGE_STAFF_IDS'=>' ', 'ACCESS_EXPORT_STAFF_IDS'=>' ',
    'ACCESS_APPROVAL_REFERENCE'=>'REGRESSAO SINTETICA SEM APROVACAO INSTITUCIONAL',
    'ACCESS_APPROVAL_VALID_UNTIL'=>gmdate('Y-m-d',time()+86400),
]);
try {
    $privacyGrantProcess=startServer($port+4,$privacyGrantEnvironment,$serverOut,$serverError);
    waitForServer($privacyGrantUrl.'/login',$cookieEmployee);
    $privacyReadOnly=request('GET',$privacyGrantUrl.'/aluno/dva/'.$privacyStudent,$cookieEmployee);
    checkHttp($privacyReadOnly['status']===200 && !str_contains($privacyReadOnly['body'],'Confirmar renovação')
        && str_contains($privacyReadOnly['body'],'autorização permite consultar'), 'Concessão de saúde permite consulta sem edição');
    $privacyReadOnlyProfile=request('GET',$privacyGrantUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
    checkHttp(str_contains($privacyReadOnlyProfile['body'],'OBSERVACAO_RESTRITA_HTTP')
        && !str_contains($privacyReadOnlyProfile['body'],'65988881234'), 'Concessão de saúde não concede contatos');
    checkHttp(request('POST',$privacyGrantUrl.'/aluno/dva/'.$privacyStudent,$cookieEmployee,
        ['_csrf_token'=>$privacyCsrf,'data_vencimento'=>'2038-12-31','observacao'=>'tentativa'])['status']===403
        && (int)$privacyDb->query('SELECT COUNT(*) FROM dvas WHERE id_aluno='.$privacyStudent)->fetchColumn()===$privacyBefore,
        'Concessão de consulta não permite POSTDVA nem modifica o histórico');
    checkHttp(request('POST',$privacyGrantUrl.'/formulario/rascunho',$cookieEmployee,
        ['_csrf_token'=>$privacyCsrf,'form'=>'aluno/dva/'.$privacyStudent,'context'=>'teste','fields'=>'{"observacao":"tentativa"}'])['status']===403,
        'Concessão de consulta não permite gravar rascunho de DVA');
    stopServer($privacyGrantProcess);
    $privacyGrantEnvironment['ACCESS_HEALTH_STAFF_IDS']=' ';
    $privacyGrantEnvironment['ACCESS_CONTACT_STAFF_IDS']=(string)$privacyActor;
    $privacyGrantProcess=startServer($port+4,$privacyGrantEnvironment,$serverOut,$serverError);
    waitForServer($privacyGrantUrl.'/login',$cookieEmployee);
    $privacyContactProfile=request('GET',$privacyGrantUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
    checkHttp($privacyContactProfile['status']===200 && str_contains($privacyContactProfile['body'],'65988881234')
        && !str_contains($privacyContactProfile['body'],'OBSERVACAO_RESTRITA_HTTP'), 'Concessão de contatos não concede saúde');
    checkHttp(request('GET',$privacyGrantUrl.'/aluno?dva=vencida',$cookieEmployee)['status']===403
        && request('GET',$privacyGrantUrl.'/dashboard?dva=vencida',$cookieEmployee)['status']===403,
        'Concessão de contatos não permite inferência por filtro de saúde');
    stopServer($privacyGrantProcess);
    $privacyGrantEnvironment['ACCESS_HEALTH_STAFF_IDS']=(string)$privacyActor;
    $privacyGrantEnvironment['ACCESS_APPROVAL_VALID_UNTIL']='2000-01-01';
    $privacyGrantProcess=startServer($port+4,$privacyGrantEnvironment,$serverOut,$serverError);
    waitForServer($privacyGrantUrl.'/login',$cookieEmployee);
    $privacyExpired=request('GET',$privacyGrantUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
    checkHttp($privacyExpired['status']===200 && !str_contains($privacyExpired['body'],'OBSERVACAO_RESTRITA_HTTP')
        && !str_contains($privacyExpired['body'],'65988881234'), 'Concessão expirada volta ao cadastro mínimo');
    checkHttp(request('GET',$privacyGrantUrl.'/aluno/dva/'.$privacyStudent,$cookieEmployee)['status']===403,
        'Concessão expirada é negada em requisição direta');
} finally { stopServer($privacyGrantProcess); }
$privacyDb=null;
