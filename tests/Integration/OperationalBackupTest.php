<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use src\Core\DatabaseInitializer;
use Tests\Support\DatabaseTestCase;

final class OperationalBackupTest extends DatabaseTestCase
{
    public function testJointSqliteAndPdfSnapshotRestoresIdsLinksHashesAndAudit(): void
    {
        $root = sys_get_temp_dir() . '/gse-backup-rehearsal-' . bin2hex(random_bytes(8));
        mkdir($root . '/backup/certidoes', 0700, true);
        mkdir($root . '/restored/certidoes', 0700, true);
        $restored = null;
        try {
            $actor = $this->insertUsuario('Administrador Backup');
            $storage = new \CertidaoStorage($root . '/live');
            $model = new \Certidao();
            $supplier = $model->saveOption('fornecedor', null, 'Fornecedor Ficticio', true, $actor);
            $type = $model->saveOption('tipo', null, 'Fiscal', true, $actor);
            file_put_contents($root . '/fixture.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
            $metadata = $storage->locked(function () use ($storage, $root): array {
                return $storage->importLocal($root . '/fixture.pdf', 'Certidao Ficticia.pdf');
            });
            $id = $storage->locked(function () use ($model, $metadata, $actor, $storage, $supplier, $type): int {
                return $model->createStored([
                    'id_fornecedor' => $supplier, 'id_tipo_certidao' => $type,
                    'data_emissao' => '2026-01-01', 'data_vencimento' => '2026-12-31', 'observacao' => 'Ficticia',
                ], $metadata, $actor, null, $storage);
            });
            $before = $this->snapshot($this->pdo);

            // All writers are quiescent; VACUUM INTO includes committed WAL data.
            $this->pdo->exec('VACUUM main INTO ' . $this->pdo->quote($root . '/backup/database.sqlite'));
            $key = $metadata['key'];
            $this->assertTrue(copy($storage->path($key), $root . '/backup/certidoes/' . $key));
            $this->assertSame($metadata['hash'], hash_file('sha256', $root . '/backup/certidoes/' . $key));

            // A later live change is absent from the restored point in time.
            $model->transition($id, 'archive', 1, $actor);
            $this->assertSame('arquivada', $model->buscarPorId($id)['estado']);
            $this->assertTrue(copy($root . '/backup/database.sqlite', $root . '/restored/database.sqlite'));
            $this->assertTrue(copy($root . '/backup/certidoes/' . $key, $root . '/restored/certidoes/' . $key));
            $restored = new PDO('sqlite:' . $root . '/restored/database.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $restored->exec('PRAGMA foreign_keys=ON');
            DatabaseInitializer::initialize($restored);
            DatabaseInitializer::initialize($restored);
            $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $restored->query('PRAGMA foreign_key_check')->fetchAll());
            $this->assertSame($before, $this->snapshot($restored));
            $row = $restored->query('SELECT * FROM certidoes')->fetch();
            $restoredStorage = new \CertidaoStorage($root . '/restored/certidoes');
            $this->assertSame($metadata['hash'], hash_file('sha256', $restoredStorage->path($row['pdf_privado'])));
            $this->assertSame($metadata['bytes'], filesize($restoredStorage->path($row['pdf_privado'])));
            $this->assertSame($id, (int) $row['id']);
            $this->assertSame(0, (int) $row['arquivado']);
        } finally {
            $restored = null;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (['usuarios', 'lista_fornecedores', 'lista_tipos_certidao', 'certidoes', 'security_audit', 'sqlite_sequence', 'schema_migrations'] as $table) {
            $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
        }
        $snapshot['user_version'] = $pdo->query('PRAGMA user_version')->fetchColumn();
        return $snapshot;
    }
}
