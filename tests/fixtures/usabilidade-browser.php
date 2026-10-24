<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
Model::setConexao($pdo);
src\Core\DatabaseInitializer::initialize($pdo);
$user = test_insert_user($pdo, ['nome'=>'Secretaria fictícia','email'=>'ui@example.test','senha'=>'fixture-non-login-hash','tipo'=>'administrador','ativo'=>1]);
$_SESSION = ['usuario_id'=>$user,'auth_version'=>1];
$pdo->exec("INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES ('7º Ano A','7 ano a',2026)");
$insert = $pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(?,?,?,1)');
foreach ([['José Silva','2010-02-03'],['José Silva','2009-04-05']] as [$name,$birth]) { $insert->execute([$name,src\Core\TextNormalizer::comparisonKey($name),$birth]); }
$_SERVER['REQUEST_METHOD']='GET';
if (($argv[1] ?? '') === 'aluno') {
    $_SERVER['REQUEST_URI']='/aluno/criar';
    require ROOT_PATH . '/src/Controllers/AlunoController.php';
    (new AlunoController())->criar();
} else {
    $_GET=['q'=>'jose']; $_SERVER['REQUEST_URI']='/dashboard';
    require ROOT_PATH . '/src/Controllers/DashboardController.php';
    (new DashboardController())->index();
}
