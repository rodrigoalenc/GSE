<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use Tests\Support\DatabaseTestCase;

final class ContratoResourceLimitsTest extends DatabaseTestCase
{
    /** @return array<string,string> */
    private function product(): array
    {
        return ['nome' => 'Produto fictício', 'marca' => '', 'unidade' => 'UN', 'quantidade' => '1', 'preco' => '0,01'];
    }

    public function testTotalSheetQuotaIncludesDeletedHistoryAndLeavesRevisionAndAuditUnchanged(): void
    {
        $actor = $this->insertUsuario();
        $model = new Contrato();
        $id = $model->createDetailed('Notas no limite', '100,00', '', array_fill(0, Contrato::MAX_SHEETS, ['observacao' => '', 'produtos' => []]), $actor);
        $sheet = $model->sheets($id)[0];
        $model->delete($id, (int)$sheet['id'], null, (int)$model->find($id)['revisao'], $actor);
        $before = $model->find($id);
        $events = (int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        try { $model->addSheet($id, '', (int)$before['revisao'], $actor); self::fail('A deleted note bypassed the quota.'); }
        catch (DomainException $exception) { self::assertStringContainsString('20 notas', $exception->getMessage()); }
        self::assertCount(Contrato::MAX_SHEETS, $model->sheets($id));
        self::assertSame($before, $model->find($id));
        self::assertSame($events, (int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }

    public function testItemQuotaAllowsTheBoundaryAndIncludesDeletedHistory(): void
    {
        $actor = $this->insertUsuario();
        $model = new Contrato();
        $id = $model->createDetailed('Produtos no limite', '100,00', '', [['produtos' => array_fill(0, Contrato::MAX_ITEMS - 1, $this->product())]], $actor);
        $sheet = (int)$model->sheets($id)[0]['id'];
        $item = $model->addItem($id, $sheet, $this->product(), (int)$model->find($id)['revisao'], $actor);
        self::assertCount(Contrato::MAX_ITEMS, $model->items($id));
        $model->delete($id, null, $item, (int)$model->find($id)['revisao'], $actor);
        $revision = (int)$model->find($id)['revisao'];
        try { $model->addItem($id, $sheet, $this->product(), $revision, $actor); self::fail('A deleted item bypassed the quota.'); }
        catch (DomainException $exception) { self::assertStringContainsString('100 produtos', $exception->getMessage()); }
        self::assertSame($revision, (int)$model->find($id)['revisao']);
        self::assertCount(Contrato::MAX_ITEMS, $model->items($id));
    }

    public function testDuplicatingSheetChecksAggregateItemQuotaBeforeWritingReceiptOrSheet(): void
    {
        $actor = $this->insertUsuario();
        $model = new Contrato();
        $id = $model->createDetailed('Duplicação limitada', '100,00', '', [['produtos' => array_fill(0, 51, $this->product())]], $actor);
        $sheet = (int)$model->sheets($id)[0]['id'];
        $revision = (int)$model->find($id)['revisao'];
        $operations = (int)$this->pdo->query('SELECT COUNT(*) FROM modulo5_operacoes')->fetchColumn();
        try { $model->addSheet($id, '', $revision, $actor, $sheet, str_repeat('d', 32)); self::fail('A duplicate exceeded the item quota.'); }
        catch (DomainException $exception) { self::assertStringContainsString('100 produtos', $exception->getMessage()); }
        self::assertCount(1, $model->sheets($id));
        self::assertCount(51, $model->items($id));
        self::assertSame($revision, (int)$model->find($id)['revisao']);
        self::assertSame($operations, (int)$this->pdo->query('SELECT COUNT(*) FROM modulo5_operacoes')->fetchColumn());
    }

    public function testOversizedLegacyContractIsRejectedAsAWholeButIndividualSheetStaysReadable(): void
    {
        $actor = $this->insertUsuario();
        $model = new Contrato();
        $id = $model->create('Pedido legado extenso', '100,00', '', $actor);
        $first = $model->sheets($id)[0];
        $insert = $this->pdo->prepare('INSERT INTO pedido_paginas(id_pedido,numero_pagina,valor_pagina,valor_centavos,observacao) VALUES(?,?,0,0,?)');
        for ($number = 2; $number <= Contrato::MAX_SHEETS + 1; $number++) { $insert->execute([$id, $number, 'Histórico fictício']); }
        try { $model->sheets($id); self::fail('The complete legacy set was loaded.'); }
        catch (DomainException $exception) { self::assertStringContainsString('revisão técnica', $exception->getMessage()); }
        try { $model->items($id); self::fail('The complete legacy items were loaded.'); }
        catch (DomainException $exception) { self::assertStringContainsString('revisão técnica', $exception->getMessage()); }
        self::assertSame($first, $model->sheet($id, (int)$first['id']));
        self::assertSame([], $model->items($id, (int)$first['numero_pagina']));
    }
}
