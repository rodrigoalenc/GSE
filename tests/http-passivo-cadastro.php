<?php

declare(strict_types=1);

// Included by http-smoke.php; HTTP and fixture writes use its disposable database.
require_once dirname(__DIR__) . '/src/Core/TextNormalizer.php';
$registrationDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$registrationSnapshot = static fn (): array => $registrationDb->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll();
$registrationInsert = $registrationDb->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,caixa,caixa_normalizada,numero,numero_normalizado) VALUES(?,?,?,?,?,?)');
foreach ([['Zeta HTTP Automatica', '10001', '50001'], ['Ana HTTP Automatica', '10001', '50002'], ['Caixa Seguinte HTTP Automatica', '10002', '50003']] as [$registrationName, $registrationBox, $registrationNumber]) {
    $registrationInsert->execute([$registrationName, src\Core\TextNormalizer::searchKey($registrationName), $registrationBox, $registrationBox, $registrationNumber, $registrationNumber]);
}
$registrationBoxRows = static function (string $box) use ($registrationDb): array {
    $statement = $registrationDb->prepare('SELECT id,nome_completo,numero,caixa FROM alunos_passivo WHERE caixa = ? ORDER BY nome_normalizado,id');
    $statement->execute([$box]);

    return $statement->fetchAll();
};
$registrationSubmit = static function (string $name, string $mode, string $box = '', ?string $token = null) use ($baseUrl, $cookieAdmin): array {
    $form = request('GET', $baseUrl . '/passivo/criar?' . http_build_query(['tipo_caixa' => $mode, 'caixa' => $box]), $cookieAdmin);
    checkHttp($form['status'] === 200 && str_contains($form['body'], 'name="nome_completo"'), 'Cadastro automático abre dados depois da escolha da caixa');

    return request('POST', $baseUrl . '/passivo/criar', $cookieAdmin, [
        '_csrf_token' => csrf($form['body']), '_request_token' => $token ?? bin2hex(random_bytes(16)),
        'nome_completo' => $name, 'data_nascimento' => '2000-02-29', 'tipo_caixa' => $mode,
        'caixa' => $box, 'numero' => '999999999',
    ]);
};
$registrationResolveId = static function (array $response): int {
    $location = $response['headers']['location'] ?? '';

    return preg_match('#/passivo/detalhes/(\d+)$#', $location, $matches) === 1 ? (int) $matches[1] : 0;
};

$registrationBefore = $registrationSnapshot();
$registrationChoice = request('GET', $baseUrl . '/passivo/criar', $cookieAdmin);
$registrationNextForm = request('GET', $baseUrl . '/passivo/criar?tipo_caixa=nova', $cookieAdmin);
checkHttp($registrationChoice['status'] === 200
    && str_contains($registrationChoice['body'], 'Selecionar caixa existente')
    && str_contains($registrationChoice['body'], 'Criar próxima caixa')
    && !str_contains($registrationChoice['body'], 'name="nome_completo"')
    && str_contains($registrationNextForm['body'], 'name="nome_completo"')
    && !str_contains($registrationNextForm['body'], 'name="numero"')
    && $registrationBefore === $registrationSnapshot(),
    'Escolha e prévia da próxima caixa são etapas de leitura, sem criar caixas ou alunos');

$registrationBia = $registrationSubmit('Bia HTTP Automatica', 'existente', '10001');
$registrationBiaId = $registrationResolveId($registrationBia);
$registrationFirstRows = $registrationBoxRows('10001');
$registrationFollowingRows = $registrationBoxRows('10002');
checkHttp($registrationBia['status'] === 302 && $registrationBiaId > 0
    && array_column($registrationFirstRows, 'nome_completo') === ['Ana HTTP Automatica', 'Bia HTTP Automatica', 'Zeta HTTP Automatica']
    && (int) $registrationFirstRows[1]['numero'] === (int) $registrationFirstRows[0]['numero'] + 1
    && (int) $registrationFirstRows[2]['numero'] === (int) $registrationFirstRows[1]['numero'] + 1
    && (int) $registrationFollowingRows[0]['numero'] === (int) $registrationFirstRows[2]['numero'] + 1,
    'Aluno entra na posição alfabética da caixa e a caixa seguinte mantém sequência global');

$registrationAfter = $registrationSnapshot();
$registrationPreserved = true;
foreach ($registrationBefore as $registrationOldRow) {
    $registrationNewRow = array_values(array_filter($registrationAfter, static fn (array $row): bool => $row['id'] === $registrationOldRow['id']))[0] ?? [];
    foreach (['id', 'aluno_origem_id', 'nome_completo', 'nome_normalizado', 'data_nascimento', 'caixa', 'caixa_normalizada', 'ativo', 'criado_em', 'criado_por'] as $registrationField) {
        $registrationPreserved = $registrationPreserved && ($registrationNewRow[$registrationField] ?? null) === $registrationOldRow[$registrationField];
    }
}
checkHttp($registrationPreserved && count($registrationAfter) === count($registrationBefore) + 1,
    'Renumeração preserva todos os alunos, seus dados cadastrais, status, origem e caixa');

$registrationFollowingNumber = (int) $registrationFollowingRows[0]['numero'];
$registrationAlvaro = $registrationSubmit('Álvaro HTTP Automatica', 'existente', '10001');
$registrationSecondRows = $registrationBoxRows('10001');
checkHttp($registrationAlvaro['status'] === 302 && $registrationResolveId($registrationAlvaro) > 0
    && array_column($registrationSecondRows, 'nome_completo') === ['Álvaro HTTP Automatica', 'Ana HTTP Automatica', 'Bia HTTP Automatica', 'Zeta HTTP Automatica']
    && (int) $registrationBoxRows('10002')[0]['numero'] === $registrationFollowingNumber + 1,
    'Nova inclusão considera acentos na ordenação e desloca a numeração da caixa seguinte em uma posição');

$registrationToken = bin2hex(random_bytes(16));
$registrationNewBox = $registrationSubmit('Primeiro HTTP Nova Caixa', 'nova', 'CAIXA-FORJADA', $registrationToken);
$registrationNewId = $registrationResolveId($registrationNewBox);
$registrationCreatedRows = $registrationBoxRows('10003');
checkHttp($registrationNewBox['status'] === 302 && $registrationNewId > 0
    && count($registrationCreatedRows) === 1
    && $registrationCreatedRows[0]['id'] === $registrationNewId
    && (int) $registrationCreatedRows[0]['numero'] === (int) $registrationBoxRows('10002')[0]['numero'] + 1
    && $registrationCreatedRows[0]['numero'] !== '999999999'
    && $registrationBoxRows('CAIXA-FORJADA') === [],
    'Próxima caixa e número do aluno são calculados pelo servidor e ignoram valores manuais forjados');
$registrationBeforeRetry = $registrationSnapshot();
$registrationRetried = $registrationSubmit('Primeiro HTTP Nova Caixa', 'nova', 'CAIXA-FORJADA', $registrationToken);
checkHttp($registrationRetried['status'] === 302
    && $registrationResolveId($registrationRetried) === $registrationNewId
    && $registrationBeforeRetry === $registrationSnapshot(),
    'Repetir o mesmo envio não cria outro aluno nem avança a próxima caixa');

$registrationList = listRequest($baseUrl, 'passivo', $cookieAdmin, ['caixa' => '10001', 'ordem' => 'numero']);
preg_match('/data-list-state-token="([a-f0-9]{32})"/', $registrationList['body'], $registrationListToken);
// Match list-state.js: refreshing the clean URL restores its opaque list token.
$registrationReload = request('POST', $baseUrl . '/passivo', $cookieAdmin, [
    '_csrf_token' => csrf($registrationList['body']), '_list_state_action' => 'restore',
    '_list_state_token' => $registrationListToken[1] ?? '',
]);
$registrationDocument = new DOMDocument();
@$registrationDocument->loadHTML('<?xml encoding="UTF-8">' . $registrationReload['body']);
$registrationXPath = new DOMXPath($registrationDocument);
$registrationNames = [];
foreach ($registrationXPath->query('//a[@class="passivo-name"]') as $registrationNode) {
    $registrationNames[] = trim($registrationNode->textContent);
}
checkHttp($registrationList['status'] === 200 && $registrationReload['status'] === 200
    && $registrationNames === ['Álvaro HTTP Automatica', 'Ana HTTP Automatica', 'Bia HTTP Automatica', 'Zeta HTTP Automatica']
    && $registrationBeforeRetry === $registrationSnapshot(),
    'Consulta por número mantém a ordem alfabética da caixa após atualizar a página');

$registrationAllNumbers = $registrationDb->query("SELECT numero FROM alunos_passivo WHERE NULLIF(TRIM(caixa_normalizada), '') IS NOT NULL OR NULLIF(TRIM(numero_normalizado), '') IS NOT NULL ORDER BY LENGTH(numero),numero")->fetchAll(PDO::FETCH_COLUMN);
checkHttp($registrationAllNumbers === array_map('strval', range(1, count($registrationAllNumbers))),
    'Acervo completo mantém numeração única e contínua entre caixas, incluindo registros excluídos');

$registrationProtectedBefore = $registrationSnapshot();
$registrationInvalidCsrf = request('POST', $baseUrl . '/passivo/criar', $cookieAdmin, [
    '_csrf_token' => 'invalid', '_request_token' => bin2hex(random_bytes(16)),
    'nome_completo' => 'Não deve ser criado HTTP', 'tipo_caixa' => 'nova',
]);
checkHttp($registrationInvalidCsrf['status'] === 419 && $registrationProtectedBefore === $registrationSnapshot(),
    'CSRF inválido impede cadastro e renumeração integralmente');
$registrationInvalidBox = request('POST', $baseUrl . '/passivo/criar', $cookieAdmin, [
    '_csrf_token' => csrf($registrationNextForm['body']), '_request_token' => bin2hex(random_bytes(16)),
    'nome_completo' => 'Caixa inexistente HTTP', 'tipo_caixa' => 'existente', 'caixa' => 'CX-INEXISTENTE-CADASTRO-HTTP',
]);
$registrationInvalidFeedback = request('GET', $baseUrl . '/passivo/criar', $cookieAdmin);
$registrationCorrectedForm = request('GET', $baseUrl . '/passivo/criar?tipo_caixa=existente&caixa=10001', $cookieAdmin);
checkHttp($registrationInvalidBox['status'] === 302
    && str_contains($registrationInvalidFeedback['body'], 'A caixa informada não existe')
    && str_contains($registrationCorrectedForm['body'], 'value="Caixa inexistente HTTP"')
    && $registrationProtectedBefore === $registrationSnapshot(),
    'Caixa existente forjada é rejeitada sem criar aluno ou alterar a sequência');

$registrationDb->exec("CREATE TRIGGER fail_http_registration_audit BEFORE INSERT ON security_audit WHEN NEW.action = 'passive.enumerated' BEGIN SELECT RAISE(ABORT, 'forced registration failure'); END");
try {
    $registrationFailed = $registrationSubmit('Abel HTTP Falha Atomica', 'existente', '10001');
    $registrationFailureFeedback = request('GET', $baseUrl . '/passivo/criar', $cookieAdmin);
    checkHttp($registrationFailed['status'] === 302
        && $registrationResolveId($registrationFailed) === 0
        && $registrationProtectedBefore === $registrationSnapshot()
        && !preg_match('/forced registration failure|PDOException|SQLSTATE/', $registrationFailureFeedback['body']),
        'Falha durante atualização desfaz cadastro e toda renumeração sem expor detalhes técnicos');
} finally {
    $registrationDb->exec('DROP TRIGGER fail_http_registration_audit');
}

unset($registrationInsert, $registrationSnapshot, $registrationBoxRows, $registrationSubmit);
$registrationDb = null;
