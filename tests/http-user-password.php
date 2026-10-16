<?php

declare(strict_types=1);

// Only the disposable SQLite database and sessions created by http-smoke.php.
$ownPasswordDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ownPasswordOld = 'Frase inicial ficticia 2026';
$ownPasswordNew = 'Frase definitiva ficticia 2027';
$ownPasswordEmail = 'senha-propria@example.test';
$ownPasswordId = test_insert_user($ownPasswordDb, ['nome' => 'Administrador senha propria', 'email' => $ownPasswordEmail,
    'senha' => password_hash($ownPasswordOld, PASSWORD_DEFAULT), 'tipo' => 'administrador', 'ativo' => 1, 'deve_alterar_senha' => 0]);
$ownPasswordCookie = $tempRoot . DIRECTORY_SEPARATOR . 'senha-propria.cookies';
$ownPasswordOtherCookie = $tempRoot . DIRECTORY_SEPARATOR . 'senha-propria-outra.cookies';
$ownPasswordLogin = static function (string $email, string $password, string $cookie) use ($baseUrl): array {
    $page = request('GET', $baseUrl . '/login', $cookie);
    return request('POST', $baseUrl . '/login/entrar', $cookie, ['_csrf_token' => csrf($page['body']), 'email' => $email, 'senha' => $password]);
};
$ownPasswordRow = static function () use ($ownPasswordDb, $ownPasswordId): array {
    return $ownPasswordDb->query('SELECT * FROM usuarios WHERE id=' . $ownPasswordId)->fetch(PDO::FETCH_ASSOC);
};
checkHttp(str_ends_with($ownPasswordLogin($ownPasswordEmail, $ownPasswordOld, $ownPasswordCookie)['headers']['location'] ?? '', '/dashboard'), 'Administrador de teste entra antes de editar a própria senha');
$ownPasswordLogin($ownPasswordEmail, $ownPasswordOld, $ownPasswordOtherCookie);
$ownPasswordPage = request('GET', $baseUrl . '/usuario/editar/' . $ownPasswordId, $ownPasswordCookie);
checkHttp($ownPasswordPage['status'] === 200 && str_contains($ownPasswordPage['body'], 'Alterar minha senha')
    && str_contains($ownPasswordPage['body'], 'name="senha_atual"') && str_contains($ownPasswordPage['body'], 'Sua nova senha será definitiva'),
    'Editor da própria conta explica a troca definitiva e pede a senha atual');
$ownPasswordBefore = $ownPasswordRow();
$ownPasswordData = ['_csrf_token' => csrf($ownPasswordPage['body']), 'nome' => 'Administrador senha propria', 'email' => $ownPasswordEmail,
    'tipo' => 'administrador', 'senha' => $ownPasswordNew, 'confirmar_senha' => $ownPasswordNew];
checkHttp(request('POST', $baseUrl . '/usuario/editar/' . $ownPasswordId, $ownPasswordCookie,
    array_replace($ownPasswordData, ['_csrf_token' => 'invalid', 'senha_atual' => $ownPasswordOld]))['status'] === 419
    && $ownPasswordBefore === $ownPasswordRow(), 'CSRF protege alteração da própria senha');
foreach (['', 'Senha ficticia incorreta'] as $currentPassword) {
    $response = request('POST', $baseUrl . '/usuario/editar/' . $ownPasswordId, $ownPasswordCookie,
        $ownPasswordData + ['senha_atual' => $currentPassword]);
    checkHttp($response['status'] === 422 && str_contains($response['body'], 'informe a senha atual corretamente')
        && $ownPasswordBefore === $ownPasswordRow(), 'Senha atual ausente ou incorreta impede qualquer alteração');
    checkHttp(!str_contains($response['body'], $ownPasswordNew) && !str_contains($response['body'], $ownPasswordOld)
        && !str_contains($response['body'], 'Senha ficticia incorreta'), 'Resposta de erro não devolve as senhas preenchidas');
}
$ownPasswordReuse = request('POST', $baseUrl . '/usuario/editar/' . $ownPasswordId, $ownPasswordCookie,
    array_replace($ownPasswordData, ['senha' => $ownPasswordOld, 'confirmar_senha' => $ownPasswordOld, 'senha_atual' => $ownPasswordOld]));
checkHttp($ownPasswordReuse['status'] === 422 && str_contains($ownPasswordReuse['body'], 'diferente da senha atual')
    && $ownPasswordBefore === $ownPasswordRow(), 'Editor da própria conta rejeita reutilização da senha');
$ownPasswordChanged = request('POST', $baseUrl . '/usuario/editar/' . $ownPasswordId, $ownPasswordCookie,
    $ownPasswordData + ['senha_atual' => $ownPasswordOld]);
checkHttp($ownPasswordChanged['status'] === 302 && str_ends_with($ownPasswordChanged['headers']['location'] ?? '', '/login'), 'Troca própria encerra a sessão e pede um novo login');
$ownPasswordAfter = $ownPasswordRow();
checkHttp((int) $ownPasswordAfter['deve_alterar_senha'] === 0 && $ownPasswordAfter['password_changed_at'] !== null
    && (int) $ownPasswordAfter['session_version'] === (int) $ownPasswordBefore['session_version'] + 1
    && password_verify($ownPasswordNew, $ownPasswordAfter['senha']), 'Nova senha própria é definitiva e invalida as sessões anteriores');
$ownPasswordAudit = $ownPasswordDb->query("SELECT action FROM security_audit WHERE actor_user_id=$ownPasswordId AND action IN ('password.changed','password.reset') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
checkHttp($ownPasswordAudit === ['password.changed'], 'Troca própria é auditada como alteração de senha, sem redefinição temporária');
checkHttp(str_ends_with(request('GET', $baseUrl . '/dashboard', $ownPasswordOtherCookie)['headers']['location'] ?? '', '/login'), 'Sessão anterior em outro navegador também é encerrada');
$ownPasswordRelogin = $ownPasswordLogin($ownPasswordEmail, $ownPasswordNew, $ownPasswordCookie);
checkHttp(str_ends_with($ownPasswordRelogin['headers']['location'] ?? '', '/dashboard')
    && request('GET', $baseUrl . '/dashboard', $ownPasswordCookie)['status'] === 200, 'Novo login com a senha definitiva abre o painel sem exigir outra troca');
$otherPasswordEmail = 'senha-outra@example.test';
$otherPasswordId = test_insert_user($ownPasswordDb, ['nome' => 'Funcionario senha alheia', 'email' => $otherPasswordEmail,
    'senha' => password_hash($ownPasswordOld, PASSWORD_DEFAULT), 'tipo' => 'funcionario', 'ativo' => 1, 'deve_alterar_senha' => 0]);
$otherPasswordPage = request('GET', $baseUrl . '/usuario/editar/' . $otherPasswordId, $ownPasswordCookie);
checkHttp(str_contains($otherPasswordPage['body'], 'Redefinir senha temporária') && !str_contains($otherPasswordPage['body'], 'name="senha_atual"'),
    'Editor de outra conta explica a senha temporária');
$otherPasswordReset = request('POST', $baseUrl . '/usuario/editar/' . $otherPasswordId, $ownPasswordCookie, [
    '_csrf_token' => csrf($otherPasswordPage['body']), 'nome' => 'Funcionario senha alheia', 'email' => $otherPasswordEmail,
    'tipo' => 'funcionario', 'senha' => $ownPasswordNew, 'confirmar_senha' => $ownPasswordNew, 'actorId' => (string) $otherPasswordId,
]);
checkHttp($otherPasswordReset['status'] === 302 && (int) $ownPasswordDb->query('SELECT deve_alterar_senha FROM usuarios WHERE id=' . $otherPasswordId)->fetchColumn() === 1,
    'Redefinir senha de outra pessoa continua exigindo troca no próximo acesso');
$otherPasswordCookie = $tempRoot . DIRECTORY_SEPARATOR . 'senha-outra.cookies';
checkHttp(str_ends_with($ownPasswordLogin($otherPasswordEmail, $ownPasswordNew, $otherPasswordCookie)['headers']['location'] ?? '', '/senha/alterar'),
    'Somente a conta cuja senha foi redefinida recebe a troca obrigatória');
$ownPasswordDashboard = request('GET', $baseUrl . '/dashboard', $ownPasswordCookie);
request('POST', $baseUrl . '/login/sair', $ownPasswordCookie, ['_csrf_token' => csrf($ownPasswordDashboard['body'])]);
checkHttp(str_ends_with($ownPasswordLogin($ownPasswordEmail, $ownPasswordNew, $ownPasswordCookie)['headers']['location'] ?? '', '/dashboard'),
    'Logins posteriores também não exigem uma nova troca da própria senha');
unset($ownPasswordRow);
$ownPasswordDb = null;
