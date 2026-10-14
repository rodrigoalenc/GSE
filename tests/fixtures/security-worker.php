<?php

declare(strict_types=1);

$database = (string) getenv('DB_PATH');
if (PHP_SAPI !== 'cli' || !str_starts_with(basename($database), 'tcc_phpunit_')
    || realpath(dirname($database)) !== realpath(sys_get_temp_dir()) || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Banco descartável de teste exigido.');
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
$pdo = Model::getConexao();
src\Core\DatabaseInitializer::initialize($pdo);
if (($argv[1] ?? '') === 'budget') {
    try { ActionBudget::consume($pdo, (int)$argv[2], 'concurrent'); echo 'accepted'; }
    catch (ActionLimitExceeded) { echo 'limited'; }
} else {
    echo (new Contrato())->create('Contrato concorrente ficticio','100,00','',(int)$argv[2],$argv[3]);
}
