<?php

declare(strict_types=1);

// Use the authenticated, disposable administrator; never touch institutional data.
$hardeningDb = new PDO('sqlite:' . $database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$hardeningBefore = $hardeningDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC);
checkHttp(request('GET',$baseUrl.'/?url%5B%5D=usuario',$cookieAdmin)['status']===400,
    'Rota enviada como array retorna erro controlado');
$malformedForm=request('GET',$baseUrl.'/usuario/criar',$cookieAdmin);
$usersBefore=(int)$hardeningDb->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$malformedRole=request('POST',$baseUrl.'/usuario/criar',$cookieAdmin,['_csrf_token'=>csrf($malformedForm['body']),
    'nome'=>'Pessoa ficticia tipo invalido','email'=>'invalid-type@example.test','tipo'=>['administrador'],
    'senha'=>$permanentPassword,'confirmar_senha'=>$permanentPassword]);
checkHttp($malformedRole['status']===400 && $usersBefore===(int)$hardeningDb->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
    'Perfil enviado como array é recusado sem HTTP 500 nem cadastro');
checkHttp(request('GET',$baseUrl.'/passivo?ordem%5B%5D=nome',$cookieAdmin)['status']===400,
    'Ordenação enviada como array retorna erro controlado');
foreach ([null,[],['unexpected'=>'token'],42] as $csrfValue) {
    $response = request('POST',$baseUrl.'/usuario/perfil',$cookieAdmin,
        ['_csrf_token'=>$csrfValue,'nome'=>'Sem autorizacao CSRF','email'=>'fake@example.test','senha_atual'=>'fake']);
    checkHttp($response['status']===419,'CSRF com tipo inesperado retorna 419 sem mutação');
    checkHttp($hardeningBefore===$hardeningDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC),'Conta preservada após CSRF malformado');
}
$hardeningPage=request('GET',$baseUrl.'/usuario/perfil',$cookieAdmin);
$bucket=hash_hmac('sha256','CONFIRM:user:1:identity',DataProtection::keyring()['index']);
$hardeningDb->prepare('INSERT INTO action_budgets(bucket,attempts,expires) VALUES(?,10,?) ON CONFLICT(bucket) DO UPDATE SET attempts=10,expires=excluded.expires')
    ->execute([$bucket,time()+120]);
$limited=request('POST',$baseUrl.'/usuario/perfil',$cookieAdmin,['_csrf_token'=>csrf($hardeningPage['body']),
    'nome'=>'Perfil ficticio bloqueado','email'=>'limited@example.test','senha_atual'=>$permanentPassword]);
checkHttp($limited['status']===429 && (int)($limited['headers']['retry-after'] ?? 0)>0,'Confirmação limitada retorna HTTP 429 e Retry-After');
checkHttp($hardeningBefore===$hardeningDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC),'Limite de confirmação preserva conta');
$limitedAdminPage=request('GET',$baseUrl.'/usuario/editar/1',$cookieAdmin);
$limitedAdmin=DataProtection::user($hardeningBefore);
$limitedAdminResult=request('POST',$baseUrl.'/usuario/editar/1',$cookieAdmin,['_csrf_token'=>csrf($limitedAdminPage['body']),
    'nome'=>$limitedAdmin['nome'],'email'=>'limited-admin@example.test','tipo'=>'administrador','senha'=>'','confirmar_senha'=>'','senha_atual'=>$permanentPassword]);
checkHttp($limitedAdminResult['status']===429 && (int)($limitedAdminResult['headers']['retry-after']??0)>0,
    'Mesmo orçamento impede contornar confirmação pela edição administrativa do próprio e-mail');
$limitedPasswordPage=request('GET',$baseUrl.'/senha/alterar',$cookieAdmin);
$limitedPasswordResult=request('POST',$baseUrl.'/senha/alterar',$cookieAdmin,['_csrf_token'=>csrf($limitedPasswordPage['body']),
    'senha_atual'=>$permanentPassword,'nova_senha'=>'Outra frase sequencial 2030','confirmar_senha'=>'Outra frase sequencial 2030']);
checkHttp($limitedPasswordResult['status']===429 && (int)($limitedPasswordResult['headers']['retry-after']??0)>0,
    'Mesmo orçamento impede contornar confirmação pela troca de senha');
checkHttp($hardeningBefore===$hardeningDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC),
    'Trocar entre perfil, edição administrativa e senha não permite mutação sob limite');
$hardeningDb->prepare('DELETE FROM action_budgets WHERE bucket=?')->execute([$bucket]);
$receiptPage=request('GET',$baseUrl.'/contrato/criar',$cookieAdmin);
$receiptData=['_csrf_token'=>csrf($receiptPage['body']),'_request_token'=>hiddenValue($receiptPage['body'],'_request_token',32),
    'titulo'=>'Contrato HTTP replay ficticio','valor'=>'100,00','fornecedor'=>''];
$created=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,$receiptData);
$count=(int)$hardeningDb->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
$audits=(int)$hardeningDb->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
$replayed=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,$receiptData);
checkHttp($created['status']===302 && $replayed['status']===302 && $created['headers']['location']===$replayed['headers']['location']
    && $count===(int)$hardeningDb->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()
    && $audits===(int)$hardeningDb->query('SELECT COUNT(*) FROM security_audit')->fetchColumn(),'Replay HTTP sem JavaScript preserva contrato e auditoria únicos');
$mismatch=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,[...$receiptData,'titulo'=>'Conteudo divergente ficticio']);
checkHttp($mismatch['status']===302 && str_ends_with($mismatch['headers']['location'] ?? '', '/contrato/criar')
    && $count===(int)$hardeningDb->query('SELECT COUNT(*) FROM pedidos')->fetchColumn(),'Reutilização HTTP da chave com outro conteúdo é recusada');
foreach (['.gse-exposure-'.bin2hex(random_bytes(8)), 'gse-exposure-'.bin2hex(random_bytes(8)).'.sqlite'] as $privateName) {
    $privatePath=dirname(__DIR__).'/public/'.$privateName;
    $privateHandle=fopen($privatePath,'xb');
    if ($privateHandle===false) { throw new RuntimeException('Fixture estática indisponível.'); }
    fwrite($privateHandle,'SYNTHETIC_PRIVATE_STATIC_VALUE'); fclose($privateHandle);
    try {
        $response=request('GET',$baseUrl.'/'.$privateName,$cookieGuest);
        checkHttp($response['status']===404 && !str_contains($response['body'],'SYNTHETIC_PRIVATE_STATIC_VALUE'),
            'Servidor local bloqueia arquivo sensível estático mesmo quando ele existe');
    } finally { unlink($privatePath); }
}
$hardeningDb=null;
$corsResponse=request('GET',$baseUrl.'/login',$cookieGuest,[],['Origin: https://other.example.test']);
checkHttp(!isset($corsResponse['headers']['access-control-allow-origin']) && !isset($corsResponse['headers']['access-control-allow-credentials']),
    'Aplicação local mantém CORS fechado para origem externa');
