<?php

declare(strict_types=1);

require_once __DIR__ . '/certidao-bootstrap.php';

// This command inventories only; it never deletes documents, including orphans.
try {
    $pdo = src\Core\Database::getConnection();
    src\Core\DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);
    $model = new Certidao(); $storage = new CertidaoStorage();
    $issues = $model->diagnostics();
    foreach ($pdo->query('SELECT id,pdf_privado,pdf_sha256,pdf_bytes FROM certidoes WHERE pdf_privado IS NOT NULL')->fetchAll() as $row) {
        try {
            $path = $storage->path((string)$row['pdf_privado']);
            if (hash_file('sha256',$path) !== $row['pdf_sha256'] || filesize($path) !== (int)$row['pdf_bytes']) { throw new RuntimeException('Integrity'); }
        } catch (Throwable) { $issues[] = ['resource'=>'certidao','id'=>(int)$row['id'],'issue'=>'PDF privado ausente ou integridade divergente.']; }
    }
    fwrite(STDOUT,(string)json_encode(['diagnostics'=>$issues,'storage'=>$storage->reconcile($pdo)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($issues === [] ? 0 : 2);
} catch (Throwable $e) {
    TechnicalLogger::error('certidao_maintenance_failed',['exception'=>$e::class]);
    fwrite(STDERR,"Não foi possível concluir o inventário. Consulte o log técnico.\n"); exit(1);
}
