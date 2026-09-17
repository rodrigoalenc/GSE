<?php
declare(strict_types=1);
namespace Tests\Integration;
use Tests\Support\DatabaseTestCase;

final class CertidaoCliTest extends DatabaseTestCase
{
    private string $root;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root=sys_get_temp_dir().'/gse-cert-cli-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/source',0700,true);
    }
    protected function tearDown(): void
    {
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->root); parent::tearDown();
    }
    private function runCommand(array $arguments): array
    {
        $env=array_merge(getenv(),['APP_ENV'=>'testing','CERTIDAO_STORAGE_PATH'=>$this->root.'/private','MAIL_ENABLED'=>'false','CERTIDAO_MAIL_ENABLED'=>'false','LOG_PATH'=>$this->root.'/technical.log']);
        $process=proc_open([PHP_BINARY,...$arguments],[1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,$env);
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    }
    public function testControlledLegacyCopyHasSimulationBackupAuditAndIdempotence(): void
    {
        $actor=$this->insertUsuario('Admin CLI');
        $this->pdo->exec("INSERT INTO lista_fornecedores(nome) VALUES('Fornecedor'); INSERT INTO lista_tipos_certidao(nome) VALUES('Fiscal'); INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivo_pdf) VALUES(1,1,'2026-01-01','2026-02-01','Documento.pdf')");
        $pdf="%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        file_put_contents($this->root.'/source/Documento.pdf',$pdf);
        $dry=$this->runCommand(['bin/migrate-certidao-pdfs.php','--source='.$this->root.'/source']);
        $this->assertSame(0,$dry[0],$dry[1].$dry[2]); $this->assertNull($this->pdo->query('SELECT pdf_privado FROM certidoes')->fetchColumn());
        $apply=$this->runCommand(['bin/migrate-certidao-pdfs.php','--source='.$this->root.'/source','--apply','--offline-confirmed','--backup='.$this->root.'/backup','--actor='.$actor]);
        $this->assertSame(0,$apply[0],$apply[1].$apply[2]);
        $key=$this->pdo->query('SELECT pdf_privado FROM certidoes')->fetchColumn();
        $this->assertSame($pdf,file_get_contents($this->root.'/private/'.$key));
        $this->assertSame($pdf,file_get_contents($this->root.'/source/Documento.pdf'));
        $this->assertSame($pdf,file_get_contents($this->root.'/backup/1.pdf'));
        $backup=new \PDO('sqlite:'.$this->root.'/backup/database.sqlite');
        $this->assertSame('ok',$backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertNull($backup->query('SELECT pdf_privado FROM certidoes')->fetchColumn()); $backup=null;
        $this->assertSame('certidao.pdf_migrated',$this->pdo->query("SELECT action FROM security_audit WHERE resource_type='certidao'")->fetchColumn());
        $again=$this->runCommand(['bin/migrate-certidao-pdfs.php','--source='.$this->root.'/source']);
        $this->assertSame(0,$again[0]); $this->assertSame('',$again[1]);
        $inventory=$this->runCommand(['bin/certidoes-maintenance.php']);
        $this->assertSame(0,$inventory[0],$inventory[1].$inventory[2]);
        $this->assertSame([],json_decode($inventory[1],true)['diagnostics']);
    }
    public function testNotificationCliNeverSendsInTesting(): void
    {
        $result=$this->runCommand(['bin/notify-certidoes.php']);
        $this->assertSame(0,$result[0]); $this->assertStringContainsString('desabilitado',$result[1]);
    }
}
