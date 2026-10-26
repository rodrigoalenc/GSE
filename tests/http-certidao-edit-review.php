<?php
declare(strict_types=1);

// Included by http-smoke.php; this fixture is independent of other certificate scenarios.
$reviewDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$reviewPage = request('GET', $baseUrl . '/certidao/cadastrar', $cookieEmployee);
$reviewCsrf = csrf($reviewPage['body']);
foreach (['fornecedor' => 'Fornecedor Conferência HTTP', 'tipo' => 'Tipo Conferência HTTP'] as $kind => $name) {
    request('POST', $baseUrl . '/certidao/configurar', $cookieEmployee, ['_csrf_token' => $reviewCsrf, 'tipo' => $kind, 'nome' => $name, 'ativo' => '1']);
}
$reviewFields = [
    '_csrf_token' => $reviewCsrf,
    'id_fornecedor' => (string) $reviewDb->query("SELECT id FROM lista_fornecedores WHERE nome='Fornecedor Conferência HTTP'")->fetchColumn(),
    'id_tipo_certidao' => (string) $reviewDb->query("SELECT id FROM lista_tipos_certidao WHERE nome='Tipo Conferência HTTP'")->fetchColumn(),
    'data_emissao' => '2026-01-01', 'data_vencimento' => '2026-12-31', 'observacao' => 'Original conferência',
];
$reviewCreated = request('POST', $baseUrl . '/certidao/cadastrar', $cookieEmployee, $reviewFields);
preg_match('#/certidao/detalhes/([0-9]+)#', $reviewCreated['headers']['location'] ?? '', $reviewMatch);
checkHttp(isset($reviewMatch[1]), 'Conferência: cria certidão independente');
$reviewId = (int) $reviewMatch[1];
$reviewUrl = $baseUrl . '/certidao/editar/' . $reviewId;
$reviewRecord = static fn (): array => $reviewDb->query('SELECT * FROM certidoes WHERE id=' . $reviewId)->fetch();
$reviewAuditCount = static fn (): int => (int) $reviewDb->query("SELECT COUNT(*) FROM security_audit WHERE action='certidao.updated' AND resource_id=" . $reviewId)->fetchColumn();
$reviewOldPage = request('GET', $reviewUrl, $cookieEmployee);
$reviewAttempt = array_replace($reviewFields, ['revisao' => formRevision($reviewOldPage['body']), 'observacao' => '<Minha tentativa preservada>', 'data_emissao' => '2026-02-01']);
$reviewCompete = static function (string $observation) use ($reviewUrl, $cookieAdmin, $reviewFields): void {
    $page = request('GET', $reviewUrl, $cookieAdmin);
    $result = request('POST', $reviewUrl, $cookieAdmin, array_replace($reviewFields, ['_csrf_token' => csrf($page['body']), 'revisao' => formRevision($page['body']), 'observacao' => $observation]));
    checkHttp($result['status'] === 302 && str_contains($result['headers']['location'] ?? '', '/certidao/detalhes/'), 'Conferência: escritor concorrente salva revisão real');
};
$reviewCompete('Versão atual de outra pessoa');
$reviewBefore = $reviewRecord(); $reviewAuditBefore = $reviewAuditCount();
$reviewResponse = request('POST', $reviewUrl, $cookieEmployee, $reviewAttempt);
checkHttp($reviewResponse['status'] === 422 && str_contains($reviewResponse['body'], 'data-conflict-review')
    && str_contains($reviewResponse['body'], 'Dados atuais') && str_contains($reviewResponse['body'], 'Sua tentativa')
    && str_contains($reviewResponse['body'], 'Versão atual de outra pessoa')
    && str_contains($reviewResponse['body'], '&lt;Minha tentativa preservada&gt;')
    && str_contains($reviewResponse['body'], 'value="2026-02-01"')
    && formRevision($reviewResponse['body']) === (string) $reviewBefore['revisao']
    && $reviewRecord() === $reviewBefore && $reviewAuditCount() === $reviewAuditBefore,
    'Conferência: conflito preserva campos e compara com registro atual sem gravar');

$reviewPayload = array_replace($reviewAttempt, ['revisao' => formRevision($reviewResponse['body']), '_conflict_token' => hiddenValue($reviewResponse['body'], '_conflict_token', 32)]);
$reviewCsrfFailure = request('POST', $reviewUrl, $cookieEmployee, array_replace($reviewPayload, ['_csrf_token' => 'invalid', 'confirmar_revisao' => '1']));
checkHttp($reviewCsrfFailure['status'] === 419 && $reviewRecord() === $reviewBefore, 'Conferência: CSRF inválido continua bloqueado');
foreach (['sem confirmação', 'token inválido', 'token omitido', 'revisão adulterada'] as $reviewCase) {
    $payload = $reviewPayload;
    if ($reviewCase !== 'sem confirmação') { $payload['confirmar_revisao'] = '1'; }
    if ($reviewCase === 'token inválido') { $payload['_conflict_token'] = str_repeat('0', 32); }
    if ($reviewCase === 'token omitido') { unset($payload['_conflict_token']); }
    if ($reviewCase === 'revisão adulterada') { $payload['revisao'] = (string) ((int) $payload['revisao'] + 1); }
    $reviewResponse = request('POST', $reviewUrl, $cookieEmployee, $payload);
    checkHttp($reviewResponse['status'] === 422 && str_contains($reviewResponse['body'], 'data-conflict-review')
        && $reviewRecord() === $reviewBefore && $reviewAuditCount() === $reviewAuditBefore,
        'Conferência: exige autorização explícita e revisão conferida — ' . $reviewCase);
    $reviewPayload = array_replace($reviewAttempt, ['revisao' => formRevision($reviewResponse['body']), '_conflict_token' => hiddenValue($reviewResponse['body'], '_conflict_token', 32)]);
}
$reviewCompete('Terceira versão durante conferência');
$reviewBefore = $reviewRecord(); $reviewAuditBefore = $reviewAuditCount();
$reviewResponse = request('POST', $reviewUrl, $cookieEmployee, $reviewPayload + ['confirmar_revisao' => '1']);
checkHttp($reviewResponse['status'] === 422 && str_contains($reviewResponse['body'], 'Terceira versão durante conferência')
    && str_contains($reviewResponse['body'], '&lt;Minha tentativa preservada&gt;')
    && formRevision($reviewResponse['body']) === (string) $reviewBefore['revisao'] && $reviewRecord() === $reviewBefore,
    'Conferência: terceira edição recusa confirmação antiga e reapresenta comparação');
$reviewPayload = array_replace($reviewAttempt, ['revisao' => formRevision($reviewResponse['body']), '_conflict_token' => hiddenValue($reviewResponse['body'], '_conflict_token', 32), 'confirmar_revisao' => '1']);
$reviewInvalid = request('POST', $reviewUrl, $cookieEmployee, array_replace($reviewPayload, ['data_vencimento' => '2025-01-01']));
checkHttp($reviewInvalid['status'] === 422 && str_contains($reviewInvalid['body'], 'data-conflict-review')
    && str_contains($reviewInvalid['body'], 'value="2025-01-01"') && str_contains($reviewInvalid['body'], '&lt;Minha tentativa preservada&gt;')
    && $reviewRecord() === $reviewBefore && $reviewAuditCount() === $reviewAuditBefore,
    'Conferência: erro de validação preserva tentativa e exige nova confirmação');
$reviewPayload['_conflict_token'] = hiddenValue($reviewInvalid['body'], '_conflict_token', 32);
$reviewDb->exec("CREATE TRIGGER fail_cert_review_audit BEFORE INSERT ON security_audit WHEN NEW.action='certidao.updated' AND NEW.resource_id=" . $reviewId . " BEGIN SELECT RAISE(ABORT,'forced'); END");
try {
    $reviewFailure = request('POST', $reviewUrl, $cookieEmployee, $reviewPayload);
    checkHttp($reviewFailure['status'] === 422 && str_contains($reviewFailure['body'], 'data-conflict-review')
        && !preg_match('/forced|PDOException|SQLSTATE/', $reviewFailure['body'])
        && $reviewRecord() === $reviewBefore && $reviewAuditCount() === $reviewAuditBefore,
        'Conferência: falha da auditoria desfaz gravação e preserva preenchimento');
    $reviewPayload['_conflict_token'] = hiddenValue($reviewFailure['body'], '_conflict_token', 32);
} finally {
    $reviewDb->exec('DROP TRIGGER fail_cert_review_audit');
}
$reviewResponse = request('POST', $reviewUrl, $cookieEmployee, $reviewPayload);
checkHttp($reviewResponse['status'] === 302 && str_contains($reviewResponse['headers']['location'] ?? '', '/certidao/detalhes/' . $reviewId)
    && $reviewRecord()['observacao'] === '<Minha tentativa preservada>'
    && (int) $reviewRecord()['revisao'] === (int) $reviewBefore['revisao'] + 1 && $reviewAuditCount() === $reviewAuditBefore + 1,
    'Conferência: confirmação válida salva e audita exatamente uma vez');
$reviewClean = request('GET', $reviewUrl, $cookieEmployee);
checkHttp($reviewClean['status'] === 200 && !str_contains($reviewClean['body'], 'data-conflict-review')
    && formRevision($reviewClean['body']) === (string) $reviewRecord()['revisao'], 'Conferência: GET limpo mostra registro atual');

// Store and recover an actual session draft before emulating the browser's submission.
$reviewDraftTarget = 'certidao/editar/' . $reviewId;
$reviewDraftEndpoint = $baseUrl . '/formulario/rascunho';
$reviewDraftContext = json_decode(request('GET', $reviewDraftEndpoint . '?form=' . $reviewDraftTarget, $cookieEmployee)['body'], true)['context'] ?? '';
$reviewDraftSaved = request('POST', $reviewDraftEndpoint, $cookieEmployee, [
    '_csrf_token' => $reviewCsrf, 'form' => $reviewDraftTarget, 'context' => $reviewDraftContext, 'operation' => 'save', 'revision' => '1',
    'fields' => json_encode(array_diff_key(array_replace($reviewFields, ['observacao' => 'Rascunho antigo recuperado']), ['_csrf_token' => true])),
]);
$reviewRecoveredDraft = json_decode(request('GET', $reviewDraftEndpoint . '?form=' . $reviewDraftTarget, $cookieEmployee)['body'], true)['draft'] ?? null;
checkHttp($reviewDraftSaved['status'] === 200 && $reviewRecoveredDraft['revision'] === '1'
    && $reviewRecoveredDraft['fields']['observacao'] === 'Rascunho antigo recuperado', 'Conferência: rascunho de sessão mantém revisão original');

// The shared recovery script currently submits the originating revision for certificates.
// Also cover _draft_source_revision so both recovery protocols require the same review.
foreach (['originating revision', '_draft_source_revision'] as $draftProtocol) {
    $reviewBefore = $reviewRecord(); $reviewAuditBefore = $reviewAuditCount();
    $payload = array_replace($reviewFields, $reviewRecoveredDraft['fields'], ['revisao' => $draftProtocol === 'originating revision' ? $reviewRecoveredDraft['revision'] : (string) $reviewBefore['revisao']]);
    if ($draftProtocol === '_draft_source_revision') { $payload['_draft_source_revision'] = $reviewRecoveredDraft['revision']; }
    $reviewResponse = request('POST', $reviewUrl, $cookieEmployee, $payload);
    checkHttp($reviewResponse['status'] === 422 && str_contains($reviewResponse['body'], 'data-conflict-review')
        && str_contains($reviewResponse['body'], 'data-source-revision="1"')
        && str_contains($reviewResponse['body'], 'Rascunho antigo recuperado')
        && formRevision($reviewResponse['body']) === (string) $reviewBefore['revisao']
        && $reviewRecord() === $reviewBefore && $reviewAuditCount() === $reviewAuditBefore,
        'Conferência: rascunho antigo exige comparação sem promoção silenciosa — ' . $draftProtocol);
    request('GET', $reviewUrl, $cookieEmployee);
}
unset($reviewCompete, $reviewRecord, $reviewAuditCount);
$reviewDb = null;
