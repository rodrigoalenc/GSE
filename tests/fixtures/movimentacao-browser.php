<?php

declare(strict_types=1);

$directory = dirname((string) getenv('DB_PATH'));
if (PHP_SAPI !== 'cli' || !str_starts_with(basename($directory), 'gse-movement-browser-')
    || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Disposable movement fixture directory required.');
}
ob_start();
require dirname(__DIR__, 2) . '/bin/init-db.php';
ob_end_clean();
require_once ROOT_PATH . '/tests/Support/Keyring.php';
$pdo = src\Core\Database::getConnection();
if (($argv[1] ?? '') === 'seed') {
    test_insert_user($pdo, ['nome' => 'Administrador fictício', 'email' => 'movement@example.test',
        'senha' => password_hash('Teste ficticio seguro 2026', PASSWORD_DEFAULT), 'tipo' => 'administrador']);
    test_insert_user($pdo, ['nome' => 'Funcionário fictício', 'email' => 'employee@example.test',
        'senha' => password_hash('Teste ficticio seguro 2026', PASSWORD_DEFAULT), 'tipo' => 'funcionario']);
    $pdo->exec("INSERT INTO turmas(id,nome_turma,nome_normalizado,ano_letivo) VALUES
        (1,'TURMA EXISTENTE A','turma existente a',2026),
        (2,'TURMA EXISTENTE B','turma existente b',2026),
        (3,'TURMA EXISTENTE C','turma existente c',2026)");
    $insert = $pdo->prepare('INSERT INTO alunos(id,nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(?,?,?,?,?)');
    foreach ([[1, 'Aprovado primeiro', 1], [2, 'Reprovado primeiro', 1], [3, 'Concluinte terceiro', 3], [4, 'Reprovado terceiro', 3]] as [$id, $name, $class]) {
        $insert->execute([$id, $name, strtolower($name), '2010-05-10', $class]);
        $pdo->prepare('INSERT INTO dvas(id_aluno,data_vencimento,observacao) VALUES(?,?,?)')
            ->execute([$id, '2099-01-01', 'Histórico fictício preservado']);
    }
}
echo json_encode([
    'students' => $pdo->query('SELECT id,id_turma,ativo FROM alunos ORDER BY id')->fetchAll(),
    'classes' => $pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll(),
    'dvas' => $pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll(),
    'passive' => $pdo->query('SELECT aluno_origem_id,caixa FROM alunos_passivo ORDER BY id')->fetchAll(),
    'movements' => $pdo->query('SELECT m.aluno_id,l.tipo,l.ano FROM alunos_movimentacoes m JOIN alunos_movimentacao_lotes l ON l.id=m.lote_id ORDER BY m.id')->fetchAll(),
    'audit' => $pdo->query('SELECT action,result,resource_type,resource_id,description FROM security_audit ORDER BY id')->fetchAll(),
], JSON_THROW_ON_ERROR);
