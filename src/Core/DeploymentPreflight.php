<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseInitializer.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SqliteJournalPolicy.php';
require_once dirname(__DIR__) . '/Services/CertidaoStorage.php';

/** Read-only verification of a restored database and its referenced private PDFs. */
final class DeploymentPreflight
{
    /** @return array{ok:bool,schema:int,documents:int,errors:list<string>} */
    public static function database(string $path, ?string $storageDirectory): array
    {
        $result = ['ok' => false, 'schema' => 0, 'documents' => 0, 'errors' => []];
        try {
            CertidaoStorage::rejectLinks($path);
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('database.unavailable');
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA query_only=ON');
            $pdo->exec('PRAGMA busy_timeout=5000');
            try {
                SqliteJournalPolicy::configure($pdo, false, Config::string('DB_JOURNAL_MODE', 'auto'));
            } catch (Throwable) {
                $result['errors'][] = 'O modo do banco precisa de ajuste por CLI em manutenção para esta biblioteca SQLite. WAL sem correção não pode ser liberado.';
            }
            $result['schema'] = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            \src\Core\DatabaseInitializer::assertCurrent($pdo);
            if ($pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']
                || $pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                $result['errors'][] = 'A integridade ou os vínculos do banco precisam de revisão.';
            }
            $legacyDocuments = (int) $pdo->query("SELECT COUNT(*) FROM certidoes
                WHERE (pdf_privado IS NULL OR trim(pdf_privado) = '')
                  AND arquivo_pdf IS NOT NULL AND trim(arquivo_pdf) <> ''")->fetchColumn();
            $result['documents'] = $legacyDocuments;
            if ($legacyDocuments > 0) {
                $result['errors'][] = sprintf('%d PDF(s) legado(s) ainda exigem migração para armazenamento privado antes do aceite da restauração.', $legacyDocuments);
            }
            $documents = $pdo->query("SELECT pdf_privado,pdf_bytes,pdf_sha256 FROM certidoes
                WHERE pdf_privado IS NOT NULL AND trim(pdf_privado) <> ''");
            $storage = null;
            $badDocuments = 0;
            foreach ($documents as $document) {
                $result['documents']++;
                try {
                    if ($storage === null) {
                        if ($storageDirectory === null || !is_dir($storageDirectory)) {
                            throw new RuntimeException('storage.unavailable');
                        }
                        $storage = new CertidaoStorage($storageDirectory);
                    }
                    $pdf = $storage->path((string) $document['pdf_privado']);
                    $hash = hash_file('sha256', $pdf);
                    if (filesize($pdf) !== (int) $document['pdf_bytes'] || $hash === false
                        || !hash_equals((string) $document['pdf_sha256'], $hash)) {
                        $badDocuments++;
                    }
                } catch (Throwable) {
                    $badDocuments++;
                }
            }
            if ($badDocuments > 0) {
                $result['errors'][] = sprintf('%d PDF(s) referenciado(s) ausente(s) ou com integridade divergente. Confira o mesmo ponto de recuperação do banco.', $badDocuments);
            }
            $result['ok'] = $result['errors'] === [];
        } catch (Throwable) {
            $result['errors'][] = 'Não foi possível conferir o banco no modo somente leitura. Confira arquivo, permissões e versão do esquema; migrações exigem manutenção por CLI.';
        }
        return $result;
    }
}
