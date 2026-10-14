<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Core/ContractFormDraft.php';

final class ContractFormDraftTest extends TestCase
{
    protected function setUp(): void { $_SESSION['contract_form_recovery']=[]; }
    protected function tearDown(): void { unset($_SESSION['contract_form_recovery']); }

    public function testOnlyWhitelistedScalarFieldsAreKeptWithinCharacterLimits(): void
    {
        $fields=\ContractFormDraft::fields('adicionar-produto',[
            'nome'=>str_repeat('á',200),'marca'=>['nested'=>'not accepted'],'unidade'=>'un',
            'quantidade'=>'invalid scalar','preco'=>'1,00','_csrf_token'=>'secret',
            'chave'=>'idempotency secret','produto'=>'99','revisao'=>'1','extra'=>'discard',
        ]);
        $this->assertSame(150,mb_strlen($fields['nome']));
        $this->assertSame(['nome','unidade','quantidade','preco'],array_keys($fields));
        $this->assertSame('invalid scalar',$fields['quantidade']);
        $this->assertSame([],\ContractFormDraft::fields('observacao',['observacao'=>"\xFF"]));
        $this->assertSame([],\ContractFormDraft::fields('observacao',['observacao'=>"Texto\0inválido"]));
        $this->assertSame(2000,mb_strlen(\ContractFormDraft::fields('observacao',['observacao'=>str_repeat('á',3000)])['observacao']));
    }

    public function testRecoveryIdentifierBelongsToTheExactContractAndSurvivesRefreshUntilSuccess(): void
    {
        $id=\ContractFormDraft::save(4,'observacao',12,0,['observacao'=>'Primeira nota','revisao'=>'2']);
        $this->assertNull(\ContractFormDraft::read(5,$id));
        $draft=\ContractFormDraft::read(4,$id);
        $this->assertSame(['observacao'=>'Primeira nota'],$draft['fields']);
        $this->assertSame(12,$draft['sheet']);
        $this->assertSame(2,$draft['revision']);
        $this->assertNotNull(\ContractFormDraft::read(4,$id));
        \ContractFormDraft::clear(4,'observacao',12,0,$draft['context']);
        $this->assertNull(\ContractFormDraft::read(4,$id));
    }

    public function testSuccessClearsOnlyTheSameOperationResourceAndPageContext(): void
    {
        $context=str_repeat('a',32); $other=str_repeat('b',32);
        $saved=\ContractFormDraft::save(4,'observacao',12,0,['_form_context'=>$context]);
        $differentTab=\ContractFormDraft::save(4,'observacao',12,0,['_form_context'=>$other]);
        $differentNote=\ContractFormDraft::save(4,'observacao',13,0,['_form_context'=>$context]);
        $differentOperation=\ContractFormDraft::save(4,'faturar',12,0,['_form_context'=>$context]);
        $differentContract=\ContractFormDraft::save(5,'observacao',12,0,['_form_context'=>$context]);
        \ContractFormDraft::clear(4,'observacao',12,0,$context);
        $this->assertNull(\ContractFormDraft::read(4,$saved));
        foreach ([$differentTab,$differentNote,$differentOperation] as $id) { $this->assertNotNull(\ContractFormDraft::read(4,$id)); }
        $this->assertNotNull(\ContractFormDraft::read(5,$differentContract));
    }

    public function testDraftCountExpirationAndMalformedSessionDataAreBounded(): void
    {
        $first='';
        for ($i=0;$i<20;$i++) { $id=\ContractFormDraft::save(1,'observacao',$i,0,[]); if ($i===0) { $first=$id; } }
        $this->assertCount(12,$_SESSION['contract_form_recovery']);
        $this->assertNull(\ContractFormDraft::read(1,$first));
        $expired=\ContractFormDraft::save(1,'observacao',99,0,[]);
        $_SESSION['contract_form_recovery'][$expired]['created']=time()-1801;
        $_SESSION['contract_form_recovery']['invalid']=['fields'=>['observacao'=>'bad']];
        $this->assertNull(\ContractFormDraft::read(1,$expired));
        $this->assertArrayNotHasKey('invalid',$_SESSION['contract_form_recovery']);
    }

    public function testInvalidIdentifiersCannotSelectDraftsAndAuthorizationTokensAreNeverStored(): void
    {
        $id=\ContractFormDraft::save(1,'estoque',2,3,[
            '_form_context'=>['bad'],'_csrf_token'=>'csrf secret','chave'=>'idempotency secret',
            'revisao'=>['bad'],'abertura'=>'0','confirmar_abertura'=>'1',
        ]);
        $this->assertNull(\ContractFormDraft::read(1,[$id]));
        $this->assertNull(\ContractFormDraft::read(1,'../'.$id));
        $draft=\ContractFormDraft::read(1,$id);
        $this->assertNull($draft['revision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',$draft['context']);
        $encoded=json_encode($draft,JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret',$encoded);
        $this->assertSame(['abertura'=>'0','confirmar_abertura'=>'1'],$draft['fields']);
    }

    public function testUncheckedBillingIsPreservedWithoutAnAuthorizationOrFormMarker(): void
    {
        $fields=\ContractFormDraft::fields('faturar',['data'=>'2026-10-02','motivo'=>'Conferência','_billing_form'=>'1','_csrf_token'=>'secret']);
        $this->assertSame(['data'=>'2026-10-02','motivo'=>'Conferência','faturado'=>''],$fields);
        $this->assertSame(['faturado'=>'1'],\ContractFormDraft::fields('faturar',['faturado'=>'1']));
    }
}
