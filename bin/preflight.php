<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/src/Core/RuntimeRequirements.php';
require_once ROOT_PATH . '/src/Core/DeploymentPreflight.php';
require_once ROOT_PATH . '/src/Core/StorageProtection.php';

$options = getopt('', ['database:', 'storage:', 'json', 'storage-protection', 'sessions:', 'logs:', 'keys:', 'temporary:', 'backups:']);
$runtime = RuntimeRequirements::inspect();
$report = ['runtime' => $runtime, 'database' => null, 'production_approved' => false];
$report['storage_protection'] = null;
if (isset($options['storage-protection'])) {
    $paths = [];
    foreach (['database' => 'database', 'documents' => 'storage', 'sessions' => 'sessions', 'logs' => 'logs', 'keys' => 'keys', 'temporary' => 'temporary', 'backups' => 'backups'] as $category => $option) {
        $paths[$category] = is_string($options[$option] ?? null) ? $options[$option] : '';
    }
    $report['storage_protection'] = StorageProtection::inspect($paths);
}
if (isset($options['database']) && is_string($options['database'])) {
    $report['database'] = DeploymentPreflight::database(
        $options['database'],
        isset($options['storage']) && is_string($options['storage']) ? $options['storage'] : null
    );
}
$ok = $runtime['errors'] === [] && ($report['database'] === null || $report['database']['ok']);
$ok = $ok && ($report['storage_protection'] === null || $report['storage_protection']['ok']);
if (isset($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo sprintf("Runtime: PHP %s, %d bits, SQLite %s\n", $runtime['php'], $runtime['bits'], $runtime['sqlite']);
    foreach ($runtime['errors'] as $error) { echo $error . PHP_EOL; }
    foreach ($report['storage_protection']['errors'] ?? [] as $error) { echo $error . PHP_EOL; }
    if ($report['database'] !== null) {
        echo sprintf("Banco: esquema %d, %d PDF(s) referenciado(s).\n", $report['database']['schema'], $report['database']['documents']);
        foreach ($report['database']['errors'] as $error) { echo $error . PHP_EOL; }
    } else {
        echo "Banco não verificado. Para conferir uma restauração, use --database=CAMINHO --storage=DIRETORIO_PDF.\n";
    }
    echo $ok ? "Verificações solicitadas aprovadas.\n" : "Há verificações reprovadas.\n";
    echo "O ensaio não migra o banco nem atesta TLS, SMTP, custódia de chaves ou aceite institucional.\n";
}
exit($ok ? 0 : 1);
