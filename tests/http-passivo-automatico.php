<?php

declare(strict_types=1);

// Only the disposable HTTP-smoke database is used here.
$automaticDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$automaticInsert = $automaticDb->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,caixa,caixa_normalizada,numero,numero_normalizado) VALUES(?,?,?,?,?,?)');
foreach ([['Ana Automatica', '851'], ['Bia Automatica', '852']] as [$name, $number]) {
    $automaticInsert->execute([$name, strtolower($name), 'CX-AUTOMATICA', 'cx-automatica', $number, $number]);
}
$automaticBefore = $automaticDb->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll();
foreach ([$cookieAdmin, $cookieEmployee, $cookieGuest] as $automaticCookie) {
    foreach (['' => 'GET', '/enumerar/preview' => 'POST', '/enumerar/confirmar' => 'POST'] as $suffix => $method) {
        $removed = request($method, $baseUrl . '/passivo/ferramentas' . $suffix, $automaticCookie, ['caixa' => 'CX-AUTOMATICA']);
        checkHttp($removed['status'] === 404, 'Ferramentas e renumeração manual não possuem mais rota: ' . $method . $suffix);
    }
}
$automaticPage = request('GET', $baseUrl . '/passivo?caixa=CX-AUTOMATICA&ordem=numero', $cookieAdmin);
checkHttp($automaticPage['status'] === 200 && !str_contains($automaticPage['body'], '/passivo/ferramentas')
    && str_contains($automaticPage['body'], 'id="passivo-export-form"')
    && str_contains($automaticPage['body'], 'name="caixa" value="CX-AUTOMATICA"'),
    'Caixa escolhida oferece exportação direta sem Ferramentas');
$automaticExport = request('POST', $baseUrl . '/passivo/exportar', $cookieAdmin, [
    '_csrf_token' => csrf($automaticPage['body']), 'caixa' => 'CX-AUTOMATICA',
]);
checkHttp($automaticExport['status'] === 200 && $automaticExport['body'] === "851 - Ana Automatica\r\n852 - Bia Automatica\r\n",
    'Exportação conserva nomes e numeração contínua da caixa escolhida');
checkHttp($automaticBefore === $automaticDb->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll(),
    'Exportação e chamadas às rotas removidas preservam todos os números históricos');
$automaticInsert = null;
$automaticDb = null;
