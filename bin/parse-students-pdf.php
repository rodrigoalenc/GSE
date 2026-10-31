<?php

declare(strict_types=1);

// Do not load bootstrap.php: PDF content never needs credentials or a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
// Check the executable before loading the PDF library or untrusted bytes.
if (($argv[2] ?? '') !== (string) PHP_VERSION_ID) {
    echo '{"ok":false,"message":"O leitor PDF precisa usar a mesma versão PHP do servidor."}';
    exit(4);
}
define('GSE_PDF_WORKER', true);
require_once dirname(__DIR__) . '/src/Services/AlunoPdfParser.php';

try {
    $report = (new AlunoPdfParser())->parseFileInWorker($argv[1] ?? '');
    echo json_encode(['ok' => true, 'report' => $report], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $exception) {
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    exit(2);
} catch (Throwable) {
    echo '{"ok":false,"message":"Não foi possível concluir a leitura segura deste PDF."}';
    exit(3);
}
