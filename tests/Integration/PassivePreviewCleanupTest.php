<?php
declare(strict_types=1);
namespace Tests\Integration;

use src\Core\Maintenance;
use Tests\Support\DatabaseTestCase;

final class PassivePreviewCleanupTest extends DatabaseTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-preview-cleanup-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $_ENV['PASSIVO_IMPORT_DIRECTORY'] = $this->directory . DIRECTORY_SEPARATOR . 'private';
        $_ENV['PASSIVO_LEGACY_IMPORT_DIRECTORY'] = $this->directory . DIRECTORY_SEPARATOR . 'gse-passive-imports';
    }

    protected function tearDown(): void
    {
        foreach (['private','gse-passive-imports',''] as $child) {
            $path = $child === '' ? $this->directory : $this->directory . DIRECTORY_SEPARATOR . $child;
            if (!is_dir($path) || is_link($path)) { continue; }
            foreach (new \DirectoryIterator($path) as $file) {
                if (!$file->isDot() && !$file->isDir()) { unlink($file->getPathname()); }
            }
            rmdir($path);
        }
        unset($_ENV['PASSIVO_IMPORT_DIRECTORY'], $_ENV['PASSIVO_LEGACY_IMPORT_DIRECTORY']);
        parent::tearDown();
    }

    public function testBlankOptionalDirectoryUsesThePrivateDefaultWithoutCreatingStorage(): void
    {
        $previousDatabase = $_ENV['DB_PATH'];
        $database = $this->directory . DIRECTORY_SEPARATOR . 'not-created.sqlite';
        $_ENV['DB_PATH'] = $database;
        $_ENV['PASSIVO_IMPORT_DIRECTORY'] = '';
        try {
            $method = new \ReflectionMethod(\PassivoCsvService::class, 'privateDirectory');
            $this->assertNull($method->invoke(new \PassivoCsvService(), false));
            $this->assertFileDoesNotExist($database);
            $this->assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '.gse-passive-imports-*'));
        } finally { $_ENV['DB_PATH'] = $previousDatabase; }
    }

    public function testScheduledMaintenanceDeletesExpiredPreviewsWithoutAnySessionOrNewImport(): void
    {
        $actor = $this->insertUsuario('Admin Limpeza CSV');
        $source = $this->directory . DIRECTORY_SEPARATOR . 'source.csv';
        file_put_contents($source, "Nome;Data;Numero;Caixa\nAluno Ficticio;2000-01-01;1;CX\n");
        $service = new \PassivoCsvService();
        $first = $service->previewTrustedFile($source,$actor,new \Passivo());
        $fresh = $service->previewTrustedFile($source,$actor,new \Passivo());
        $this->assertIsArray($first); $this->assertIsArray($fresh);
        $expiredPath = $_SESSION['passivo_import_previews'][$first['token']]['path'];
        $freshPath = $_SESSION['passivo_import_previews'][$fresh['token']]['path'];
        touch($expiredPath, time()-\PassivoCsvService::PREVIEW_TTL-10);
        $foreign = dirname($expiredPath) . DIRECTORY_SEPARATOR . 'foreign.csv';
        file_put_contents($foreign,'arquivo alheio'); touch($foreign,time()-86400);
        $_SESSION = [];
        $result = (new Maintenance($this->pdo))->run();
        $this->assertSame(1,$result['passive_previews']);
        $this->assertFileDoesNotExist($expiredPath);
        $this->assertFileExists($freshPath);
        $this->assertFileExists($foreign);
        $this->assertSame(0,(new Maintenance($this->pdo))->run()['passive_previews']);
    }

    public function testLegacyCleanupRequiresOwnershipOrApprovalAndPreservesUnrecognizedFiles(): void
    {
        $legacy = $_ENV['PASSIVO_LEGACY_IMPORT_DIRECTORY'];
        mkdir($legacy,0755); chmod($legacy,0755);
        $recognized = $legacy . DIRECTORY_SEPARATOR . str_repeat('a',48) . '.csv';
        $foreign = $legacy . DIRECTORY_SEPARATOR . 'institutional.csv';
        file_put_contents($recognized,'teste'); file_put_contents($foreign,'preservar');
        touch($recognized,time()-86400); touch($foreign,time()-86400);
        $service = new \PassivoCsvService();
        try { $service->cleanupLegacyExpiredFiles(); $this->fail('Diretório sem propriedade comprovada exige aprovação.'); }
        catch (\LegacyPassivePreviewsPending) { $this->assertFileExists($recognized); }
        $this->assertSame(1,$service->cleanupLegacyExpiredFiles(null,true));
        $this->assertFileDoesNotExist($recognized); $this->assertFileExists($foreign);
    }

    public function testForgedExpiredSessionCannotDeleteAFileOutsideTheOwnedDirectory(): void
    {
        $outside = $this->directory . DIRECTORY_SEPARATOR . str_repeat('b',48) . '.csv';
        file_put_contents($outside,'arquivo externo');
        $token = str_repeat('a',64);
        $_SESSION['passivo_import_previews'][$token] = ['actor_id'=>1,'expires_at'=>time()-1,'path'=>$outside];
        $this->assertFalse((new \PassivoCsvService())->confirm($token,1,new \Passivo()));
        $this->assertFileExists($outside);
    }
}
