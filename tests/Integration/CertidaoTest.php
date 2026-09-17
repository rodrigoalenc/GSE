<?php
declare(strict_types=1);
namespace Tests\Integration;
use Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CertidaoTest extends DatabaseTestCase
{
    private string $root;
    private \CertidaoStorage $storage;
    private \Certidao $model;
    private int $actor;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root=sys_get_temp_dir().'/gse-cert-test-'.bin2hex(random_bytes(6));
        $this->storage=new \CertidaoStorage($this->root);
        $this->model=new \Certidao(new \CertidaoStatus(new \DateTimeImmutable('2026-09-16T12:00:00Z')));
        $this->actor=$this->insertUsuario('Funcionario Certidao','funcionario');
        $this->model->saveOption('fornecedor',null,'Fornecedor Á',true,$this->actor);
        $this->model->saveOption('tipo',null,'Fiscal',true,$this->actor);
        file_put_contents($this->root.'/sample.pdf',"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
    }
    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
        if (is_file($this->root.'/.storage.lock')) { unlink($this->root.'/.storage.lock'); }
        rmdir($this->root); parent::tearDown();
    }
    private function data(): array { return ['id_fornecedor'=>1,'id_tipo_certidao'=>1,'data_emissao'=>'2026-09-01','data_vencimento'=>'2026-09-20','observacao'=>'Reservada','revisao'=>1]; }
    private function create(?int $previous=null,array $changes=[]): int
    {
        return $this->storage->locked(function () use ($previous,$changes): int {
            $pdf=$this->storage->importLocal($this->root.'/sample.pdf','Documento.pdf');
            try { return $this->model->createStored(array_replace($this->data(),$changes),$pdf,$this->actor,$previous,$this->storage); }
            catch (\Throwable $e) { $this->storage->compensate($pdf['key'],$this->pdo); throw $e; }
        });
    }
    public function testEmployeeLifecyclePreservesMultiplicityAndPdfHistory(): void
    {
        $first=$this->create(); $other=$this->create(); $before=$this->model->buscarPorId($first); $new=$this->create($first);
        $this->assertSame('arquivada',$this->model->buscarPorId($first)['estado']);
        $this->assertSame('corrente',$this->model->buscarPorId($other)['estado']);
        $this->assertSame($first,$this->model->buscarPorId($new)['anterior_id']);
        $this->assertSame($before['pdf_privado'],$this->model->buscarPorId($first)['pdf_privado']);
        $this->assertFileExists($this->storage->path($before['pdf_privado']));
        $pdf=$this->model->buscarPorId($new)['pdf_privado'];
        $this->model->updateMetadata($new,array_replace($this->data(),['observacao'=>'Corrigida']),$this->actor);
        $this->assertSame($pdf,$this->model->buscarPorId($new)['pdf_privado']);
        $this->model->transition($new,'delete',2,$this->actor);
        $this->assertSame('excluida',$this->model->buscarPorId($new)['estado']);
        $this->assertFileExists($this->storage->path($pdf));
        $this->assertSame(1,$this->model->paginate(['estado'=>'arquivada'])['total']);
        $this->assertSame(1,$this->model->paginate(['estado'=>'excluida'])['total']);
        $this->assertSame(1,$this->model->paginate()['total']);
        $audit=$this->pdo->query("SELECT * FROM security_audit WHERE resource_type='certidao'")->fetchAll();
        $this->assertCount(6,$audit); $this->assertNotContains('Reservada',array_column($audit,'description'));
    }
    public function testRenewalAuditFailureRollsBackOldNewAndNewFile(): void
    {
        $id=$this->create(); $old=$this->model->buscarPorId($id); $files=glob($this->root.'/*.pdf');
        $this->pdo->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON security_audit WHEN NEW.action='certidao.renewed' BEGIN SELECT RAISE(ABORT,'forced'); END");
        try { $this->create($id); $this->fail('Expected rollback'); } catch (\PDOException) {}
        $this->assertSame($old,$this->model->buscarPorId($id)); $this->assertSame($files,glob($this->root.'/*.pdf'));
        $this->assertSame(1,$this->model->paginate()['total']);
    }
    public function testRepeatedRenewalAndStaleMetadataAreRejected(): void
    {
        $id=$this->create(); $this->create($id);
        try { $this->create($id); $this->fail('Repeated renewal'); } catch (\DomainException) {}
        $current=$this->create(); $this->model->updateMetadata($current,$this->data(),$this->actor);
        $this->expectException(\DomainException::class); $this->model->updateMetadata($current,$this->data(),$this->actor);
    }
    public function testInactiveOptionsPreventNewDocumentsButPreserveHistory(): void
    {
        $id=$this->create(); $this->model->saveOption('fornecedor',1,'Fornecedor Á',false,$this->actor);
        $this->model->updateMetadata($id,$this->data(),$this->actor);
        $this->assertSame('Fornecedor Á',$this->model->buscarPorId($id)['fornecedor']);
        $this->expectException(\DomainException::class); $this->create();
    }
    public function testUnicodeDuplicateAndInvalidTableAreRejected(): void
    {
        try { $this->model->saveOption('fornecedor',null,"  FORNECEDOR A\u{0301} ",true,$this->actor); $this->fail('Duplicate'); } catch (\DomainException) {}
        $this->assertCount(1,$this->model->options('fornecedor','á'));
        $this->expectException(\DomainException::class); $this->model->options('usuarios');
    }
    public function testAuditFailureRollsBackMetadataArchiveDeletionAndOptions(): void
    {
        $id=$this->create(); $before=$this->model->buscarPorId($id);
        $this->pdo->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON security_audit BEGIN SELECT RAISE(ABORT,'forced'); END");
        foreach ([fn()=> $this->model->transition($id,'archive',1,$this->actor),fn()=> $this->model->transition($id,'delete',1,$this->actor),fn()=> $this->model->updateMetadata($id,$this->data(),$this->actor),fn()=> $this->model->saveOption('tipo',1,'Modificado',true,$this->actor)] as $operation) {
            try { $operation(); $this->fail('Expected audit failure'); } catch (\PDOException) {}
            $this->assertSame($before,$this->model->buscarPorId($id));
        }
        $this->assertSame('Fiscal',$this->model->options('tipo')[0]['nome']);
    }
    #[DataProvider('invalidFields')]
    public function testInvalidFieldsLeaveNoNewRecordOrPdf(array $changes): void
    {
        $files=glob($this->root.'/*.pdf');
        try { $this->create(null,$changes); $this->fail('Invalid input accepted'); } catch (\DomainException) {}
        $this->assertSame(0,$this->model->paginate()['total']); $this->assertSame($files,glob($this->root.'/*.pdf'));
    }
    public static function invalidFields(): array
    {
        return [[['id_fornecedor'=>999]],[['id_tipo_certidao'=>0]],[['data_emissao'=>'2026-02-30']],[['data_vencimento'=>'2025-09-01']],[['data_vencimento'=>'2026-9-20']],[['observacao'=>str_repeat('x',2001)]]];
    }
    public function testExpiredDocumentsStayCurrentAndFiltersPaginationHaveDefinedScope(): void
    {
        $this->create(null,['data_vencimento'=>'2026-09-01']); $this->create(); $this->create();
        $this->assertSame(1,$this->model->summary()['vencida']); $this->assertSame(3,$this->model->paginate()['total']);
        $this->assertSame(1,$this->model->paginate(['validade'=>'vencida'])['total']); $this->assertSame(0,$this->model->paginate(['ano'=>'2025'])['total']);
        $page=$this->model->paginate(['ano'=>'todos','fornecedor'=>1,'tipo'=>1],2,2);
        $this->assertCount(1,$page['items']); $this->assertSame(2,$page['pages']);
    }
    public function testUploadRequiresHttpOriginAndActorMustBeActive(): void
    {
        foreach ([[],['error'=>UPLOAD_ERR_PARTIAL],['error'=>UPLOAD_ERR_OK,'tmp_name'=>$this->root.'/sample.pdf','name'=>'Documento.pdf']] as $upload) {
            try { $this->model->create($this->data(),$upload,$this->actor,null,$this->storage); $this->fail('Bad upload'); } catch (\DomainException) {}
        }
        $this->pdo->exec('UPDATE usuarios SET ativo=0 WHERE id='.$this->actor);
        $this->expectException(\DomainException::class); $this->create();
    }
    public function testPdfStorageRejectsNamesContentSizeAndPublicDirectory(): void
    {
        foreach (['../x.pdf','x.php.pdf','x.pdf.exe',"x\r\n.pdf",'x\\a.pdf'] as $name) {
            try { $this->storage->importLocal($this->root.'/sample.pdf',$name); $this->fail('Bad name'); } catch (\DomainException) {}
        }
        file_put_contents($this->root.'/bad.pdf','<html>Not a PDF</html>');
        try { $this->storage->importLocal($this->root.'/bad.pdf','bad.pdf'); $this->fail('Bad content'); } catch (\DomainException) {}
        $small=new \CertidaoStorage($this->root,8);
        try { $small->importLocal($this->root.'/sample.pdf','ok.pdf'); $this->fail('Too large'); } catch (\DomainException) {}
        $this->expectException(\RuntimeException::class); new \CertidaoStorage(ROOT_PATH.'/public/uploads');
    }
    public function testReconciliationPreservesReferencedAndRecentFiles(): void
    {
        $id=$this->create(); $referenced=$this->storage->path($this->model->buscarPorId($id)['pdf_privado']); touch($referenced,time()-172800);
        $orphan=$this->storage->importLocal($this->root.'/sample.pdf','orphan.pdf'); $this->assertSame([],$this->storage->reconcile($this->pdo));
        touch($this->storage->path($orphan['key']),time()-172800);
        $this->assertCount(1,$this->storage->reconcile($this->pdo)); $this->assertFileExists($referenced); $this->assertFileExists($this->storage->path($orphan['key']));
    }
    public function testLegacyDiagnosticsPreserveAmbiguousAndMissingDocuments(): void
    {
        $this->pdo->exec("INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivado,status) VALUES(1,1,'bad','2026-02-30',1,1)");
        $this->pdo->exec("INSERT INTO lista_fornecedores(nome) VALUES('FORNECEDOR Á')");
        $this->assertCount(4,$this->model->diagnostics()); $this->assertSame(1,$this->model->paginate(['estado'=>'arquivada'])['total']);
        $this->assertSame('bad',$this->model->buscarPorId(1)['data_emissao']);
    }

    public function testRenewalRejectsDifferentSupplierAndConcurrentWriter(): void
    {
        $id=$this->create(); $this->model->saveOption('fornecedor',null,'Fornecedor B',true,$this->actor);
        try { $this->create($id,['id_fornecedor'=>2]); $this->fail('Incompatible renewal'); } catch (\DomainException) {}
        $db=$this->pdo->query('PRAGMA database_list')->fetchAll()[0]['file'];
        $other=new \PDO('sqlite:'.$db,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $other->exec('PRAGMA busy_timeout=10');
        $blocked=false;
        $this->pdo->sqliteCreateFunction('concurrent_renewal',function () use ($other,$id,&$blocked): int {
            \Model::setConexao($other);
            try {
                $competitor=new \Certidao();
                $existing=$this->pdo->query('SELECT * FROM certidoes WHERE id='.$id)->fetch();
                $pdf=['key'=>$existing['pdf_privado'],'name'=>$existing['pdf_nome'],'bytes'=>$existing['pdf_bytes'],'hash'=>$existing['pdf_sha256']];
                $competitor->createStored($this->data(),$pdf,$this->actor,$id,$this->storage);
            } catch (\PDOException $e) { $blocked=str_contains($e->getMessage(),'locked'); }
            finally { \Model::setConexao($this->pdo); }
            return 1;
        },0);
        $this->pdo->exec('CREATE TRIGGER contender BEFORE UPDATE OF arquivado ON certidoes BEGIN SELECT concurrent_renewal(); END');
        $new=$this->create($id);
        $this->assertTrue($blocked); $this->assertSame($id,$this->model->buscarPorId($new)['anterior_id']);
        $this->assertSame(1,$this->model->paginate()['total']);
    }

    public function testChangedStoredFileAndUnavailableStorageCannotAlterPrevious(): void
    {
        $id=$this->create(); $before=$this->model->buscarPorId($id);
        $pdf=$this->storage->importLocal($this->root.'/sample.pdf','changed.pdf');
        file_put_contents($this->storage->path($pdf['key']),'corrupted');
        try { $this->model->createStored($this->data(),$pdf,$this->actor,$id,$this->storage); $this->fail('Changed file'); } catch (\DomainException) {}
        try { new \CertidaoStorage($this->root.'/sample.pdf'); $this->fail('File is not directory'); } catch (\RuntimeException) {}
        $this->assertSame($before,$this->model->buscarPorId($id));
        $this->assertSame(1,$this->model->paginate()['total']);
    }
}
