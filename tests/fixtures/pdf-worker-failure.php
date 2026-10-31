<?php
declare(strict_types=1);

switch ($argv[1] ?? '') {
    case 'timeout': usleep(5_000_000); break;
    case 'crash': exit(7);
    case 'invalid': echo 'not JSON'; break;
    case 'false-success': echo '{"ok":true,"report":{}}'; exit(7);
    case 'memory': $large = str_repeat('x', 220 * 1024 * 1024); break;
    case 'output': echo str_repeat('x', 9 * 1024 * 1024); break;
    default:
        echo json_encode(['ok' => true, 'report' => ['memory' => ini_get('memory_limit'), 'secret' => getenv('GSE_PDF_TEST_SECRET')]], JSON_THROW_ON_ERROR);
}
