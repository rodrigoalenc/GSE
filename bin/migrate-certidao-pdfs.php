<?php

declare(strict_types=1);

require_once __DIR__ . '/certidao-bootstrap.php';

use src\Core\SqliteTransaction;

$options = getopt('', ['source:', 'backup:', 'actor:', 'apply', 'offline-confirmed']);
if (!isset($options['source'])) {
    fwrite(STDERR,"Uso: php bin/migrate-certidao-pdfs.php --source=CAMINHO_ABSOLUTO [--apply --offline-confirmed --backup=DIRETORIO_NOVO --actor=ID_ADMIN]\nPadrão: simulação. Nunca remove o arquivo original.\n"); exit(1);
}
try {
    $sourceInput = is_string($options['source']) ? $options['source'] : '';
    CertidaoStorage::rejectLinks($sourceInput);
    $source = realpath($sourceInput);
    if ($source === false || !is_dir($source)) { throw new RuntimeException('Origem inválida.'); }
    $pdo = src\Core\Database::getConnection();
    src\Core\DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);
    $storage = new CertidaoStorage();
    $apply = isset($options['apply']);
    $actor = 0; $backup = '';
    if ($apply) {
        $actor = Certidao::id($options['actor'] ?? null);
        $q = $pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND ativo=1 AND tipo='administrador'"); $q->execute([$actor]);
        if ($q->fetchColumn() === false || !isset($options['offline-confirmed']) || !is_string($options['backup'] ?? null)) { throw new RuntimeException('Exige administrador, modo offline e backup novo.'); }
        $q->closeCursor();
        $backup = $options['backup'];
        CertidaoStorage::rejectLinks($backup);
        if (file_exists($backup)) { throw new RuntimeException('Use diretório novo para backup.'); }
        new CertidaoStorage($backup); // Enforces absolute, private, non-symlink destination.
        $pdo->exec('VACUUM main INTO ' . $pdo->quote($backup . '/database.sqlite'));
        $check = new PDO('sqlite:'.$backup.'/database.sqlite');
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new RuntimeException('Backup inválido.'); }
        $check = null;
    }
    $failures = 0;
    foreach ($pdo->query("SELECT id,arquivo_pdf FROM certidoes WHERE pdf_privado IS NULL AND arquivo_pdf IS NOT NULL AND arquivo_pdf <> '' ORDER BY id")->fetchAll() as $row) {
        $id = (int)$row['id']; $name = (string)$row['arquivo_pdf'];
        // Never interpret a legacy path as filesystem authority. Unusual paths require manual mapping.
        if (basename($name) !== $name || str_contains($name,'\\') || preg_match('/^[^\x00-\x1f\/\\\\.]+\.pdf$/iuD',$name) !== 1) {
            fwrite(STDOUT,"#{$id}: caminho legado exige mapeamento manual; preservado.\n"); $failures++; continue;
        }
        $path = $source . '/' . $name;
        try {
            CertidaoStorage::rejectLinks($path);
            if (!is_file($path)) { throw new RuntimeException('Arquivo ausente.'); }
            if (!$apply) { fwrite(STDOUT,"#{$id}: candidato à cópia privada; validação completa durante aplicação.\n"); continue; }
            $backupFile = $backup . '/' . $id . '.pdf';
            if (!copy($path,$backupFile) || hash_file('sha256',$path) !== hash_file('sha256',$backupFile)) { throw new RuntimeException('Backup PDF inválido.'); }
            $storage->locked(function () use ($storage,$path,$name,$pdo,$id,$actor): void {
                $pdf = $storage->importLocal($path,$name);
                try {
                    SqliteTransaction::immediate($pdo,function (PDO $pdo) use ($id,$pdf,$actor): void {
                        $q = $pdo->prepare('UPDATE certidoes SET pdf_privado=?,pdf_nome=?,pdf_bytes=?,pdf_sha256=?,atualizado_por=?,atualizado_em=?,revisao=revisao+1 WHERE id=? AND pdf_privado IS NULL');
                        $q->execute([$pdf['key'],$pdf['name'],$pdf['bytes'],$pdf['hash'],$actor,gmdate('Y-m-d H:i:s'),$id]);
                        if ($q->rowCount() !== 1) { throw new RuntimeException('Registro modificado.'); }
                        AuditLogger::recordRequired($pdo,'certidao.pdf_migrated',AuditLogger::SUCCESS,$actor,null,'Cópia privada validada; original preservado.','certidao',$id);
                    });
                } catch (Throwable $e) { $storage->compensate($pdf['key'],$pdo); throw $e; }
            });
            fwrite(STDOUT,"#{$id}: cópia privada validada.\n");
        } catch (Throwable $e) { TechnicalLogger::error('certidao_pdf_migration_failed',['exception'=>$e::class]); fwrite(STDOUT,"#{$id}: falha; original preservado, revisar log.\n"); $failures++; }
    }
    exit($failures > 0 ? 2 : 0);
} catch (Throwable $e) {
    TechnicalLogger::error('certidao_pdf_migration_failed',['exception'=>$e::class]);
    fwrite(STDERR,"Migração interrompida. Verifique parâmetros, backup e log técnico.\n"); exit(1);
}
