<?php

declare(strict_types=1);

// Included by http-smoke.php; all fixtures use its disposable database.
require_once dirname(__DIR__) . '/src/Core/TextNormalizer.php';
$paginationDb = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$paginationInsert = $paginationDb->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,caixa,caixa_normalizada,numero,numero_normalizado,ativo) VALUES(?,?,?,?,?,?,?)');
$paginationBox = 'CX-PAG & 1';
foreach (range(1, 72) as $number) {
    $name = ($number === 71 ? 'OUTRO NOME ' : 'ALUNO PAGINADO ') . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    $box = $number === 72 ? 'OUTRA CX-PAG' : $paginationBox;
    $paginationInsert->execute([$name, src\Core\TextNormalizer::searchKey($name), $box,
        src\Core\TextNormalizer::searchKey($box), (string) $number, (string) $number, $number <= 45 || $number >= 71 ? 1 : 0]);
}
$paginationParams = ['q' => 'ALUNO PAGINADO', 'caixa' => $paginationBox, 'localizacao' => 'completa', 'ativo' => '1', 'ordem' => 'numero'];
$paginationSeen = [];
foreach ([1 => [1, 20], 2 => [21, 40], 3 => [41, 45]] as $page => [$first, $last]) {
    $response = listRequest($baseUrl, 'passivo', $cookieAdmin, $paginationParams + ['page' => (string) $page]);
    checkHttp($response['status'] === 200, 'Página do passivo responde normalmente: ' . $page);
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $response['body']);
    $xpath = new DOMXPath($document);
    $names = [];
    foreach ($xpath->query('//a[@class="passivo-name"]') as $node) {
        $names[] = trim($node->textContent);
    }
    checkHttp($names === array_map(static fn (int $number): string => 'ALUNO PAGINADO ' . str_pad((string) $number, 3, '0', STR_PAD_LEFT), range($first, $last)),
        'Página contém a sequência correta da caixa, sem outros resultados: ' . $page);
    $paginationSeen = array_merge($paginationSeen, $names);
    checkHttp($xpath->query('//nav[@class="passivo-pagination"]')->length === 2
        && substr_count($response['body'], 'Exibindo ' . $first . '–' . $last . ' de 45 registros · Página ' . $page . ' de 3') === 2,
        'Navegação e intervalo aparecem antes e depois da tabela: ' . $page);
    checkHttp($xpath->query('//nav[@class="passivo-pagination"]//button[@aria-current="page"]')->length === 2,
        'Página atual está identificada nos dois controles: ' . $page);
    foreach ($xpath->query('//nav[@class="passivo-pagination"]/form') as $form) {
        $posted = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $posted[$input->getAttribute('name')] = $input->getAttribute('value'); }
        checkHttp(!str_contains($form->getAttribute('action'), '?')
            && array_intersect_key($posted, $paginationParams) === $paginationParams
            && (int) $posted['page'] >= 1 && (int) $posted['page'] <= 3,
            'Paginação mantém os filtros em POST e conserva o endereço limpo');
    }
    $previous = $xpath->query('(//nav[@class="passivo-pagination"])[1]//button[@rel="prev"]');
    $next = $xpath->query('(//nav[@class="passivo-pagination"])[1]//button[@rel="next"]');
    checkHttp($previous->length === ($page > 1 ? 1 : 0) && $next->length === ($page < 3 ? 1 : 0),
        'Anterior e próxima respeitam as extremidades: ' . $page);
    if ($page < 3) {
        $nextResponse = listRequest($baseUrl, 'passivo', $cookieAdmin, $paginationParams + ['page' => (string) ($page + 1)]);
        checkHttp($nextResponse['status'] === 200 && str_contains($nextResponse['body'], 'Página ' . ($page + 1) . ' de 3'),
            'Link Próxima abre a página seguinte da mesma caixa');
    }
}
checkHttp(count($paginationSeen) === 45 && count(array_unique($paginationSeen)) === 45, 'Todos os alunos da caixa são acessíveis sem repetição entre páginas');
$lastPage = listRequest($baseUrl, 'passivo', $cookieAdmin, $paginationParams + ['page'=>'999']);
checkHttp(str_contains($lastPage['body'], 'Exibindo 41–45 de 45 registros'), 'Página acima do limite abre a última página válida');
$firstPage = listRequest($baseUrl, 'passivo', $cookieAdmin, $paginationParams + ['page'=>'-1']);
checkHttp(str_contains($firstPage['body'], 'Exibindo 1–20 de 45 registros'), 'Página negativa abre a primeira página');
$inactiveParams = array_replace($paginationParams, ['ativo' => '0', 'page' => 2]);
$inactivePage = listRequest($baseUrl, 'passivo/inativos', $cookieAdmin, $inactiveParams);
checkHttp(str_contains($inactivePage['body'], 'Exibindo 21–25 de 25 registros') && str_contains($inactivePage['body'], 'ALUNO PAGINADO 070'),
    'Registros excluídos também têm paginação');
$allPage = listRequest($baseUrl, 'passivo', $cookieAdmin, array_replace($paginationParams, ['ativo' => 'todos', 'page' => 4]));
checkHttp(str_contains($allPage['body'], 'Exibindo 61–70 de 70 registros') && str_contains($allPage['body'], 'name="ativo" value="todos"'),
    'Filtro de todas as situações permanece nos links');
foreach (['ALUNO PAGINADO 045' => 'Exibindo 1–1 de 1 registros', 'NENHUM PAGINADO' => 'Exibindo 0–0 de 0 registros'] as $search => $summary) {
    $response = listRequest($baseUrl, 'passivo', $cookieAdmin, array_replace($paginationParams, ['q' => $search]));
    checkHttp(str_contains($response['body'], $summary) && !str_contains($response['body'], '<nav class="passivo-pagination"'),
        'Resultado de uma página ou vazio mostra intervalo sem links desnecessários');
}
unset($paginationInsert);
$paginationDb = null;
