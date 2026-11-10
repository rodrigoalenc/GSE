<?php

declare(strict_types=1);

$database = (string) getenv('DB_PATH');
$barrier = $argv[1] ?? '';
$actor = (int) ($argv[2] ?? 0);
$token = $argv[3] ?? '';
$worker = $argv[4] ?? '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || !str_starts_with(basename($database), 'tcc_phpunit_')
    || realpath(dirname($database)) !== realpath(sys_get_temp_dir())
    || !str_starts_with(basename($barrier), 'gse-passivo-race-')
    || realpath(dirname($barrier)) !== realpath(sys_get_temp_dir())
    || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
    throw new RuntimeException('Banco e barreira descartáveis de teste exigidos.');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';

$model = new Passivo();
$preview = $model->proximaCaixa();
file_put_contents($barrier . '.' . $token . '.ready', 'ready');
for ($attempt = 0; $attempt < 1500 && !is_file($barrier); $attempt++) {
    usleep(10000);
}
if (!is_file($barrier)) {
    throw new RuntimeException('Barreira de gravação não liberada.');
}

$id = $model->cadastrarAutomatico([
    'nome_completo' => 'Aluno concorrente ' . $worker,
    'tipo_caixa' => 'nova', 'caixa' => $preview,
], $actor, $token);
$record = $id === false ? false : $model->buscarPorId($id);
echo json_encode(['success' => $id !== false, 'preview' => $preview,
    'id' => $id, 'caixa' => $record['caixa'] ?? null, 'error' => $model->lastErrorCode()], JSON_THROW_ON_ERROR);
