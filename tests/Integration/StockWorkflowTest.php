<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use Tests\Support\DatabaseTestCase;

final class StockWorkflowTest extends DatabaseTestCase
{
    public function testInitialQuantityDoesNotRequireLimitsAndConsumptionCannotOverdraw(): void
    {
        [$model,$actor,$contract,$sheet,$first]=$this->fixture();
        $model->initializeStock($contract,$first,20,1,$actor,$this->key('initial'));
        $item=$model->items($contract)[0];
        $this->assertNull($item['estoque_minimo']);
        $this->assertNull($item['estoque_maximo']);
        $this->assertSame(20,(int)$item['saldo']);
        $model->move($contract,$first,'saida',6,'Consumo registrado',$this->key('consume'),$actor,2);
        $this->assertSame(14,(int)$model->items($contract)[0]['saldo']);
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->move($contract,$first,'saida',15,'Consumo registrado',$this->key('excess'),$actor,3));
        $this->rejected(fn()=>$model->initializeStock($contract,$first,20,3,$actor,$this->key('again')));
        $this->assertSame($before,$this->snapshot());
        $this->assertCount(2,$model->movements($first));
    }

    public function testInitialQuantityPreservesSeparatelyConfiguredAlertsAndZeroIsConfirmedOnce(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $model->configureStock($contract,$first,5,30,1,$actor);
        $model->initializeStock($contract,$first,20,2,$actor,$this->key('initial'));
        $item=$model->items($contract)[0];
        $this->assertSame(5,(int)$item['estoque_minimo']);
        $this->assertSame(30,(int)$item['estoque_maximo']);
        $model->initializeStock($contract,$second,0,1,$actor,$this->key('zero'));
        $this->assertSame(0,(int)$model->items($contract)[1]['abertura_sem_comprovacao']);
        $this->assertCount(0,$model->movements($second));
        $initialHistory=$model->stockHistory($second);
        $this->assertCount(1,$initialHistory);
        $this->assertNull($initialHistory[0]['id']);
        $this->assertSame(0,(int)$initialHistory[0]['quantidade']);
        $this->assertSame($actor,(int)$initialHistory[0]['usuario_id']);
        $this->assertNotEmpty($initialHistory[0]['criado_em']);
        $this->assertSame(1,$model->stockList('CHAM')['total']);
        $model->move($contract,$second,'entrada',4,'Recebimento registrado',$this->key('receive'),$actor,2);
        $this->assertSame(4,(int)$model->items($contract)[1]['saldo']);
        $this->assertCount(2,$model->stockHistory($second));
        $this->assertSame('entrada',$model->stockHistory($second)[0]['tipo']);
    }

    public function testOptionalMaximumCanBeRemovedAndMinimumCanExistIndependently(): void
    {
        [$model,$actor,$contract,$sheet,$first]=$this->fixture();
        $model->configureStock($contract,$first,0,20,1,$actor,20,$this->key('initial'));
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->configureStock($contract,$first,null,19,2,$actor));
        $this->assertSame($before,$this->snapshot());
        $model->configureStock($contract,$first,25,null,2,$actor);
        $model->move($contract,$first,'entrada',30,'Recebimento registrado',$this->key('receive'),$actor,3);
        $item=$model->items($contract)[0];
        $this->assertSame(50,(int)$item['saldo']);
        $this->assertSame(25,(int)$item['estoque_minimo']);
        $this->assertNull($item['estoque_maximo']);
        $model->configureStock($contract,$first,null,null,4,$actor);
        $this->assertNull($model->items($contract)[0]['estoque_minimo']);
    }

    public function testOptionalLimitsStillRejectInvalidNumbersAndInconsistentBounds(): void
    {
        [$model,$actor,$contract,$sheet,$first]=$this->fixture();
        $before=$this->snapshot();
        foreach ([[-1,null],[null,-1],[1000000001,null],[null,1000000001],[10,9]] as [$minimum,$maximum]) {
            $this->rejected(fn()=>$model->configureStock($contract,$first,$minimum,$maximum,1,$actor));
            $this->assertSame($before,$this->snapshot());
        }
        foreach ([-1,1000000001] as $quantity) {
            $this->rejected(fn()=>$model->initializeStock($contract,$first,$quantity,1,$actor,$this->key((string)$quantity)));
            $this->assertSame($before,$this->snapshot());
        }
    }

    public function testNoteReceiptAddsActualQuantitiesOnceAndKeepsZeroLinesUnchanged(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $model->initializeStock($contract,$first,20,1,$actor,$this->key('initial'));
        $lines=[['id'=>$first,'revision'=>2,'quantity'=>6],['id'=>$second,'revision'=>1,'quantity'=>0]];
        $model->receiveSheet($contract,$sheet,$lines,$this->key('receipt'),$actor);
        $after=$this->snapshot();
        $model->receiveSheet($contract,$sheet,$lines,$this->key('receipt'),$actor);
        $this->assertSame($after,$this->snapshot());
        $items=$model->items($contract);
        $this->assertSame(26,(int)$items[0]['saldo']);
        $this->assertSame(0,(int)$items[1]['saldo']);
        $this->assertSame(0,(int)$items[1]['estoque_inicializado']);
        $this->assertSame(1,(int)$items[1]['revisao']);
        $this->assertSame('entrada',$model->movements($first)[0]['tipo']);
        $this->assertSame($actor,(int)$model->movements($first)[0]['usuario_id']);
        $this->assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='contrato.sheet_received'")->fetchColumn());
        $lines[0]['quantity']=7;
        $this->rejected(fn()=>$model->receiveSheet($contract,$sheet,$lines,$this->key('receipt'),$actor));
        $this->assertSame($after,$this->snapshot());
    }

    public function testNoConfiguredMaximumStillHonorsTheSystemQuantityLimit(): void
    {
        [$model,$actor,$contract,$sheet,$first]=$this->fixture();
        $model->initializeStock($contract,$first,1000000000,1,$actor,$this->key('initial'));
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->move($contract,$first,'entrada',1,'Recebimento registrado',$this->key('move'),$actor,2));
        $this->rejected(fn()=>$model->receiveSheet($contract,$sheet,[['id'=>$first,'revision'=>2,'quantity'=>1]],$this->key('receipt'),$actor));
        $this->assertSame($before,$this->snapshot());
    }

    public function testReceiptOfSeveralProductsRollsBackEntirelyWhenOneExceedsItsMaximum(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $model->initializeStock($contract,$first,0,1,$actor,$this->key('first'));
        $model->configureStock($contract,$second,null,5,1,$actor,4,$this->key('second'));
        $before=$this->snapshot();
        $lines=[['id'=>$first,'revision'=>2,'quantity'=>6],['id'=>$second,'revision'=>2,'quantity'=>2]];
        $this->rejected(fn()=>$model->receiveSheet($contract,$sheet,$lines,$this->key('receipt'),$actor));
        $this->assertSame($before,$this->snapshot());
        $lines[1]['quantity']=1;
        $model->receiveSheet($contract,$sheet,$lines,$this->key('receipt'),$actor);
        $this->assertSame([6,5],array_map(static fn(array $item): int=>(int)$item['saldo'],$model->items($contract)));
    }

    public function testReceiptRejectsPendingCountsStaleLinesAndProductsFromAnotherNote(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $model->initializeStock($contract,$first,0,1,$actor,$this->key('first'));
        $otherSheet=$model->addSheet($contract,'Outra nota',(int)$model->find($contract)['revisao'],$actor);
        $before=$this->snapshot();
        foreach ([
            [$sheet,[['id'=>$second,'revision'=>1,'quantity'=>1]]],
            [$sheet,[['id'=>$first,'revision'=>1,'quantity'=>1]]],
            [$sheet,[['id'=>$first,'revision'=>2,'quantity'=>1],['id'=>$second,'revision'=>2,'quantity'=>0]]],
            [$otherSheet,[['id'=>$first,'revision'=>2,'quantity'=>1]]],
            [$sheet,[['id'=>$first,'revision'=>2,'quantity'=>1],['id'=>$first,'revision'=>2,'quantity'=>2]]],
            [$sheet,[['id'=>$first,'revision'=>2,'quantity'=>0]]],
        ] as [$note,$lines]) {
            $this->rejected(fn()=>$model->receiveSheet($contract,$note,$lines,$this->key('receipt'),$actor));
            $this->assertSame($before,$this->snapshot());
        }
    }

    public function testNoteReceiptAndInitialQuantityRollBackWhenAuditFails(): void
    {
        [$model,$actor,$contract,$sheet,$first]=$this->fixture();
        $before=$this->snapshot();
        $this->rejectAudit('contrato.stock_opened');
        $this->auditRejected(fn()=>$model->initializeStock($contract,$first,20,1,$actor,$this->key('initial')));
        $this->assertSame($before,$this->snapshot());
        $this->pdo->exec('DROP TRIGGER reject_stock_audit');
        $model->initializeStock($contract,$first,20,1,$actor,$this->key('initial'));
        $before=$this->snapshot();
        $this->rejectAudit('contrato.sheet_received');
        $this->auditRejected(fn()=>$model->receiveSheet($contract,$sheet,[['id'=>$first,'revision'=>2,'quantity'=>6]],$this->key('receipt'),$actor));
        $this->assertSame($before,$this->snapshot());
    }

    public function testLegacyOpeningAndUnprovedOldOpeningKeepAdministrativeProtections(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $this->pdo->prepare("INSERT INTO modulo5_valores_legados(tabela,registro_id,campo,valor_original) VALUES('pedido_produtos',?,'quantidade','2')")->execute([$first]);
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->initializeStock($contract,$first,20,1,$actor,$this->key('initial')));
        $this->assertSame($before,$this->snapshot());
        $model->initializeStock($contract,$first,20,1,$actor,$this->key('initial'),true);
        $this->pdo->prepare('UPDATE pedido_produtos SET estoque_inicializado=1 WHERE id=?')->execute([$second]);
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->receiveSheet($contract,$sheet,[['id'=>$second,'revision'=>1,'quantity'=>1]],$this->key('receipt'),$actor));
        $this->assertSame($before,$this->snapshot());
        $model->recoverOldOpening($contract,$second,3,'UN',null,null,1,$actor,'Contagem física e documento conferidos',$this->key('recovery'),true);
        $this->assertNull($model->items($contract)[1]['estoque_maximo']);
        $this->assertSame(3,(int)$model->items($contract)[1]['saldo']);
        $model->receiveSheet($contract,$sheet,[['id'=>$second,'revision'=>2,'quantity'=>1]],$this->key('receipt'),$actor);
        $this->assertSame(4,(int)$model->items($contract)[1]['saldo']);
    }

    public function testInactiveNotesCannotChangeStock(): void
    {
        [$model,$actor,$contract,$sheet,$first,$second]=$this->fixture();
        $model->initializeStock($contract,$first,0,1,$actor,$this->key('initial'));
        $this->pdo->prepare('UPDATE pedido_paginas SET excluido_em=? WHERE id=?')->execute(['2026-01-01 00:00:00',$sheet]);
        $this->assertSame(0,$model->stockList()['total']);
        $before=$this->snapshot();
        $this->rejected(fn()=>$model->initializeStock($contract,$second,1,1,$actor,$this->key('second')));
        $this->rejected(fn()=>$model->configureStock($contract,$first,1,null,2,$actor));
        $this->rejected(fn()=>$model->move($contract,$first,'entrada',1,'Recebimento registrado',$this->key('move'),$actor,2));
        $this->rejected(fn()=>$model->receiveSheet($contract,$sheet,[['id'=>$first,'revision'=>2,'quantity'=>1]],$this->key('receipt'),$actor));
        $this->assertSame($before,$this->snapshot());
    }

    /** @return array{Contrato,int,int,int,int,int} */
    private function fixture(): array
    {
        $actor=$this->insertUsuario();
        $model=new Contrato();
        $contract=$model->createDetailed('Alimentação escolar','100,00','',[
            ['observacao'=>'Nota inicial','produtos'=>[
                ['nome'=>'Açúcar cristalizado','marca'=>'CHAM','unidade'=>'UN','quantidade'=>'10','preco'=>'1,00'],
                ['nome'=>'Arroz','marca'=>'Escola','unidade'=>'UN','quantidade'=>'5','preco'=>'1,00'],
            ]],
        ],$actor);
        $items=$model->items($contract);
        return [$model,$actor,$contract,(int)$model->sheets($contract)[0]['id'],(int)$items[0]['id'],(int)$items[1]['id']];
    }

    private function key(string $label): string { return md5($label); }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Operação inválida foi aceita.'); }
        catch (DomainException) { $this->addToAssertionCount(1); }
    }

    private function auditRejected(callable $operation): void
    {
        try { $operation(); $this->fail('Operação sem auditoria foi aceita.'); }
        catch (\PDOException) { $this->addToAssertionCount(1); }
    }

    private function rejectAudit(string $action): void
    {
        $this->pdo->exec('CREATE TRIGGER reject_stock_audit BEFORE INSERT ON security_audit WHEN NEW.action='
            .$this->pdo->quote($action)." BEGIN SELECT RAISE(ABORT, 'forced_stock_audit_failure'); END");
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $state=[];
        foreach (['pedido_produtos','estoque_movimentos','modulo5_operacoes','request_receipts','security_audit'] as $table) {
            $state[$table]=$this->pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
        }
        return $state;
    }
}
