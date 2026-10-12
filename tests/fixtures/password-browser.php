<?php

declare(strict_types=1);

$testDirectory = dirname((string) getenv('DB_PATH'));
if (PHP_SAPI !== 'cli' || !str_starts_with(basename($testDirectory), 'gse-password-browser-')
    || realpath(dirname($testDirectory)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Disposable password browser directory required.');
}
ob_start();
require dirname(__DIR__, 2) . '/bin/init-db.php';
ob_end_clean();
require_once ROOT_PATH . '/src/Core/PasswordPolicy.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Model/Usuario.php';
$pdo = Model::getConexao();
src\Core\DatabaseInitializer::initialize($pdo);
$users = new Usuario();
if (($argv[1] ?? '') === 'seed') {
    if (!$users->criarAdministradorInicial('Admin ficticio', 'admin@example.test', str_repeat('á', 40) . ' frase temporária')) {
        throw new RuntimeException('Password fixture failed.');
    }
    $pdo->prepare('INSERT INTO usuarios(nome,email,senha,tipo) VALUES(?,?,?,?)')->execute([
        'Legado ficticio', 'legacy@example.test', password_hash(str_repeat('L', 72), PASSWORD_BCRYPT, ['cost' => 4]), 'funcionario',
    ]);
}
echo json_encode([
    'users' => $pdo->query('SELECT id,nome,email,tipo,session_version,deve_alterar_senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'algorithms' => array_map(static fn (string $hash): string => password_get_info($hash)['algoName'],
        $pdo->query('SELECT senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
], JSON_THROW_ON_ERROR);
