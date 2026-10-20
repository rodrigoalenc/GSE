<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use Tests\Support\DatabaseTestCase;

final class ContratoAditivoTest extends DatabaseTestCase
{
    /** @return array{nome:string,marca:string,unidade:string,quantidade:string,preco:string} */
    private function product(string $price): array
    {
        return ['nome'=>'Produto de teste','marca'=>'','unidade'=>'un','quantidade'=>'1','preco'=>$price];
    }

    private function revision(Contrato $model,int $id): int
    {
        return (int)$model->find($id)['revisao'];
    }

    private function add(Contrato $model,int $id,int $sheet,string $price,int $actor): int
    {
        return $model->addItem($id,$sheet,$this->product($price),$this->revision($model,$id),$actor);
    }

    public function testAditivosCompartilhamVinteECincoPorCentoSemLiberarExcessoNasNotasComuns(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Pedido com aditivos','100,00','',$actor);
        $normal=(int)$model->sheets($id)[0]['id'];
        $this->add($model,$id,$normal,'100,00',$actor);
        $first=$model->addSheet($id,'Primeiro aditivo',$this->revision($model,$id),$actor,null,null,true);
        $second=$model->addSheet($id,'Segundo aditivo',$this->revision($model,$id),$actor,null,null,true);
        $item=$this->add($model,$id,$first,'15,00',$actor);
        $this->add($model,$id,$second,'10,00',$actor);
        $summary=$model->allocationSummary($id);
        $this->assertSame(10000,$summary['original_limit']);
        $this->assertSame(2500,$summary['additive_limit']);
        $this->assertSame(10000,$summary['original_allocated']);
        $this->assertSame(2500,$summary['additive_allocated']);
        $this->assertSame(12500,$summary['total_allocated']);
        $this->assertSame(12500,$summary['final_limit']);
        $this->assertSame(0,$summary['remaining']);
        $this->assertFalse($summary['legacy']);
        foreach ([$normal,$first,$second] as $sheet) {
            try { $this->add($model,$id,$sheet,'0,01',$actor); $this->fail('Limite excedido.'); }
            catch (DomainException) { $this->assertSame($summary,$model->allocationSummary($id)); }
        }
        try { $model->updateItem($id,$item,$this->product('15,01'),1,$actor); $this->fail('Edição excedeu os aditivos.'); }
        catch (DomainException) { $this->assertSame(1500,(int)$model->items($id)[1]['total_centavos']); }
        try { $model->update($id,'Pedido reduzido','99,99','',$this->revision($model,$id),$actor); $this->fail('Teto reduzido abaixo dos valores distribuídos.'); }
        catch (DomainException) { $this->assertSame(10000,(int)$model->find($id)['valor_centavos']); }
    }

    public function testTetoDosAditivosArredondaParaBaixoEmCentavosEIndependeDoSaldoComum(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Limite em centavos','0,07','',$actor);
        $summary=$model->allocationSummary($id);
        $this->assertFalse($summary['has_additive']);
        $this->assertSame(7,$summary['final_limit']);
        $sheet=$model->addSheet($id,'',$this->revision($model,$id),$actor,null,null,true);
        $this->add($model,$id,$sheet,'0,01',$actor);
        $this->assertSame(1,$model->allocationSummary($id)['additive_limit']);
        try { $this->add($model,$id,$sheet,'0,01',$actor); $this->fail('Arredondamento liberou um centavo indevido.'); }
        catch (DomainException) { $this->assertSame(7,$model->allocationSummary($id)['remaining']); }
        try { $model->update($id,'Redução do teto','0,03','',$this->revision($model,$id),$actor); $this->fail('Aditivo ficou acima de 25%.'); }
        catch (DomainException) { $this->assertSame(7,(int)$model->find($id)['valor_centavos']); }
    }

    public function testDuplicacaoMantemClassificacaoECobraLimiteAgregadoComRollbackIntegral(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Duplicação de notas','100,00','',$actor);
        $normal=(int)$model->sheets($id)[0]['id'];
        $this->add($model,$id,$normal,'10,00',$actor);
        $copiedNormal=$model->addSheet($id,'',$this->revision($model,$id),$actor,$normal,str_repeat('a',32),true);
        $this->assertSame(0,(int)$this->pdo->query('SELECT aditivo FROM pedido_paginas WHERE id='.$copiedNormal)->fetchColumn());
        $additive=$model->addSheet($id,'Aditivo',$this->revision($model,$id),$actor,null,null,true);
        $this->add($model,$id,$additive,'10,00',$actor);
        $copiedAdditive=$model->addSheet($id,'',$this->revision($model,$id),$actor,$additive,str_repeat('b',32));
        $this->assertSame(1,(int)$this->pdo->query('SELECT aditivo FROM pedido_paginas WHERE id='.$copiedAdditive)->fetchColumn());
        $before=$model->allocationSummary($id); $revision=$this->revision($model,$id);
        try { $model->addSheet($id,'',$revision,$actor,$additive,str_repeat('c',32)); $this->fail('Cópia excedeu o limite.'); }
        catch (DomainException) {
            $this->assertCount(4,$model->sheets($id));
            $this->assertSame($before,$model->allocationSummary($id));
            $this->assertSame($revision,$this->revision($model,$id));
            $this->assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM modulo5_operacoes WHERE idempotencia='".str_repeat('c',32)."'")->fetchColumn());
        }
        $model->delete($id,$copiedAdditive,null,$this->revision($model,$id),$actor);
        $this->assertSame(1000,$model->allocationSummary($id)['additive_allocated']);
        $model->addSheet($id,'',$this->revision($model,$id),$actor,$additive,str_repeat('c',32));
        $this->assertSame(2000,$model->allocationSummary($id)['additive_allocated']);
    }

    public function testConciliacaoAplicaLimitesSemAlterarOriginaisNemDadosExcluidos(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Conciliação controlada','100,00','',$actor);
        $sheet=$model->addSheet($id,'',$this->revision($model,$id),$actor,null,null,true);
        $item=$this->add($model,$id,$sheet,'10,00',$actor);
        $this->pdo->prepare('UPDATE pedido_produtos SET total_centavos=NULL,preco_centavos=NULL,quantidade_contratada=NULL WHERE id=?')->execute([$item]);
        $before=$model->items($id)[0];
        $this->assertTrue($model->allocationSummary($id)['legacy']);
        try { $model->reconcileLegacyItem($id,$item,1,'25,01',1,$actor); $this->fail('Conciliação excedeu 25%.'); }
        catch (DomainException) { $this->assertSame($before,$model->items($id)[0]); }
        $model->reconcileLegacyItem($id,$item,1,'25,00',1,$actor);
        $this->assertSame(10.0,(float)$model->items($id)[0]['valor_total']);
        $this->pdo->prepare('UPDATE pedidos SET valor_centavos=NULL WHERE id=?')->execute([$id]);
        try { $model->reconcileLegacyContract($id,'99,99',$this->revision($model,$id),$actor); $this->fail('Conciliação reduziu o teto dos aditivos.'); }
        catch (DomainException) { $this->assertNull($model->find($id)['valor_centavos']); }
        $model->reconcileLegacyContract($id,'100,00',$this->revision($model,$id),$actor);
        $model->delete($id,$sheet,null,$this->revision($model,$id),$actor);
        $this->assertFalse($model->allocationSummary($id)['has_additive']);
        $this->assertSame(10000,$model->allocationSummary($id)['final_limit']);
        try { $model->updateItem($id,$item,$this->product('1,00'),2,$actor); $this->fail('Item excluído foi editado.'); }
        catch (DomainException) { $this->assertNotNull($model->items($id)[0]['excluido_em']); }
    }

    public function testCadastroCompletoNaoUsaAditivoImplicitoParaUltrapassarTeto(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        try {
            $model->createDetailed('Cadastro com excesso','100,00','',[
                ['observacao'=>'Comum','produtos'=>[$this->product('100,00')]],
                ['observacao'=>'Tentativa de aditivo','aditivo'=>true,'produtos'=>[$this->product('25,00')]],
            ],$actor);
            $this->fail('Cadastro inicial excedeu o teto original.');
        } catch (DomainException) { $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()); }
    }

    public function testFalhaDaAuditoriaReverteCriacaoDoAditivo(): void
    {
        $actor=$this->insertUsuario(); $model=new Contrato();
        $id=$model->create('Auditoria dos aditivos','100,00','',$actor);
        $this->pdo->exec("CREATE TRIGGER fail_additive_audit BEFORE INSERT ON security_audit WHEN NEW.action='contrato.additive_sheet_created' BEGIN SELECT RAISE(ABORT,'falha auditada'); END");
        try { $model->addSheet($id,'',1,$actor,null,null,true); $this->fail('Aditivo sem auditoria.'); }
        catch (\PDOException) {
            $this->assertCount(1,$model->sheets($id));
            $this->assertSame(1,$this->revision($model,$id));
            $this->assertFalse($model->allocationSummary($id)['has_additive']);
        }
    }
}
