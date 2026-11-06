<?php
declare(strict_types=1);
// Included by http-smoke.php; only its disposable database, accounts and server.
$privacyDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$privacyDb->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,telefone_aluno,telefone_responsavel) VALUES(?,?,?,?,?)')
    ->execute(['Aluno política HTTP','aluno politica http','2010-05-10','65988881234','65988884321']);
$privacyStudent = (int)$privacyDb->lastInsertId();
$privacyDb->prepare('INSERT INTO dvas(id_aluno,data_vencimento,observacao) VALUES(?,?,?)')
    ->execute([$privacyStudent,'2037-12-31','OBSERVACAO_DVA_HTTP']);
$privacyProfile = request('GET',$baseUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
checkHttp($privacyProfile['status']===200 && str_contains($privacyProfile['body'],'Aluno política HTTP')
    && str_contains($privacyProfile['body'],'OBSERVACAO_DVA_HTTP') && str_contains($privacyProfile['body'],'Histórico de DVAs'),
    'Funcionário consulta a DVA e o histórico no perfil do aluno');
foreach (['/dva?dva=vigente','/aluno?dva=vigente','/dashboard?dva=vigente','/aluno/perfil/'.$privacyStudent] as $privacyPath) {
    $privacyPage=request('GET',$baseUrl.$privacyPath,$cookieEmployee);
    checkHttp($privacyPage['status']===200 && !preg_match('/65988881234|65988884321|Contatos Registrados/',$privacyPage['body']),
        'Consulta de DVA mantém os contatos protegidos: '.$privacyPath);
}
checkHttp(preg_match('~href="[^"]*/dva"[^>]*>\s*<span class="sidebar-icon">.*?</span><span class="sidebar-label">DVAs</span>~s',$privacyProfile['body'])===1
    && !preg_match('/class="sidebar-label">(?:Relatórios|Usuários|Auditoria|Backups)</',$privacyProfile['body']),
    'Menu do funcionário exibe DVAs e preserva as restrições de relatórios e administração');
foreach (['/aluno/criar','/aluno/editar/'.$privacyStudent,'/relatorio','/relatorio/csv','/relatorio/pdf'] as $privacyPath) {
    checkHttp(request('GET',$baseUrl.$privacyPath,$cookieEmployee)['status']===403, 'Requisição direta sem autorização específica retorna 403: '.$privacyPath);
}
$privacyDvaUrl=$baseUrl.'/aluno/dva/'.$privacyStudent;
$privacyDva=request('GET',$privacyDvaUrl,$cookieEmployee);
checkHttp($privacyDva['status']===200 && str_contains($privacyDva['body'],'Confirmar renovação')
    && str_contains($privacyDva['body'],'name="dva_esperada"') && str_contains($privacyDva['body'],'name="_request_token"'),
    'Funcionário acessa o formulário de renovação com versão esperada e idempotência');
$privacyCsrf=csrf($privacyDva['body']);
$privacyDvaFields=[
    'dva_esperada'=>hiddenIntegerValue($privacyDva['body'],'dva_esperada',true),
    '_request_token'=>hiddenValue($privacyDva['body'],'_request_token',32),
    'data_vencimento'=>'2038-12-31','observacao'=>'RENOVACAO_FUNCIONARIO_HTTP',
];
checkHttp(request('GET',$baseUrl.'/dva',$cookieGuest)['status']===302
    && request('POST',$privacyDvaUrl,$cookieGuest,$privacyDvaFields)['status']===302
    && request('POST',$privacyDvaUrl,$cookieEmployee,$privacyDvaFields)['status']===419,
    'Consulta e gravação de DVA exigem sessão e a gravação exige CSRF');
$privacyDraftTarget='aluno/dva/'.$privacyStudent;
$privacyDraftUrl=$baseUrl.'/formulario/rascunho?form='.$privacyDraftTarget;
$privacyDraft=request('GET',$privacyDraftUrl,$cookieEmployee);
checkHttp($privacyDraft['status']===200,'Funcionário abre rascunho de DVA');
$privacyDraftContext=json_decode($privacyDraft['body'],true,512,JSON_THROW_ON_ERROR)['context'];
checkHttp(request('POST',$baseUrl.'/formulario/rascunho',$cookieEmployee,[
    '_csrf_token'=>$privacyCsrf,'form'=>$privacyDraftTarget,'context'=>$privacyDraftContext,
    'fields'=>json_encode($privacyDvaFields,JSON_THROW_ON_ERROR),
])['status']===200 && str_contains(request('GET',$privacyDraftUrl,$cookieEmployee)['body'],'RENOVACAO_FUNCIONARIO_HTTP'),
    'Funcionário grava e recupera rascunho de DVA');
checkHttp(request('POST',$privacyDvaUrl,$cookieEmployee,['_csrf_token'=>$privacyCsrf]+$privacyDvaFields)['status']===302,
    'Funcionário renova DVA sem concessão de edição cadastral');
$privacyHistory=$privacyDb->query('SELECT id,ativo,id_usuario_registro FROM dvas WHERE id_aluno='.$privacyStudent.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
checkHttp(count($privacyHistory)===2 && (int)$privacyHistory[0]['ativo']===0 && (int)$privacyHistory[1]['ativo']===1,
    'Renovação do funcionário preserva o histórico de DVA');
$privacyDvaAudit=$privacyDb->prepare("SELECT actor_user_id FROM security_audit WHERE action='dva.renewed' AND resource_type='dva' AND resource_id=? AND result='success'");
$privacyDvaAudit->execute([(int)$privacyHistory[1]['id']]);
$privacyActorQuery=$privacyDb->prepare('SELECT id FROM usuarios WHERE email_lookup=?');
$privacyActorQuery->execute([DataProtection::emailIndex('employee@example.test')]);
$privacyActor=(int)$privacyActorQuery->fetchColumn();
checkHttp($privacyActor>0 && (int)$privacyDvaAudit->fetchColumn()===$privacyActor && (int)$privacyHistory[1]['id_usuario_registro']===$privacyActor,
    'Renovação de DVA registra o funcionário responsável na auditoria');
$privacyDvaAudit->closeCursor();
$privacyActorQuery->closeCursor();

$privacyConcurrentA=request('GET',$privacyDvaUrl,$cookieEmployee);
$privacyConcurrentB=request('GET',$privacyDvaUrl,$cookieEmployee);
$privacyConcurrentFields=static fn(array $page,string $date,string $note): array => [
    '_csrf_token'=>csrf($page['body']),
    'dva_esperada'=>hiddenIntegerValue($page['body'],'dva_esperada',true),
    '_request_token'=>hiddenValue($page['body'],'_request_token',32),
    'data_vencimento'=>$date,
    'observacao'=>$note,
];
$privacyFirstConcurrent=request('POST',$privacyDvaUrl,$cookieEmployee,$privacyConcurrentFields($privacyConcurrentA,'2039-12-31','PRIMEIRA_SESSAO_DVA'));
$privacyStaleConcurrent=request('POST',$privacyDvaUrl,$cookieEmployee,$privacyConcurrentFields($privacyConcurrentB,'2040-12-31','FORMULARIO_ANTIGO_DVA'));
checkHttp($privacyFirstConcurrent['status']===302 && $privacyStaleConcurrent['status']===409
    && str_contains($privacyStaleConcurrent['body'],'A DVA mudou enquanto este formulário estava aberto')
    && str_contains($privacyStaleConcurrent['body'],'FORMULARIO_ANTIGO_DVA')
    && str_contains($privacyStaleConcurrent['body'],'PRIMEIRA_SESSAO_DVA'),
    'Duas sessões exigem revisão consciente antes de substituir a DVA mais recente');
$privacyConfirmFields=[
    '_csrf_token'=>csrf($privacyStaleConcurrent['body']),
    'dva_esperada'=>hiddenIntegerValue($privacyStaleConcurrent['body'],'dva_esperada',true),
    '_request_token'=>hiddenValue($privacyStaleConcurrent['body'],'_request_token',32),
    '_dva_conflict_token'=>hiddenValue($privacyStaleConcurrent['body'],'_dva_conflict_token',32),
    'confirmar_revisao'=>'1',
    'data_vencimento'=>'2040-12-31',
    'observacao'=>'FORMULARIO_ANTIGO_DVA',
];
$privacyConfirmWithoutReviewToken=$privacyConfirmFields;
unset($privacyConfirmWithoutReviewToken['_dva_conflict_token']);
checkHttp(request('POST',$privacyDvaUrl,$cookieEmployee,$privacyConfirmWithoutReviewToken)['status']===409,
    'Confirmação concorrente não pode omitir o token da revisão apresentada');
checkHttp(request('POST',$privacyDvaUrl,$cookieEmployee,$privacyConfirmFields)['status']===302,
    'Funcionário confirma a renovação somente após revisar a DVA concorrente');
checkHttp((int)$privacyDb->query('SELECT COUNT(*) FROM dvas WHERE id_aluno='.$privacyStudent)->fetchColumn()===4,
    'Conflito recusado não cria DVA e confirmação posterior cria uma única versão');
checkHttp(request('POST',$baseUrl.'/aluno/status/'.$privacyStudent,$cookieEmployee,['_csrf_token'=>$privacyCsrf,'ativo'=>'0'])['status']===403,
    'Acesso a DVA não permite inativar o aluno');
checkHttp(request('POST',$baseUrl.'/passivo/exportar',$cookieEmployee,['_csrf_token'=>$privacyCsrf,'caixa'=>'CX-HTTP'])['status']===403,
    'POST direto não permite exportação ampla de funcionário');
$privacyAdmin=request('GET',$baseUrl.'/aluno/perfil/'.$privacyStudent,$cookieAdmin);
checkHttp($privacyAdmin['status']===200 && str_contains($privacyAdmin['body'],'RENOVACAO_FUNCIONARIO_HTTP')
    && str_contains($privacyAdmin['body'],'OBSERVACAO_DVA_HTTP'), 'Administrador consulta a DVA renovada e seu histórico');
$privacyGrantProcess=null;
$privacyGrantUrl='http://127.0.0.1:'.($port+4);
$privacyGrantEnvironment=array_merge($baseEnvironment,[
    'ACCESS_HEALTH_STAFF_IDS'=>' ',
    'ACCESS_CONTACT_STAFF_IDS'=>(string)$privacyActor, 'ACCESS_MANAGE_STAFF_IDS'=>' ', 'ACCESS_EXPORT_STAFF_IDS'=>' ',
    'ACCESS_APPROVAL_REFERENCE'=>'REGRESSAO SINTETICA SEM APROVACAO INSTITUCIONAL',
    'ACCESS_APPROVAL_VALID_UNTIL'=>gmdate('Y-m-d',time()+86400),
]);
try {
    $privacyGrantProcess=startServer($port+4,$privacyGrantEnvironment,$serverOut,$serverError);
    waitForServer($privacyGrantUrl.'/login',$cookieEmployee);
    $privacyContactProfile=request('GET',$privacyGrantUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
    checkHttp($privacyContactProfile['status']===200 && str_contains($privacyContactProfile['body'],'65988881234')
        && str_contains($privacyContactProfile['body'],'RENOVACAO_FUNCIONARIO_HTTP'), 'Concessão de contatos é independente do acesso às DVAs');
    stopServer($privacyGrantProcess);
    $privacyGrantEnvironment['ACCESS_APPROVAL_VALID_UNTIL']='2000-01-01';
    $privacyGrantProcess=startServer($port+4,$privacyGrantEnvironment,$serverOut,$serverError);
    waitForServer($privacyGrantUrl.'/login',$cookieEmployee);
    $privacyExpired=request('GET',$privacyGrantUrl.'/aluno/perfil/'.$privacyStudent,$cookieEmployee);
    checkHttp($privacyExpired['status']===200 && str_contains($privacyExpired['body'],'RENOVACAO_FUNCIONARIO_HTTP')
        && !str_contains($privacyExpired['body'],'65988881234'), 'Concessão expirada revoga contatos e mantém DVA');
} finally { stopServer($privacyGrantProcess); }
$privacyDb=null;
