<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Model::setConexao($pdo);
src\Core\DatabaseInitializer::initialize($pdo);
$restricted = ($argv[1] ?? '') === 'restricted';
$user = test_insert_user($pdo, ['nome' => 'Secretaria fictícia', 'email' => 'preview@example.test', 'senha' => 'fixture-non-login-hash', 'tipo' => $restricted ? 'funcionario' : 'administrador', 'ativo' => 1]);
SessionManager::authenticate((new Usuario())->buscarPorId($user));
foreach (['ACCESS_HEALTH_STAFF_IDS', 'ACCESS_CONTACT_STAFF_IDS', 'ACCESS_MANAGE_STAFF_IDS', 'ACCESS_EXPORT_STAFF_IDS'] as $key) { $_ENV[$key] = ''; }
$pdo->exec("INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES ('8º Ano A','8 ano a',2026)");
$insert = $pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma,telefone_aluno,telefone_responsavel) VALUES(?,?,?,1,?,?)');
foreach ([['Ana Clara Santos', '2012-03-14', '67999990001', '67999990002'], ['Bruno Lima & Souza', '2011-04-17', '67999990003', '']] as [$name, $birth, $phone, $guardian]) {
    $insert->execute([$name, src\Core\TextNormalizer::comparisonKey($name), $birth, $phone, $guardian]);
}
$pdo->exec("INSERT INTO dvas(id_aluno,id_usuario_registro,data_vencimento) VALUES(1,1,'2090-12-31'),(2,1,'2020-01-01')");
$_GET = ($argv[1] ?? '') === 'empty' ? ['q' => 'Nenhum resultado fictício'] : [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/aluno';
require ROOT_PATH . '/src/Controllers/AlunoController.php';
(new AlunoController())->index();
