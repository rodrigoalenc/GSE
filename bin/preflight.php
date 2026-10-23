<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/src/Core/RuntimeRequirements.php';
require_once ROOT_PATH . '/src/Core/DeploymentPreflight.php';

$options = getopt('', ['database:', 'storage:', 'json']);
$runtime = RuntimeRequirements::inspect();
$report = ['runtime' => $runtime, 'database' => null, 'production_approved' => false];
if (isset($options['database']) && is_string($options['database'])) {
    $report['database'] = DeploymentPreflight::database(
        $options['database'],
        isset($options['storage']) && is_string($options['storage']) ? $options['storage'] : null
    );
}
$ok = $runtime['errors'] === [] && ($report['database'] === null || $report['database']['ok']);
if (isset($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo sprintf("Runtime: PHP %s, %d bits, SQLite %s\n", $runtime['php'], $runtime['bits'], $runtime['sqlite']);
    foreach ($runtime['errors'] as $error) { echo $error . PHP_EOL; }
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
