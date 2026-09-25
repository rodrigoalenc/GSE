<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use RelatorioAlunos;
use Tests\Support\DatabaseTestCase;

final class ModuloCincoTest extends DatabaseTestCase
{
    public function testContratoFolhaEstoqueHistoricoEExclusaoLogica(): void
    {
        $actor=$this->insertUsuario();
        $this->pdo->exec("INSERT INTO lista_fornecedores(nome,ativo) VALUES('Fornecedor Teste',1)");
        $supplier=(int)$this->pdo->lastInsertId();
        $model=new Contrato();
        $id=$model->create('Contrato teste','100,00',(string)$supplier,$actor);
        $this->assertSame(10000,(int)$model->find($id)['valor_centavos']);
        $sheet=$model->sheets($id)[0];
        $item=$model->addItem($id,(int)$sheet['id'],['nome'=>'Papel','marca'=>'','unidade'=>'resma','quantidade'=>'2','preco'=>'10,50'],1,$actor);
        $this->assertSame(2100,(int)$model->sheets($id)[0]['valor_centavos']);
        $this->assertSame(1,$model->stockList('Papel')['total']);
        $this->assertSame(0,(int)$model->items($id)[0]['saldo']);
        $model->configureStock($id,$item,1,10,1,$actor,2,str_repeat('a',32));
        $this->assertSame(2,(int)$model->items($id)[0]['saldo']);
        $model->move($id,$item,'saida',1,'Distribuição',str_repeat('b',32),$actor,2);
        $this->assertSame(1,(int)$model->items($id)[0]['saldo']);
        $this->assertCount(2,$model->movements($item));
        try { $model->move($id,$item,'saida',1,'Distribuição',str_repeat('b',32),$actor,2); $this->fail('Reenvio duplicado aceito.'); }
        catch (DomainException) { $this->assertSame(1,(int)$model->items($id)[0]['saldo']); }
        try { $model->move($id,$item,'saida',2,'Excesso',str_repeat('c',32),$actor,3); $this->fail('Saída excessiva aceita.'); }
        catch (DomainException $e) { $this->assertStringContainsString('Saldo',$e->getMessage()); }
        try { $model->delete($id,null,$item,2,$actor); $this->fail('Exclusão com saldo aceita.'); }
        catch (DomainException $e) { $this->assertStringContainsString('saldo',$e->getMessage()); }
        try { $model->delete($id,(int)$sheet['id'],$item,2,$actor); $this->fail('IDs misturados aceitos.'); }
        catch (DomainException) { $this->assertSame(1,(int)$model->items($id)[0]['saldo']); }
        $model->move($id,$item,'saida',1,'Destinação final',str_repeat('d',32),$actor,3);
        $model->delete($id,null,$item,2,$actor);
        $this->assertNotNull($model->items($id)[0]['excluido_em']);
        $this->assertCount(3,$model->movements($item));
    }

    public function testDvaCorrenteApareceUmaVezEExportaTodosOsFiltrados(): void
    {
        $class=$this->insertTurma('Turma Relatório');
        $student=$this->insertAlunoComDva('Aluno Teste','2025-01-01',$class);
        $this->pdo->prepare('UPDATE dvas SET ativo=0 WHERE id_aluno=?')->execute([$student]);
        $this->pdo->prepare('INSERT INTO dvas(id_aluno,data_vencimento,ativo) VALUES(?,?,1)')->execute([$student,'2028-01-01']);
        $model=new RelatorioAlunos();
        $result=$model->query(['turma'=>(string)$class,'dva'=>'vigente','ativo'=>'1'],1,true);
        $this->assertSame(1,$result['total']);
        $this->assertCount(1,$result['items']);
        $this->assertSame('2028-01-01',$result['items'][0]['data_vencimento']);
        $this->assertSame("'=SOMA(1)",RelatorioAlunos::csvCell(" \t=SOMA(1)"));
        $this->assertSame("'=1+1",RelatorioAlunos::csvCell("\u{200B}=1+1"));
        $this->pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,ativo) VALUES(?,?,?,1)')->execute(['Aluno Legado','aluno legado','2011-01-01']);
        $legacy=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO dvas(id_aluno,data_vencimento,ativo) VALUES(?,?,1)')->execute([$legacy,'data-inválida']);
        $missing=$model->query(['turma'=>'','dva'=>'sem_dva','ativo'=>'1'],1,true);
        $this->assertSame(1,$missing['total']);
        $this->assertNull($missing['items'][0]['data_vencimento']);
    }

    public function testEdicaoPreservaFilhosEDuplicacaoNaoDuplicaEstoque(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Material de aula','100,00','',$actor);
        $sheet=(int)$model->sheets($id)[0]['id'];
        $item=$model->addItem($id,$sheet,['nome'=>'Caderno','marca'=>'','unidade'=>'un','quantidade'=>'2','preco'=>'10,00'],1,$actor);
        $model->configureStock($id,$item,1,10,1,$actor,2,str_repeat('1',32));
        $model->update($id,'Material revisado','120,00','',2,$actor);
        $this->assertSame($sheet,(int)$model->sheets($id)[0]['id']);
        $this->assertSame($item,(int)$model->items($id)[0]['id']);
        $this->assertSame(2,(int)$model->items($id)[0]['saldo']);
        try { $model->update($id,'Rascunho antigo','120,00','',2,$actor); $this->fail('Revisão antiga aceita.'); }
        catch (DomainException) { $this->assertSame('Material revisado',$model->find($id)['titulo']); }
        $model->addSheet($id,'',3,$actor,$sheet,str_repeat('a',32));
        $items=$model->items($id);
        $this->assertCount(2,$items);
        $this->assertSame(0,(int)$items[1]['saldo']);
        $this->assertSame(0,(int)$items[1]['estoque_inicializado']);
        $this->assertNull($model->sheets($id)[1]['data_faturamento']);
        try { $model->addSheet($id,'',4,$actor,$sheet,str_repeat('a',32)); $this->fail('Duplicação repetida aceita.'); }
        catch (\PDOException) { $this->assertCount(2,$model->sheets($id)); }
    }

    public function testEstornoEAlertasRespeitamLimites(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Estoque teste','50,00','',$actor);
        $sheet=(int)$model->sheets($id)[0]['id'];
        $item=$model->addItem($id,$sheet,['nome'=>'Caneta','marca'=>'','unidade'=>'un','quantidade'=>'5','preco'=>'2,00'],1,$actor);
        $model->configureStock($id,$item,2,3,1,$actor,2,str_repeat('2',32));
        $model->move($id,$item,'saida',1,'Uso em aula',str_repeat('3',32),$actor,2);
        $movement=(int)$model->movements($item)[0]['id'];
        $model->move($id,$item,'estorno',1,'Correção autorizada',str_repeat('4',32),$actor,3,$movement);
        $this->assertSame(2,(int)$model->items($id)[0]['saldo']);
        try { $model->move($id,$item,'estorno',1,'Repetido',str_repeat('5',32),$actor,4,$movement); $this->fail('Estorno repetido aceito.'); }
        catch (\PDOException) { $this->assertSame(2,(int)$model->items($id)[0]['saldo']); }
        try { $model->move($id,$item,'entrada',2,'Acima do máximo',str_repeat('6',32),$actor,4); $this->fail('Máximo excedido.'); }
        catch (DomainException) { $this->assertSame(2,(int)$model->items($id)[0]['saldo']); }
    }

    public function testFalhaDeAuditoriaReverteContrato(): void
    {
        $actor=$this->insertUsuario();
        $this->pdo->exec('DROP TABLE security_audit');
        try { (new Contrato())->create('Sem auditoria','10,00','',$actor); $this->fail('Contrato salvo sem auditoria.'); }
        catch (\PDOException) { $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()); }
    }

    public function testConciliacaoLegadaPreservaValoresOriginaisSemCriarSaldo(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Legado','100,00','',$actor);
        $sheet=(int)$model->sheets($id)[0]['id'];
        $item=$model->addItem($id,$sheet,['nome'=>'Produto antigo','marca'=>'','unidade'=>'un','quantidade'=>'2','preco'=>'10,00'],1,$actor);
        $this->pdo->prepare('UPDATE pedidos SET valor_centavos=NULL WHERE id=?')->execute([$id]);
        $this->pdo->prepare('UPDATE pedido_paginas SET valor_centavos=NULL WHERE id=?')->execute([$sheet]);
        $this->pdo->prepare('UPDATE pedido_produtos SET total_centavos=NULL,preco_centavos=NULL,quantidade_contratada=NULL WHERE id=?')->execute([$item]);
        $this->pdo->prepare("INSERT INTO modulo5_valores_legados(tabela,registro_id,campo,valor_original) VALUES('pedido_produtos',?,'valor_total','20.0')")->execute([$item]);
        $model->reconcileLegacyItem($id,$item,2,'10,00',1,$actor);
        $this->assertSame('20.0',$this->pdo->query('SELECT valor_original FROM modulo5_valores_legados')->fetchColumn());
        $this->assertSame(20.0,(float)$this->pdo->query('SELECT valor_total FROM pedido_produtos')->fetchColumn());
        $this->assertSame(0,(int)$model->items($id)[0]['saldo']);
        $model->reconcileLegacyContract($id,'100,00',3,$actor);
        $this->assertSame(10000,(int)$model->find($id)['valor_centavos']);
    }

    public function testDuasSaidasEmProcessosSeparadosConfirmamApenasUma(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Concorrência','10,00','',$actor);
        $sheet=(int)$model->sheets($id)[0]['id'];
        $item=$model->addItem($id,$sheet,['nome'=>'Único item','marca'=>'','unidade'=>'un','quantidade'=>'1','preco'=>'1,00'],1,$actor);
        $model->configureStock($id,$item,0,1,1,$actor,1,str_repeat('7',32));
        $barrier=sys_get_temp_dir().'/gse-stock-barrier-'.bin2hex(random_bytes(8));
        $processes=[];
        try {
            foreach ([str_repeat('8',32),str_repeat('9',32)] as $key) {
                $process=proc_open([PHP_BINARY,ROOT_PATH.'/tests/fixtures/stock-race.php',$barrier,(string)$id,(string)$item,(string)$actor,$key],
                    [1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,array_merge(getenv(),['DB_PATH'=>(string)$_ENV['DB_PATH'],'APP_ENV'=>'testing']));
                $this->assertIsResource($process);
                $processes[]=[$process,$pipes];
            }
            file_put_contents($barrier,'go');
            $results=[];
            foreach ($processes as [$process,$pipes]) {
                $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $results[]=[proc_close($process),$out,$err];
            }
            $statuses=array_column($results,1); sort($statuses);
            $this->assertSame(['NO','OK'],$statuses);
            $this->assertSame(0,(int)$model->items($id)[0]['saldo']);
        } finally { if (is_file($barrier)) { unlink($barrier); } }
    }
}
