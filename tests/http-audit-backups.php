<?php

declare(strict_types=1);

// Todos os dados, snapshots e sessões pertencem ao ambiente descartável do smoke.
$backupDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$backupDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'backups';
$backupFiles = static fn (): array => glob($backupDirectory . DIRECTORY_SEPARATOR . '*.db') ?: [];
$missingBackup = 'escola_backup_MANUAL_2000-01-01_00-00-00_' . str_repeat('a', 32) . '.db';
foreach ([['GET', '/backup'], ['POST', '/backup/criar'], ['GET', '/backup/baixar/' . $missingBackup]] as [$method, $path]) {
    checkHttp(request($method, $baseUrl . $path, $cookieGuest)['status'] === 302, 'Visitante não acessa backups: ' . $method . ' ' . $path);
    checkHttp(request($method, $baseUrl . $path, $cookieEmployee)['status'] === 403, 'Funcionário não acessa backups: ' . $method . ' ' . $path);
}
$beforeBackupFiles = $backupFiles();
$backupPage = request('GET', $baseUrl . '/backup', $cookieAdmin);
checkHttp($backupPage['status'] === 200 && str_contains($backupPage['body'], 'backup-senha-atual')
    && str_contains($backupPage['body'], 'href="' . $baseUrl . '/backup"')
    && !str_contains($backupPage['body'], $tempRoot), 'Administrador recebe formulário e menu sem caminho privado');
checkHttp($beforeBackupFiles === $backupFiles(), 'Consultar backups não cria snapshots');
checkHttp(request('GET', $baseUrl . '/backup/criar', $cookieAdmin)['status'] === 405, 'Criação de backup exige POST');
checkHttp(request('POST', $baseUrl . '/backup/criar', $cookieAdmin, ['_csrf_token' => 'invalid', 'senha_atual' => $permanentPassword])['status'] === 419
    && $beforeBackupFiles === $backupFiles(), 'CSRF inválido impede criação de backup');
$badBackupPassword = 'Senha ficticia incorreta backup 2026';
$backupRejected = request('POST', $baseUrl . '/backup/criar', $cookieAdmin, ['_csrf_token' => csrf($backupPage['body']), 'senha_atual' => $badBackupPassword]);
checkHttp($backupRejected['status'] === 422 && $beforeBackupFiles === $backupFiles()
    && !str_contains($backupRejected['body'], $badBackupPassword)
    && !preg_match('/id="backup-senha-atual"[^>]*value="[^"]+"/', $backupRejected['body']), 'Senha incorreta não gera snapshot nem retorna o segredo');

// Mantém uma conexão com WAL confirmado e sem checkpoint durante o pedido HTTP.
$backupDb->exec('PRAGMA journal_mode=WAL');
$backupDb->exec('PRAGMA wal_autocheckpoint=0');
$backupDb->exec("INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES('Turma WAL backup HTTP','turma wal backup http',2098)");
$walClassId = (int) $backupDb->lastInsertId();
checkHttp(is_file($database . '-wal') && filesize($database . '-wal') > 0, 'Ensaio mantém dados confirmados no WAL');
$backupCreated = request('POST', $baseUrl . '/backup/criar', $cookieAdmin, ['_csrf_token' => csrf($backupRejected['body']), 'senha_atual' => $permanentPassword]);
$createdBackupFiles = array_values(array_diff($backupFiles(), $beforeBackupFiles));
checkHttp($backupCreated['status'] === 302 && str_ends_with($backupCreated['headers']['location'] ?? '', '/backup')
    && count($createdBackupFiles) === 1, 'Administrador gera um snapshot validado');
$createdBackupPath = $createdBackupFiles[0];
$createdBackupName = basename($createdBackupPath);
$backupDownload = request('GET', $baseUrl . '/backup/baixar/' . rawurlencode($createdBackupName), $cookieAdmin);
checkHttp($backupDownload['status'] === 200 && str_starts_with($backupDownload['body'], "SQLite format 3\0")
    && str_contains(strtolower($backupDownload['headers']['cache-control'] ?? ''), 'no-store')
    && str_contains($backupDownload['headers']['content-disposition'] ?? '', 'attachment;')
    && ($backupDownload['headers']['x-content-type-options'] ?? '') === 'nosniff', 'Download autenticado é SQLite privado, sem cache e sem sniffing');
$restoredBackupPath = $tempRoot . DIRECTORY_SEPARATOR . 'http-restored-backup.sqlite';
file_put_contents($restoredBackupPath, $backupDownload['body']);
$restoredBackup = new PDO('sqlite:' . $restoredBackupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
checkHttp($restoredBackup->query('PRAGMA integrity_check')->fetchColumn() === 'ok'
    && $restoredBackup->query('PRAGMA foreign_key_check')->fetchAll() === []
    && $restoredBackup->query('SELECT nome_turma FROM turmas WHERE id=' . $walClassId)->fetchColumn() === 'Turma WAL backup HTTP', 'Restauração independente preserva registro do WAL e integridade');
$restoredBackup = null;
foreach (['backup.created', 'backup.downloaded'] as $backupAction) {
    $backupEvent = $backupDb->prepare('SELECT COUNT(*) FROM security_audit WHERE action=? AND actor_user_id=1 AND result=?');
    $backupEvent->execute([$backupAction, 'success']);
    checkHttp((int) $backupEvent->fetchColumn() === 1, 'Backup registra autoria obrigatória: ' . $backupAction);
    $backupEvent->closeCursor();
}
$backupEvent = null;
foreach ([$missingBackup, '..%5Chttp.sqlite', '..%2Fhttp.sqlite', rawurlencode($createdBackupName . '..')] as $invalidBackupName) {
    $invalidDownload = request('GET', $baseUrl . '/backup/baixar/' . $invalidBackupName, $cookieAdmin);
    checkHttp($invalidDownload['status'] === 404, 'Download recusa arquivo inexistente ou caminho inválido', $invalidBackupName . ' retornou ' . $invalidDownload['status']);
}
$backupPage = request('GET', $baseUrl . '/backup', $cookieAdmin);
checkHttp(str_contains($backupPage['body'], $createdBackupName), 'Snapshot criado aparece na consulta administrativa');
$preservedBackupHash = hash_file('sha256', $createdBackupPath);
$backupDb->exec("CREATE TRIGGER fail_http_backup_audit BEFORE INSERT ON security_audit WHEN NEW.action='backup.created' BEGIN SELECT RAISE(ABORT,'forced'); END");
try {
    $backupAuditFailure = request('POST', $baseUrl . '/backup/criar', $cookieAdmin, ['_csrf_token' => csrf($backupPage['body']), 'senha_atual' => $permanentPassword]);
    checkHttp($backupAuditFailure['status'] === 422 && $backupFiles() === array_merge($beforeBackupFiles, [$createdBackupPath])
        && hash_file('sha256', $createdBackupPath) === $preservedBackupHash
        && !preg_match('/forced|PDOException|SQLSTATE|VACUUM INTO/', $backupAuditFailure['body']), 'Falha de auditoria remove somente novo snapshot e preserva backup anterior');
} finally {
    $backupDb->exec('DROP TRIGGER fail_http_backup_audit');
}
$backupDb->exec("CREATE TRIGGER fail_http_backup_download BEFORE INSERT ON security_audit WHEN NEW.action='backup.downloaded' BEGIN SELECT RAISE(ABORT,'forced'); END");
try {
    $backupDownloadFailure = request('GET', $baseUrl . '/backup/baixar/' . rawurlencode($createdBackupName), $cookieAdmin);
    checkHttp($backupDownloadFailure['status'] === 503 && !str_starts_with($backupDownloadFailure['body'], 'SQLite format 3')
        && hash_file('sha256', $createdBackupPath) === $preservedBackupHash, 'Falha de auditoria impede bytes do download e preserva o arquivo');
} finally {
    $backupDb->exec('DROP TRIGGER fail_http_backup_download');
}

// A preferência isolada de alertas também constitui alteração rastreável.
$auditAdmin = $backupDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$newAlerts = (int) $auditAdmin['recebe_alertas_dva'] !== 1;
$adminEditPage = request('GET', $baseUrl . '/usuario/editar/1', $cookieAdmin);
$adminEditData = ['_csrf_token' => csrf($adminEditPage['body']), 'nome' => $auditAdmin['nome'], 'email' => $auditAdmin['email'], 'tipo' => 'administrador', 'senha' => '', 'confirmar_senha' => ''];
if ($newAlerts) {
    $adminEditData['recebe_alertas_dva'] = '1';
}
$alertsBefore = (int) $backupDb->query("SELECT COUNT(*) FROM security_audit WHERE action='user.dva_alerts_updated' AND target_user_id=1")->fetchColumn();
$adminPreferenceSaved = request('POST', $baseUrl . '/usuario/editar/1', $cookieAdmin, $adminEditData);
checkHttp($adminPreferenceSaved['status'] === 302
    && (int) $backupDb->query('SELECT recebe_alertas_dva FROM usuarios WHERE id=1')->fetchColumn() === (int) $newAlerts
    && (int) $backupDb->query("SELECT COUNT(*) FROM security_audit WHERE action='user.dva_alerts_updated' AND actor_user_id=1 AND target_user_id=1")->fetchColumn() === $alertsBefore + 1, 'Alterar somente preferência de alerta gera um evento com autoria');
$adminEditPage = request('GET', $baseUrl . '/usuario/editar/1', $cookieAdmin);
$adminEditData['_csrf_token'] = csrf($adminEditPage['body']);
if ($newAlerts) {
    unset($adminEditData['recebe_alertas_dva']);
} else {
    $adminEditData['recebe_alertas_dva'] = '1';
}
$adminBeforeFailure = $backupDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$backupDb->exec("CREATE TRIGGER fail_http_alerts_audit BEFORE INSERT ON security_audit WHEN NEW.action='user.dva_alerts_updated' BEGIN SELECT RAISE(ABORT,'forced'); END");
try {
    $adminPreferenceFailure = request('POST', $baseUrl . '/usuario/editar/1', $cookieAdmin, $adminEditData);
    checkHttp($adminPreferenceFailure['status'] === 422
        && $adminBeforeFailure === $backupDb->query('SELECT * FROM usuarios WHERE id=1')->fetch(PDO::FETCH_ASSOC)
        && !preg_match('/forced|PDOException|SQLSTATE/', $adminPreferenceFailure['body']), 'Falha na auditoria desfaz preferência sem alterar conta ou sessão');
} finally {
    $backupDb->exec('DROP TRIGGER fail_http_alerts_audit');
}
$backupEvent = null;
$backupDb = null;
