<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use src\Core\DatabaseInitializer;
use Tests\Support\DatabaseTestCase;

final class ContratoAditivoMigrationTest extends DatabaseTestCase
{
    private function versionSeventeen(): PDO
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $schema=preg_replace('/^    aditivo INTEGER NOT NULL DEFAULT 0 CHECK \(aditivo IN \(0, 1\)\),\R/m','',(string)file_get_contents(ROOT_PATH.'/database/schema.sql'));
        $migration=new \ReflectionMethod(DatabaseInitializer::class,'runMigration');
        for ($version=1;$version<=17;$version++) { $migration->invoke(null,$pdo,$version,$schema); }
        return $pdo;
    }

    public function testVersionEighteenPreservesEveryHistoricalValueAndDefaultsOldNotesToCommon(): void
    {
        $pdo=$this->versionSeventeen();
        $actor=\test_insert_user($pdo,['nome'=>'Administrador da migração','email'=>'migration@example.test','senha'=>'unused','tipo'=>'administrador']);
        $pdo->exec("INSERT INTO pedidos(id,titulo,valor_total,valor_centavos,qtd_paginas) VALUES(7,'Pedido histórico',100.01,NULL,2);
            INSERT INTO pedido_paginas(id,id_pedido,numero_pagina,valor_pagina,valor_centavos,observacao,data_faturamento,excluido_em,excluido_por)
                VALUES(40,7,1,120.5,NULL,'Nota faturada antiga','2026-09-01',NULL,NULL),(41,7,2,20.25,2025,'Nota excluída',NULL,'2026-09-05',".$actor.");
            INSERT INTO pedido_produtos(id,id_pedido,numero_pagina,nome_produto,marca,unidade,quantidade,valor_unitario,valor_total)
                VALUES(51,7,1,'Produto legado','Marca antiga','kg',2.5,3.33,8.325);
            UPDATE sqlite_sequence SET seq=90 WHERE name='pedido_paginas';");
        $pdo->prepare("INSERT INTO estoque_movimentos(produto_id,tipo,quantidade,motivo,usuario_id,criado_em,idempotencia) VALUES(51,'abertura',2,'Contagem antiga',?,'2026-09-01',?)")->execute([$actor,str_repeat('d',32)]);
        $pdo->prepare("INSERT INTO modulo5_operacoes(idempotencia,tipo,recurso_id,usuario_id,criado_em) VALUES(?,'abertura_estoque',51,?,'2026-09-01')")->execute([str_repeat('e',32),$actor]);
        $pdo->exec("INSERT INTO modulo5_valores_legados VALUES('pedido_produtos',51,'valor_total','8.325')");
        $before=[];
        foreach (['pedidos','pedido_paginas','pedido_produtos','estoque_movimentos','modulo5_operacoes','modulo5_valores_legados'] as $table) {
            $before[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
        }
        DatabaseInitializer::initialize($pdo,true);
        DatabaseInitializer::initialize($pdo,true);
        foreach ($before as $table=>$rows) {
            $after=$pdo->query('SELECT * FROM '.$table)->fetchAll();
            if ($table==='pedido_paginas') {
                foreach ($after as &$row) { $this->assertSame(0,(int)$row['aditivo']); unset($row['aditivo']); }
                unset($row);
            }
            $this->assertSame($rows,$after,$table.' foi alterada na migração.');
        }
        $this->assertSame(18,(int)$pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=18')->fetchColumn());
        $this->assertSame(90,(int)$pdo->query("SELECT seq FROM sqlite_sequence WHERE name='pedido_paginas'")->fetchColumn());
        $this->assertSame('ok',$pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([],$pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->expectException(\PDOException::class);
        $pdo->exec('UPDATE pedido_paginas SET aditivo=2 WHERE id=40');
    }

    public function testVersionRecordingFailureRollsBackColumnAndLeavesVersionSeventeen(): void
    {
        $pdo=$this->versionSeventeen();
        $before=$pdo->query('PRAGMA table_info(pedido_paginas)')->fetchAll();
        $pdo->exec("CREATE TRIGGER fail_additive_migration BEFORE INSERT ON schema_migrations WHEN NEW.version=18 BEGIN SELECT RAISE(ABORT,'forced18'); END");
        try {
            (new \ReflectionMethod(DatabaseInitializer::class,'runMigration'))->invoke(null,$pdo,18,file_get_contents(ROOT_PATH.'/database/schema.sql'));
            $this->fail('Migração parcial confirmada.');
        } catch (\PDOException) {
            $this->assertSame($before,$pdo->query('PRAGMA table_info(pedido_paginas)')->fetchAll());
            $this->assertSame(17,(int)$pdo->query('PRAGMA user_version')->fetchColumn());
            $this->assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=18')->fetchColumn());
            $this->assertSame(1,(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn());
        }
    }
}
