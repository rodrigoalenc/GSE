<?php

declare(strict_types=1);

// Este ensaio usa apenas o SQLite e as sessões descartáveis de http-smoke.php.
$profileDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$profilePassword = 'Frase perfil ficticio 2026';
$profileLogin = static function (string $email, string $password, string $cookie) use ($baseUrl): array {
    $page = request('GET', $baseUrl . '/login', $cookie);
    return request('POST', $baseUrl . '/login/entrar', $cookie, ['_csrf_token' => csrf($page['body']), 'email' => $email, 'senha' => $password]);
};
foreach (['GET', 'POST'] as $profileMethod) {
    $profileGuest = request($profileMethod, $baseUrl . '/usuario/perfil', $cookieGuest);
    checkHttp($profileGuest['status'] === 302 && str_ends_with($profileGuest['headers']['location'] ?? '', '/login'), 'Visitante bloqueado no perfil próprio: ' . $profileMethod);
}
foreach (['administrador', 'funcionario'] as $profileRole) {
    $profileEmail = 'perfil-' . $profileRole . '@example.test';
    $profileCookie = $tempRoot . DIRECTORY_SEPARATOR . 'perfil-' . $profileRole . '.cookies';
    $profileOtherCookie = $tempRoot . DIRECTORY_SEPARATOR . 'perfil-' . $profileRole . '-outra.cookies';
    $profileInsert = $profileDb->prepare('INSERT INTO usuarios(nome,email,senha,tipo,ativo,deve_alterar_senha,recebe_alertas_dva) VALUES(?,?,?,?,1,0,?)');
    $profileInsert->execute(['Perfil ficticio ' . $profileRole, $profileEmail, password_hash($profilePassword, PASSWORD_DEFAULT), $profileRole, $profileRole === 'administrador' ? 1 : 0]);
    $profileId = (int) $profileDb->lastInsertId();
    checkHttp($profileLogin($profileEmail, $profilePassword, $profileCookie)['status'] === 302, 'Login para perfil próprio: ' . $profileRole);
    $profilePage = request('GET', $baseUrl . '/usuario/perfil?id=1', $profileCookie);
    checkHttp($profilePage['status'] === 200 && str_contains($profilePage['body'], 'value="' . $profileEmail . '"')
        && str_contains($profilePage['body'], 'class="sidebar-profile-link"')
        && !preg_match('/name="(?:id|tipo|ativo|recebe_alertas_dva)"/', $profilePage['body']), 'Perfil expõe somente dados próprios e não oferece privilégios: ' . $profileRole);
    $profileBefore = $profileDb->query('SELECT * FROM usuarios WHERE id = ' . $profileId)->fetch(PDO::FETCH_ASSOC);
    checkHttp(request('POST', $baseUrl . '/usuario/perfil', $profileCookie, ['_csrf_token' => 'invalid', 'nome' => 'Tentativa sem CSRF', 'email' => $profileEmail, 'senha_atual' => $profilePassword])['status'] === 419,
        'CSRF bloqueia alteração de perfil: ' . $profileRole);
    checkHttp($profileBefore === $profileDb->query('SELECT * FROM usuarios WHERE id = ' . $profileId)->fetch(PDO::FETCH_ASSOC), 'CSRF inválido preserva perfil: ' . $profileRole);
    $profileInvalid = request('POST', $baseUrl . '/usuario/perfil', $profileCookie, ['_csrf_token' => csrf($profilePage['body']), 'nome' => '<Nome recusado>', 'email' => $profileEmail, 'senha_atual' => 'Segredo errado perfil 2026']);
    checkHttp($profileInvalid['status'] === 422 && str_contains($profileInvalid['body'], 'value="&lt;Nome recusado&gt;"')
        && str_contains($profileInvalid['body'], 'A senha atual não confere.')
        && !str_contains($profileInvalid['body'], 'Segredo errado perfil 2026')
        && !preg_match('/id="perfil-senha-atual"[^>]*value=/', $profileInvalid['body']), 'Erro de perfil preserva identidade escapada e limpa senha: ' . $profileRole);
    $profileDuplicate = request('POST', $baseUrl . '/usuario/perfil', $profileCookie, ['_csrf_token' => csrf($profileInvalid['body']), 'nome' => 'Nome duplicado', 'email' => 'ADMIN@EXAMPLE.TEST', 'senha_atual' => $profilePassword]);
    checkHttp($profileDuplicate['status'] === 422 && str_contains($profileDuplicate['body'], 'já está em uso')
        && $profileBefore === $profileDb->query('SELECT * FROM usuarios WHERE id = ' . $profileId)->fetch(PDO::FETCH_ASSOC), 'E-mail duplicado é recusado sem mutação: ' . $profileRole);
    $profileOtherBefore = $profileDb->query('SELECT * FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $profileSaved = request('POST', $baseUrl . '/usuario/perfil', $profileCookie, [
        '_csrf_token' => csrf($profileDuplicate['body']), 'nome' => 'Nome proprio ' . $profileRole, 'email' => $profileEmail,
        'senha_atual' => $profilePassword, 'id' => '1', 'usuario_id' => '1', 'tipo' => $profileRole === 'funcionario' ? 'administrador' : 'funcionario',
        'ativo' => '0', 'recebe_alertas_dva' => $profileRole === 'administrador' ? '0' : '1',
    ]);
    $profileAfter = $profileDb->query('SELECT * FROM usuarios WHERE id = ' . $profileId)->fetch(PDO::FETCH_ASSOC);
    $profileExpected = $profileBefore;
    $profileExpected['nome'] = 'Nome proprio ' . $profileRole;
    $profileExpected['atualizado_em'] = $profileAfter['atualizado_em'];
    checkHttp($profileSaved['status'] === 302 && str_ends_with($profileSaved['headers']['location'] ?? '', '/usuario/perfil')
        && $profileExpected === $profileAfter
        && $profileOtherBefore === $profileDb->query('SELECT * FROM usuarios WHERE id = 1')->fetch(PDO::FETCH_ASSOC), 'Payload de ID e privilégios é ignorado; somente nome próprio muda: ' . $profileRole);
    $profilePage = request('GET', $baseUrl . '/usuario/perfil', $profileCookie);
    checkHttp($profilePage['status'] === 200 && str_contains($profilePage['body'], 'Nome proprio ' . $profileRole), 'Nome atualizado mantém sessão e rodapé: ' . $profileRole);
    $profileAudit = $profileDb->query("SELECT actor_user_id,target_user_id,resource_id FROM security_audit WHERE action='user.profile_updated' AND actor_user_id={$profileId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    checkHttp($profileAudit === ['actor_user_id' => $profileId, 'target_user_id' => $profileId, 'resource_id' => $profileId], 'Auditoria de perfil identifica somente a própria conta: ' . $profileRole);
    if ($profileRole === 'funcionario') {
        checkHttp(request('GET', $baseUrl . '/usuario/editar/1', $profileCookie)['status'] === 403, 'Perfil próprio não amplia gestão administrativa do funcionário');
    }
    $profileDb->exec("CREATE TRIGGER fail_http_profile_audit BEFORE INSERT ON security_audit WHEN NEW.action='user.profile_updated' BEGIN SELECT RAISE(ABORT,'forced'); END");
    $profileAuditFailed = request('POST', $baseUrl . '/usuario/perfil', $profileCookie, ['_csrf_token' => csrf($profilePage['body']), 'nome' => 'Nome para rollback', 'email' => 'rollback-' . $profileRole . '@example.test', 'senha_atual' => $profilePassword]);
    checkHttp($profileAuditFailed['status'] === 422 && !preg_match('/PDOException|forced|Stack trace/', $profileAuditFailed['body'])
        && $profileAfter === $profileDb->query('SELECT * FROM usuarios WHERE id = ' . $profileId)->fetch(PDO::FETCH_ASSOC), 'Falha de auditoria desfaz identidade e versão sem detalhes técnicos: ' . $profileRole);
    $profileDb->exec('DROP TRIGGER fail_http_profile_audit');
    checkHttp($profileLogin($profileEmail, $profilePassword, $profileOtherCookie)['status'] === 302, 'Outra sessão da mesma conta criada para revogação: ' . $profileRole);
    $profileChangedEmail = 'perfil-atualizado-' . $profileRole . '@example.test';
    $profileEmailSaved = request('POST', $baseUrl . '/usuario/perfil', $profileCookie, ['_csrf_token' => csrf($profileAuditFailed['body']), 'nome' => 'Nome proprio ' . $profileRole, 'email' => $profileChangedEmail, 'senha_atual' => $profilePassword]);
    checkHttp($profileEmailSaved['status'] === 302 && str_ends_with($profileEmailSaved['headers']['location'] ?? '', '/login')
        && (int) $profileDb->query('SELECT session_version FROM usuarios WHERE id = ' . $profileId)->fetchColumn() === (int) $profileBefore['session_version'] + 1,
        'Novo e-mail encerra sessão e incrementa versão: ' . $profileRole);
    foreach ([$profileCookie, $profileOtherCookie] as $profileRevokedCookie) {
        checkHttp(request('GET', $baseUrl . '/usuario/perfil', $profileRevokedCookie)['status'] === 302, 'Sessão anterior perde acesso após mudança de e-mail: ' . $profileRole);
    }
    $profileOldLogin = $profileLogin($profileEmail, $profilePassword, $profileCookie);
    checkHttp($profileOldLogin['status'] === 422, 'E-mail anterior deixa de autenticar: ' . $profileRole);
    checkHttp($profileLogin($profileChangedEmail, $profilePassword, $profileCookie)['status'] === 302, 'Novo e-mail autentica com senha preservada: ' . $profileRole);
    $profileDb->exec('UPDATE usuarios SET deve_alterar_senha=1 WHERE id = ' . $profileId);
    $profileTemporary = request('GET', $baseUrl . '/usuario/perfil', $profileCookie);
    checkHttp($profileTemporary['status'] === 302 && str_ends_with($profileTemporary['headers']['location'] ?? '', '/senha/alterar'), 'Troca obrigatória continua antes do perfil próprio: ' . $profileRole);
}
$profileInsert = null;
$profileDb = null;
