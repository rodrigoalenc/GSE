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
require_once ROOT_PATH . '/tests/Support/Keyring.php';
$pdo = Model::getConexao();
src\Core\DatabaseInitializer::initialize($pdo);
$users = new Usuario();
if (($argv[1] ?? '') === 'seed') {
    if (!$users->criarAdministradorInicial('Admin ficticio', 'admin@example.test', str_repeat('á', 40) . ' frase temporária')) {
        throw new RuntimeException('Password fixture failed.');
    }
    test_insert_user($pdo, ['nome'=>'Legado ficticio','email'=>'legacy@example.test','senha'=>password_hash(str_repeat('L', 72), PASSWORD_BCRYPT, ['cost' => 4]),'tipo'=>'funcionario']);
    // Keep the comparison inside this disposable fixture; never emit password hashes.
    file_put_contents($testDirectory . '/original-password-hashes.json', json_encode(
        $pdo->query('SELECT id,senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), JSON_THROW_ON_ERROR
    ));
    if (DIRECTORY_SEPARATOR === '/') {
        chmod($testDirectory . '/original-password-hashes.json', 0600);
    }
}
$originalHashes = json_decode((string) file_get_contents($testDirectory . '/original-password-hashes.json'), true, 512, JSON_THROW_ON_ERROR);
$currentHashes = $pdo->query('SELECT id,senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
echo json_encode([
    'users' => array_map([DataProtection::class,'user'], $pdo->query('SELECT id,nome,email,tipo,session_version,deve_alterar_senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)),
    'algorithms' => array_map(static fn (string $hash): string => password_get_info($hash)['algoName'],
        $pdo->query('SELECT senha FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
    'original_hashes_preserved' => array_map(static fn (int $id): bool => isset($originalHashes[$id])
        && hash_equals($originalHashes[$id], $currentHashes[$id]), array_keys($currentHashes)),
], JSON_THROW_ON_ERROR);
