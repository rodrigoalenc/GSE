<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/Core/RuntimeRequirements.php';
$report = RuntimeRequirements::inspect();
if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo sprintf("PHP %s (%d bits); SQLite PDO %s\n", $report['php'], $report['bits'], $report['sqlite']);
    if ($report['php_backport'] !== null) { echo 'Correção Ubuntu comprovada: ' . $report['php_backport'] . PHP_EOL; }
    foreach ($report['errors'] as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    echo $report['errors'] === [] ? "Ambiente compatível. Nenhum banco da aplicação foi aberto.\n" : "Ambiente incompatível; corrija antes de iniciar o GSE.\n";
}
exit($report['errors'] === [] ? 0 : 1);
