<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Tests\Support\DatabaseTestCase;

$preflightFile = ROOT_PATH . '/src/Core/DeploymentPreflight.php';
if (is_file($preflightFile)) { require_once $preflightFile; }

final class DeploymentPreflightTest extends DatabaseTestCase
{
    public function testInspectionChecksSchemaAndPdfWithoutWritingOrCreatingMissingDatabase(): void
    {
        self::assertTrue(class_exists(\DeploymentPreflight::class), 'O ensaio de restauração deve conferir banco e PDFs sem migrar.');
        $path = (string) $_ENV['DB_PATH'];
        $before = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        $report = \DeploymentPreflight::database($path, null);
        self::assertTrue($report['ok']);
        self::assertSame($before, (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());
        $missing = dirname($path) . '/gse-preflight-missing-' . bin2hex(random_bytes(8)) . '.sqlite';
        self::assertFalse(\DeploymentPreflight::database($missing, null)['ok']);
        self::assertFileDoesNotExist($missing);
    }

    public function testCorruptOrMissingReferencedDocumentCannotPassRestorationCheck(): void
    {
        self::assertTrue(class_exists(\DeploymentPreflight::class), 'É necessário conferir a integridade dos PDFs restaurados.');
        $actor = $this->insertUsuario('Operador sintetico');
        $storageDir = sys_get_temp_dir() . '/gse-preflight-docs-' . bin2hex(random_bytes(8));
        mkdir($storageDir, 0700);
        $storage = new \CertidaoStorage($storageDir);
        $model = new \Certidao();
        $supplier = $model->saveOption('fornecedor', null, 'Fornecedor sintetico', true, $actor);
        $type = $model->saveOption('tipo', null, 'Documento sintetico', true, $actor);
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        $input = $storageDir . '/input.pdf';
        file_put_contents($input, $pdf);
        $metadata = $storage->importLocal($input, 'Sintetico.pdf');
        try {
            $model->createStored(['id_fornecedor' => $supplier, 'id_tipo_certidao' => $type, 'data_emissao' => '2026-01-01', 'data_vencimento' => '2027-01-01', 'observacao' => ''], $metadata, $actor, null, $storage);
            $this->pdo->exec("UPDATE certidoes SET arquivo_pdf='already-migrated-legacy.pdf'");
            $path = (string) $_ENV['DB_PATH'];
            self::assertFalse(\DeploymentPreflight::database($path, null)['ok'], 'Banco com PDFs exige o armazenamento na conferência conjunta.');
            self::assertTrue(\DeploymentPreflight::database($path, $storageDir)['ok']);
            file_put_contents($storage->path($metadata['key']), 'damaged');
            self::assertFalse(\DeploymentPreflight::database($path, $storageDir)['ok']);
        } finally {
            foreach (glob($storageDir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($storageDir);
        }
    }

    public function testUnmigratedLegacyReferencesFailForCurrentAndHistoricalCertificates(): void
    {
        $this->pdo->exec("INSERT INTO lista_fornecedores(id,nome) VALUES(7,'Fornecedor sintetico');
            INSERT INTO lista_tipos_certidao(id,nome) VALUES(5,'Tipo sintetico');
            INSERT INTO certidoes(id,id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivo_pdf,arquivado,status)
            VALUES(42,7,5,'2026-01-01','2027-01-01','missing-legacy.pdf',0,1)");
        foreach ([0, 1] as $archived) {
            $this->pdo->exec('UPDATE certidoes SET arquivado=' . $archived);
            $report = \DeploymentPreflight::database((string) $_ENV['DB_PATH'], null);
            self::assertFalse($report['ok'], 'Referências legadas também exigem recuperação/migração privada.');
            self::assertSame(1, $report['documents']);
            self::assertStringContainsString('legado', implode(' ', $report['errors']));
            self::assertStringNotContainsString('missing-legacy.pdf', implode(' ', $report['errors']));
        }
    }

    public function testInspectionRejectsJournalThatRequiresMaintenanceWithoutChangingIt(): void
    {
        $previous = $_ENV['DB_JOURNAL_MODE'] ?? null;
        $this->pdo->query('PRAGMA journal_mode=WAL')->fetchColumn();
        $_ENV['DB_JOURNAL_MODE'] = 'delete';
        try {
            $report = \DeploymentPreflight::database((string) $_ENV['DB_PATH'], null);
            self::assertFalse($report['ok']);
            self::assertStringContainsString('modo', implode(' ', $report['errors']));
            self::assertSame('wal', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        } finally {
            if ($previous === null) { unset($_ENV['DB_JOURNAL_MODE']); } else { $_ENV['DB_JOURNAL_MODE'] = $previous; }
        }
    }
}
