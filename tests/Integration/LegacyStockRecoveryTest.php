<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

final class LegacyStockRecoveryTest extends DatabaseTestCase
{
    public function testPositiveRecoveryRecordsCurrentCountAndCompleteAudit(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $before=gmdate('Y-m-d H:i:s');
        $reason='Inventário físico de hoje, documento de conferência 42, assinado pelo responsável.';
        $model->recoverOldOpening($contract,$item,7,'caixa',2,20,1,$actor,$reason,str_repeat('a',32),true);

        $stock=$model->items($contract)[0];
        $this->assertSame(7,(int)$stock['saldo']);
        $this->assertSame(1,(int)$stock['estoque_inicializado']);
        $this->assertSame(0,(int)$stock['abertura_sem_comprovacao']);
        $this->assertSame(2,(int)$stock['estoque_minimo']);
        $this->assertSame(20,(int)$stock['estoque_maximo']);
        $this->assertSame(2,(int)$stock['revisao']);
        $this->assertSame(1,(int)$stock['quantidade_contratada']);
        $operation=$this->pdo->query("SELECT * FROM modulo5_operacoes WHERE tipo='abertura_legada_conferida'")->fetch();
        $this->assertSame($actor,(int)$operation['usuario_id']);
        $this->assertSame($item,(int)$operation['recurso_id']);
        $this->assertGreaterThanOrEqual($before,$operation['criado_em']);
        $movement=$model->movements($item)[0];
        $this->assertSame('abertura',$movement['tipo']);
        $this->assertSame(7,(int)$movement['quantidade']);
        $this->assertSame($actor,(int)$movement['usuario_id']);
        $this->assertNull($movement['movimento_original_id']);
        $this->assertGreaterThanOrEqual($before,$movement['criado_em']);
        $this->assertSame('Conferência física atual; documento: '.$reason,$movement['motivo']);
        $audit=$this->pdo->query("SELECT * FROM security_audit WHERE action='contrato.old_opening_recovered'")->fetch();
        $this->assertSame($actor,(int)$audit['actor_user_id']);
        $this->assertSame($item,(int)$audit['resource_id']);
        $this->assertSame('success',$audit['result']);
        $this->assertStringContainsString('7 caixa; limites: 2 a 20',$audit['description']);
        $this->assertStringContainsString($reason,$audit['description']);
        $this->assertGreaterThanOrEqual($before,$audit['occurred_at']);
        $this->assertSame(0,(int)$model->stockList()['items'][0]['abertura_sem_comprovacao']);

        $model->move($contract,$item,'saida',7,'Destinação registrada após a conferência.',str_repeat('b',32),$actor,2);
        $this->assertSame(0,(int)$model->items($contract)[0]['saldo']);
        try {
            $model->updateItem($contract,$item,['nome'=>'Giz','marca'=>'','unidade'=>'kg','quantidade'=>'1','preco'=>'2,00'],3,$actor);
            $this->fail('Unidade histórica foi alterada.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Unidade não pode mudar',$exception->getMessage());
        }
        $this->assertSame('caixa',$model->items($contract)[0]['unidade']);
    }

    public function testZeroRecoveryRemainsExplicitAndDoesNotCreateAFictitiousMovement(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $model->recoverOldOpening(...$this->arguments($contract,$item,$actor,['physicalQuantity'=>0]));
        $this->assertSame(0,(int)$model->items($contract)[0]['saldo']);
        $this->assertSame(0,(int)$model->items($contract)[0]['abertura_sem_comprovacao']);
        $this->assertCount(0,$model->movements($item));
        $this->assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM modulo5_operacoes WHERE tipo='abertura_zero_conferida'")->fetchColumn());
        $this->reject($model,$this->arguments($contract,$item,$actor,['revision'=>2,'key'=>str_repeat('b',32)]),'já confirmada');
        $model->move($contract,$item,'entrada',1,'Novo recebimento',str_repeat('c',32),$actor,2);
        $this->assertSame(1,(int)$model->items($contract)[0]['saldo']);
    }

    public function testReplayAndStaleRevisionNeverChangeTheConfirmedCount(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $arguments=$this->arguments($contract,$item,$actor);
        $model->recoverOldOpening(...$arguments);
        $this->reject($model,$arguments,'Estoque alterado');
        $this->reject($model,array_replace($arguments,['revision'=>2]),'Estoque alterado');
        $this->assertSame(7,(int)$model->items($contract)[0]['saldo']);
        $this->assertCount(1,$model->movements($item));
        $this->assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM modulo5_operacoes WHERE tipo='abertura_legada_conferida'")->fetchColumn());
    }

    #[DataProvider('invalidCountData')]
    public function testInvalidOrUnconfirmedCountIsRejectedWithoutChanges(array $overrides,string $message): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $this->reject($model,$this->arguments($contract,$item,$actor,$overrides),$message);
    }

    public static function invalidCountData(): array
    {
        return [
            'sem confirmação'=>[['physicalCountConfirmed'=>false],'Confirme expressamente'],
            'saldo negativo'=>[['physicalQuantity'=>-1],'Contagem física fora'],
            'saldo acima do máximo'=>[['physicalQuantity'=>21],'Contagem física fora'],
            'mínimo negativo'=>[['minimum'=>-1],'Limites de estoque'],
            'máximo menor que mínimo'=>[['minimum'=>21],'Limites de estoque'],
            'máximo excessivo'=>[['maximum'=>1000000001],'Limites de estoque'],
            'unidade divergente'=>[['unit'=>'kg'],'unidade atual'],
            'unidade vazia'=>[['unit'=>''],'Unidade da contagem'],
            'unidade longa'=>[['unit'=>str_repeat('u',31)],'Unidade da contagem'],
            'unidade com controle'=>[['unit'=>"ca\x00ixa"],'Unidade da contagem'],
            'documento ausente'=>[['reason'=>'Curto'],'documento de apoio'],
            'documento excessivo'=>[['reason'=>str_repeat('r',181)],'documento de apoio'],
            'documento com controle'=>[['reason'=>"Conferência\x00documental completa"],'documento de apoio'],
            'documento UTF8 inválido'=>[['reason'=>"Conferência documental \xFF"],'documento de apoio'],
            'chave inválida'=>[['key'=>'autorizacao'],'Chave de confirmação'],
            'revisão antiga'=>[['revision'=>0],'Estoque alterado'],
        ];
    }

    #[DataProvider('unauthorizedActorData')]
    public function testOnlyAnActiveAdministratorCanRecoverStock(string $profile,bool $active): void
    {
        [$model,$contract,$item]=$this->legacyStock();
        $actor=$this->insertUsuario('Outro operador',$profile,$active);
        $this->reject($model,$this->arguments($contract,$item,$actor),'administrador ativo');
    }

    public static function unauthorizedActorData(): array
    {
        return [['funcionario',true],['administrador',false],['funcionario',false]];
    }

    #[DataProvider('unavailableLinkData')]
    public function testInactiveOrInvalidRelationsAreRejected(string $sql,string $message): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $this->pdo->exec($sql);
        $this->reject($model,$this->arguments($contract,$item,$actor),$message);
    }

    public static function unavailableLinkData(): array
    {
        return [
            ["UPDATE pedidos SET excluido_em='2026-01-01'",'Contrato excluído'],
            ["UPDATE pedido_produtos SET excluido_em='2026-01-01'",'Estoque alterado'],
            ["UPDATE pedido_paginas SET excluido_em='2026-01-01'",'nota ativa'],
            ['UPDATE pedido_produtos SET numero_pagina=42','nota ativa'],
            ['UPDATE pedido_produtos SET estoque_inicializado=0','Estoque alterado'],
        ];
    }

    public function testProductOfAnotherContractOrMissingResourceIsRejected(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $other=$model->create('Outro contrato','10,00','',$actor);
        $this->reject($model,$this->arguments($other,$item,$actor),'não pertence');
        $this->reject($model,$this->arguments($contract,999999,$actor),'não pertence');
        $this->reject($model,$this->arguments(999999,$item,$actor),'não pertence');
        $this->reject($model,$this->arguments($contract,$item,999999),'administrador ativo');
    }

    public function testRecordedZeroOpeningAndPriorMovementsAreNotRecoverable(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $this->pdo->prepare('UPDATE pedido_produtos SET estoque_inicializado=0 WHERE id=?')->execute([$item]);
        $model->configureStock($contract,$item,0,20,1,$actor,0,str_repeat('b',32),true);
        $this->reject($model,$this->arguments($contract,$item,$actor,['revision'=>2]),'já confirmada');
        $model->move($contract,$item,'entrada',1,'Recebimento após zero confirmado',str_repeat('c',32),$actor,2);
        $model->move($contract,$item,'saida',1,'Uso do recebimento',str_repeat('d',32),$actor,3);
        $this->reject($model,$this->arguments($contract,$item,$actor,['revision'=>4]),'Há movimentos');
        $this->assertSame(0,(int)$model->items($contract)[0]['saldo']);
        $this->assertCount(2,$model->movements($item));
    }

    public function testIdempotencyKeyCannotBeReusedForAnotherProductOrOperation(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $model->recoverOldOpening(...$this->arguments($contract,$item,$actor));
        $other=$model->create('Outra conferência','20,00','',$actor);
        $sheet=(int)$model->sheets($other)[0]['id'];
        $otherItem=$model->addItem($other,$sheet,['nome'=>'Papel','marca'=>'','unidade'=>'caixa','quantidade'=>'1','preco'=>'2,00'],1,$actor);
        $this->pdo->prepare('UPDATE pedido_produtos SET estoque_inicializado=1,estoque_minimo=0,estoque_maximo=10 WHERE id=?')->execute([$otherItem]);
        $this->reject($model,$this->arguments($other,$otherItem,$actor),'Operação já enviada');

        $model->move($contract,$item,'entrada',1,'Recebimento separado',str_repeat('e',32),$actor,2);
        $this->reject($model,$this->arguments($other,$otherItem,$actor,['key'=>str_repeat('e',32)]),'Operação já enviada');
        $this->assertSame(1,(int)$model->items($other)[0]['abertura_sem_comprovacao']);
    }

    public function testAuditFailureRollsBackMovementLimitsRevisionAndIdempotency(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $before=$this->snapshot();
        $this->pdo->exec("CREATE TRIGGER falha_conferencia BEFORE INSERT ON security_audit WHEN NEW.action='contrato.old_opening_recovered' BEGIN SELECT RAISE(ABORT,'auditoria indisponível'); END");
        try {
            $model->recoverOldOpening(...$this->arguments($contract,$item,$actor));
            $this->fail('Abertura confirmada sem auditoria.');
        } catch (PDOException) {
            $this->assertSame($before,$this->snapshot());
        }
        $this->assertSame(1,(int)$model->items($contract)[0]['abertura_sem_comprovacao']);
        $this->pdo->exec('DROP TRIGGER falha_conferencia');
        $model->recoverOldOpening(...$this->arguments($contract,$item,$actor));
        $this->assertSame(7,(int)$model->items($contract)[0]['saldo']);
    }

    public function testFullDocumentIsKeptAtTheAuditLengthBoundary(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $unit=str_repeat('u',30);
        $reason=str_repeat('d',180);
        $this->pdo->prepare('UPDATE pedido_produtos SET unidade=? WHERE id=?')->execute([$unit,$item]);
        $model->recoverOldOpening(...$this->arguments($contract,$item,$actor,[
            'physicalQuantity'=>1000000000,'unit'=>$unit,'minimum'=>1000000000,'maximum'=>1000000000,'reason'=>$reason,
        ]));
        $description=$this->pdo->query("SELECT description FROM security_audit WHERE action='contrato.old_opening_recovered'")->fetchColumn();
        $this->assertLessThanOrEqual(300,mb_strlen($description));
        $this->assertStringEndsWith($reason,$description);
    }

    public function testConcurrentPhysicalCountsConfirmOnlyOne(): void
    {
        [$model,$contract,$item,$actor]=$this->legacyStock();
        $barrier=sys_get_temp_dir().'/gse-legacy-count-'.bin2hex(random_bytes(8));
        $processes=[];
        try {
            foreach ([str_repeat('1',32),str_repeat('2',32)] as $key) {
                $process=proc_open([PHP_BINARY,ROOT_PATH.'/tests/fixtures/stock-recovery-race.php',$barrier,(string)$contract,(string)$item,(string)$actor,$key],
                    [1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,array_merge(getenv(),['DB_PATH'=>(string)$_ENV['DB_PATH'],'APP_ENV'=>'testing']));
                $this->assertIsResource($process);
                $processes[]=[$process,$pipes];
            }
            file_put_contents($barrier,'go');
            $statuses=[];
            foreach ($processes as [$process,$pipes]) {
                $statuses[]=stream_get_contents($pipes[1]);
                $errors=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0,proc_close($process),$errors);
            }
            sort($statuses);
            $this->assertSame(['NO','OK'],$statuses);
            $this->assertSame(7,(int)$model->items($contract)[0]['saldo']);
            $this->assertSame(2,(int)$model->items($contract)[0]['revisao']);
            $this->assertCount(1,$model->movements($item));
            $this->assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM modulo5_operacoes WHERE tipo='abertura_legada_conferida'")->fetchColumn());
            $this->assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='contrato.old_opening_recovered'")->fetchColumn());
        } finally {
            if (is_file($barrier)) { unlink($barrier); }
        }
    }

    private function legacyStock(): array
    {
        $actor=$this->insertUsuario();
        $model=new Contrato();
        $contract=$model->create('Conferência de estoque antigo','20,00','',$actor);
        $sheet=(int)$model->sheets($contract)[0]['id'];
        $item=$model->addItem($contract,$sheet,['nome'=>'Giz','marca'=>'','unidade'=>'caixa','quantidade'=>'1','preco'=>'2,00'],1,$actor);
        $this->pdo->prepare('UPDATE pedido_produtos SET estoque_inicializado=1,estoque_minimo=0,estoque_maximo=10 WHERE id=?')->execute([$item]);
        return [$model,$contract,$item,$actor];
    }

    private function arguments(int $contract,int $item,int $actor,array $overrides=[]): array
    {
        return array_replace([
            'contractId'=>$contract,'itemId'=>$item,'physicalQuantity'=>7,'unit'=>'caixa','minimum'=>2,'maximum'=>20,
            'revision'=>1,'actor'=>$actor,'reason'=>'Contagem física atual documentada no inventário 42.','key'=>str_repeat('a',32),'physicalCountConfirmed'=>true,
        ],$overrides);
    }

    private function reject(Contrato $model,array $arguments,string $message): void
    {
        $before=$this->snapshot();
        try {
            $model->recoverOldOpening(...$arguments);
            $this->fail('Conferência inválida foi aceita.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message,$exception->getMessage());
            $this->assertSame($before,$this->snapshot());
        }
    }

    private function snapshot(): array
    {
        $snapshot=[];
        foreach (['pedido_produtos','estoque_movimentos','modulo5_operacoes','security_audit'] as $table) {
            $snapshot[$table]=$this->pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
        }
        return $snapshot;
    }
}
