<?php
declare(strict_types=1);

// Included by the smoke runner: only its disposable database, keyring and cookies.
$authAuditDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$authAuditDb->exec('PRAGMA busy_timeout=5000');
$authAuditPassword = 'Frase propria auditoria 2027';
$authAuditId = test_insert_user($authAuditDb, ['nome'=>'Administrador auditoria HTTP','email'=>'auditoria.auth@example.test',
    'senha'=>password_hash($authAuditPassword,PASSWORD_ARGON2ID),'tipo'=>'administrador','ativo'=>1,'deve_alterar_senha'=>0]);
$authAuditA = $tempRoot . DIRECTORY_SEPARATOR . 'auth-a.cookies';
$authAuditB = $tempRoot . DIRECTORY_SEPARATOR . 'auth-b.cookies';
$authAuditGuest = $tempRoot . DIRECTORY_SEPARATOR . 'auth-guest.cookies';

$authLogin = static function (string $cookie, string $email) use ($baseUrl,$authAuditPassword): void {
    $page=request('GET',$baseUrl.'/login',$cookie);
    $result=request('POST',$baseUrl.'/login/entrar',$cookie,['_csrf_token'=>csrf($page['body']),'email'=>$email,'senha'=>$authAuditPassword]);
    checkHttp($result['status']===302,'Conta ficticia autenticada para regressao da identidade');
};
foreach ([$authAuditA,$authAuditB] as $cookie) { $authLogin($cookie,'auditoria.auth@example.test'); }
$authEditUrl=$baseUrl.'/usuario/editar/'.$authAuditId;
$authEdit=request('GET',$authEditUrl,$authAuditA);
$authFields=['_csrf_token'=>csrf($authEdit['body']),'nome'=>'Administrador auditoria HTTP','email'=>'auditoria.novo@example.test',
    'tipo'=>'administrador','senha'=>'','confirmar_senha'=>''];
$authRejected=request('POST',$authEditUrl,$authAuditA,$authFields);
checkHttp($authRejected['status']===422 && str_contains($authRejected['body'],'senha atual corretamente'),
    'Edicao administrativa do proprio email exige senha atual');
checkHttp((int)$authAuditDb->query('SELECT session_version FROM usuarios WHERE id='.$authAuditId)->fetchColumn()===1,
    'Tentativa sem reautenticacao preserva versao da conta');
$authFields['_csrf_token']=csrf($authRejected['body']);
$authFields['senha_atual']=$authAuditPassword;
$authChanged=request('POST',$authEditUrl,$authAuditA,$authFields);
checkHttp($authChanged['status']===302 && str_contains($authChanged['headers']['location']??'','/login'),
    'Alteracao do proprio email pela administracao encerra sessao atual');
checkHttp(request('GET',$baseUrl.'/dashboard',$authAuditB)['status']===302,
    'Outra sessao antiga perde acesso apos email administrativo alterado');
checkHttp((int)$authAuditDb->query('SELECT session_version FROM usuarios WHERE id='.$authAuditId)->fetchColumn()===2,
    'Alteracao administrativa do email incrementa versao exatamente uma vez');

// Revocation renews CSRF too: a stale POST is refused, then a fresh form can log in.
$authLogin($authAuditB,'auditoria.novo@example.test');
$authActivePage=request('GET',$baseUrl.'/dashboard',$authAuditB);
$authAuditDb->exec('UPDATE usuarios SET session_version=session_version+1 WHERE id='.$authAuditId);
$authRecovery=request('POST',$baseUrl.'/login/entrar',$authAuditB,['_csrf_token'=>csrf($authActivePage['body']),
    'email'=>'auditoria.novo@example.test','senha'=>$authAuditPassword]);
checkHttp($authRecovery['status']===419,
    'POST com cookie revogado preserva CSRF e pede atualizar o formulário sem erro de transação');
checkHttp(request('GET',$baseUrl.'/dashboard',$authAuditB)['status']===302,
    'POST antigo recusado não cria sessão autenticada');
$authLogin($authAuditB,'auditoria.novo@example.test');
checkHttp(request('GET',$baseUrl.'/dashboard',$authAuditB)['status']===200,
    'Nova autenticação com formulário atualizado recupera acesso normal');

$authLogin($authAuditA,'auditoria.novo@example.test');
$authProfile=request('GET',$baseUrl.'/usuario/perfil',$authAuditA);
$authChanged=request('POST',$baseUrl.'/usuario/perfil',$authAuditA,['_csrf_token'=>csrf($authProfile['body']),
    'nome'=>'Administrador auditoria HTTP','email'=>'auditoria.perfil@example.test','senha_atual'=>$authAuditPassword]);
checkHttp($authChanged['status']===302 && str_contains($authChanged['headers']['location']??'','/login'),
    'Alteracao do email no perfil encerra sessao atual');
checkHttp(request('GET',$baseUrl.'/dashboard',$authAuditB)['status']===302,
    'Outra sessao antiga perde acesso apos email alterado no perfil');

$authGuestPage=request('GET',$baseUrl.'/login',$authAuditGuest);
$authGuestFields=['_csrf_token'=>csrf($authGuestPage['body']),'email'=>'inexistente.orcamento@example.test','senha'=>'Entrada incorreta ficticia'];
for ($i=0;$i<5;$i++) {
    checkHttp(request('POST',$baseUrl.'/login/entrar',$authAuditGuest,$authGuestFields)['status']===422,
        'Tentativa admitida ainda usa resposta generica');
}
$authBefore=$authAuditDb->query('SELECT * FROM login_attempts ORDER BY key_type,key_hash')->fetchAll();
$authEventCount=(int)$authAuditDb->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
for ($i=0;$i<5;$i++) {
    $authLimited=request('POST',$baseUrl.'/login/entrar',$authAuditGuest,$authGuestFields);
    $authRetry=(int)($authLimited['headers']['retry-after']??0);
    checkHttp($authLimited['status']===429 && $authRetry>0 && $authRetry<=60,
        'Orcamento excedido retorna HTTP429 e Retry-After curto');
}
checkHttp($authBefore===$authAuditDb->query('SELECT * FROM login_attempts ORDER BY key_type,key_hash')->fetchAll()
    && $authEventCount===(int)$authAuditDb->query('SELECT COUNT(*) FROM security_audit')->fetchColumn(),
    'Recusas bloqueadas nao escrevem contadores nem auditoria por requisicao');
$authAuditDb=null;
