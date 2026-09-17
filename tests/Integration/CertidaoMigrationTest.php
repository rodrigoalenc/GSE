<?php
declare(strict_types=1);
namespace Tests\Integration;
use Tests\Support\DatabaseTestCase;
use src\Core\DatabaseInitializer;

final class CertidaoMigrationTest extends DatabaseTestCase
{
    private function legacy(): \PDO
    {
        $pdo=new \PDO('sqlite::memory:',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $migration=new \ReflectionMethod(DatabaseInitializer::class,'runMigration');
        $schema=file_get_contents(ROOT_PATH.'/database/schema.sql');
        for ($version=1;$version<=12;$version++) { $migration->invoke(null,$pdo,$version,$schema); }
        return $pdo;
    }
    public function testV13PreservesLegacyIdsSequencesFlagsDatesAndPathsAndConverges(): void
    {
        $pdo=$this->legacy();
        $pdo->exec("INSERT INTO lista_fornecedores(id,nome) VALUES(7,'Fornecedor'),(8,'FORNECEDOR'); INSERT INTO lista_tipos_certidao(id,nome) VALUES(5,'Fiscal');
            INSERT INTO certidoes(id,id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivo_pdf,arquivado,status) VALUES(42,7,5,'bad','2026-02-30','legacy.pdf',1,1),(43,7,5,'2026-01-01','2026-02-02',NULL,0,1);
            UPDATE sqlite_sequence SET seq=90 WHERE name='certidoes'");
        $before=$pdo->query('SELECT id,id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivo_pdf,arquivado,status FROM certidoes')->fetchAll();
        DatabaseInitializer::initialize($pdo); DatabaseInitializer::initialize($pdo);
        $this->assertSame($before,$pdo->query('SELECT id,id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivo_pdf,arquivado,status FROM certidoes')->fetchAll());
        $this->assertSame(90,(int)$pdo->query("SELECT seq FROM sqlite_sequence WHERE name='certidoes'")->fetchColumn());
        $this->assertSame(14,(int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame([],$pdo->query('PRAGMA foreign_key_check')->fetchAll()); $this->assertSame('ok',$pdo->query('PRAGMA integrity_check')->fetchColumn());
        foreach (['certidoes','lista_fornecedores','lista_tipos_certidao','certidao_notification_deliveries'] as $table) { $this->assertSame($this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll(),$pdo->query('PRAGMA table_info('.$table.')')->fetchAll()); }
    }
    public function testMigrationFailureRestoresSchemaVersionAndForeignKeys(): void
    {
        $pdo=$this->legacy(); $before=$pdo->query('PRAGMA table_info(certidoes)')->fetchAll();
        $pdo->exec("CREATE TRIGGER fail13 BEFORE INSERT ON schema_migrations WHEN NEW.version=13 BEGIN SELECT RAISE(ABORT,'forced13'); END");
        try { DatabaseInitializer::initialize($pdo); $this->fail('Expected rollback'); } catch (\PDOException $e) { $this->assertStringContainsString('forced13',$e->getMessage()); }
        $this->assertSame($before,$pdo->query('PRAGMA table_info(certidoes)')->fetchAll());
        $this->assertSame(12,(int)$pdo->query('PRAGMA user_version')->fetchColumn()); $this->assertSame(1,(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame('ok',$pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testV14PreservesV13DataAndDeliveriesAndRollsBackFailedMigration(): void
    {
        $pdo=$this->legacy();
        (new \ReflectionMethod(DatabaseInitializer::class,'runMigration'))->invoke(null,$pdo,13,file_get_contents(ROOT_PATH.'/database/schema.sql'));
        $pdo->exec("INSERT INTO usuarios(id,nome,email,senha,tipo) VALUES(1,'Admin','a@example.test','unused','administrador');
            INSERT INTO lista_fornecedores(id,nome,ativo,atualizado_por) VALUES(40,'Inativo legado',0,1);
            INSERT INTO lista_tipos_certidao(id,nome) VALUES(8,'Tipo legado');
            INSERT INTO certidao_notification_deliveries VALUES('2026-09-16',1,'2026-09-16 12:00:00')");
        $before=$pdo->query('SELECT * FROM lista_fornecedores')->fetchAll();
        $delivered=$pdo->query('SELECT * FROM certidao_notification_deliveries')->fetchAll();
        $pdo->exec("CREATE TRIGGER fail14 BEFORE INSERT ON schema_migrations WHEN NEW.version=14 BEGIN SELECT RAISE(ABORT,'forced14'); END");
        try { DatabaseInitializer::initialize($pdo); $this->fail('Expected rollback'); }
        catch (\PDOException $e) { $this->assertStringContainsString('forced14',$e->getMessage()); }
        $this->assertSame(13,(int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame($before,$pdo->query('SELECT * FROM lista_fornecedores')->fetchAll());
        $pdo->exec('DROP TRIGGER fail14');
        DatabaseInitializer::initialize($pdo); DatabaseInitializer::initialize($pdo);
        $supplier=$pdo->query('SELECT * FROM lista_fornecedores')->fetch();
        $this->assertSame(1,$supplier['revisao']); unset($supplier['revisao']);
        $this->assertSame($before[0],$supplier);
        $this->assertSame(1,(int)$pdo->query('SELECT revisao FROM lista_tipos_certidao WHERE id=8')->fetchColumn());
        $this->assertSame($delivered,$pdo->query('SELECT * FROM certidao_notification_deliveries')->fetchAll());
        $this->assertSame(14,(int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(40,(int)$pdo->query("SELECT seq FROM sqlite_sequence WHERE name='lista_fornecedores'")->fetchColumn());
        $this->assertSame([],$pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok',$pdo->query('PRAGMA integrity_check')->fetchColumn());
    }
}
