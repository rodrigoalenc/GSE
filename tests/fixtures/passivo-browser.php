<?php

declare(strict_types=1);

// This fixture must never point at an application or institutional database.
$fixtureDirectory = dirname((string) getenv('DB_PATH'));
if (PHP_SAPI !== 'cli' || !str_starts_with(basename($fixtureDirectory), 'gse-passivo-browser-')
    || realpath(dirname($fixtureDirectory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Disposable browser fixture directory required.');
}
ob_start();
require dirname(__DIR__, 2) . '/bin/init-db.php';
ob_end_clean();
$pdo = src\Core\Database::getConnection();
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Model/Contrato.php';
require_once ROOT_PATH . '/src/Model/Certidao.php';
if (($argv[1] ?? '') === 'seed') {
    $pdo->prepare('INSERT INTO usuarios(nome,email,senha,tipo) VALUES(?,?,?,?)')->execute([
        'Administrador fictício', 'browser@example.test', password_hash('Teste ficticio seguro 2026', PASSWORD_DEFAULT), 'administrador',
    ]);
    (new Certidao())->saveOption('fornecedor', null, 'Fornecedor fictício para edição', true, 1);
    $pdo->prepare('INSERT INTO usuarios(nome,email,senha,tipo) VALUES(?,?,?,?)')->execute([
        'Funcionário fictício', 'employee@example.test', password_hash('Teste ficticio seguro 2026', PASSWORD_DEFAULT), 'funcionario',
    ]);
    $insert = $pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,ativo) VALUES(?,?,?,?)');
    $dva = $pdo->prepare('INSERT INTO dvas(id_aluno,id_usuario_registro,data_vencimento,observacao) VALUES(?,1,?,?)');
    for ($id = 1; $id <= 33; $id++) {
        $name = 'Aluno ficticio ' . str_pad((string) $id, 2, '0', STR_PAD_LEFT);
        $insert->execute([$name, strtolower($name), '2010-05-10', $id < 32 ? 1 : 0]);
        $dva->execute([$id, '2027-06-01', 'Histórico fictício a preservar']);
    }
    $pdo->exec("UPDATE alunos SET telefone_aluno='(67) 99999-0001',telefone_responsavel='(67) 99999-0002' WHERE id=1");
    $passive = $pdo->prepare('INSERT INTO alunos_passivo(nome_completo,nome_normalizado,caixa,caixa_normalizada,numero,numero_normalizado,ativo,aluno_origem_id) VALUES(?,?,?,?,?,?,?,?)');
    foreach ([['2', '3', 1, null], ['2', '15', 0, null], ['10', '1', 1, 32]] as [$box, $number, $active, $origin]) {
        $passive->execute(['Pasta ficticia', 'pasta ficticia', $box, $box, $number, $number, $active, $origin]);
    }
    (new Contrato())->createDetailed('Pedido fictício de navegador', '1000,00', '', array_map(
        static fn (int $number): array => ['observacao' => 'Nota fictícia ' . $number, 'produtos' => [[
            'nome' => 'Produto fictício ' . $number, 'marca' => '', 'unidade' => 'UN', 'quantidade' => '1', 'preco' => '2,00',
        ]]], [1, 2, 3]
    ), 1);
}
echo json_encode([
    'alunos' => $pdo->query('SELECT id,ativo,inativado_em,inativado_por FROM alunos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'passivo' => $pdo->query('SELECT aluno_origem_id,caixa,numero,ativo FROM alunos_passivo ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'dvas' => $pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
], JSON_THROW_ON_ERROR);
