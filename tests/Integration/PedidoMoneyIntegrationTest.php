<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contrato;
use DomainException;
use Tests\Support\DatabaseTestCase;

final class PedidoMoneyIntegrationTest extends DatabaseTestCase
{
    public function testGroupedBudgetAndPricesAreStoredAsCentsAndKeepAllocationLimits(): void
    {
        $actor = $this->insertUsuario();
        $model = new Contrato();
        $product = ['nome'=>'Papel fictício','marca'=>'','unidade'=>'UN','quantidade'=>'2','preco'=>'1.250,01'];
        $id = $model->createDetailed('Pedido fictício', '2.500,02', '', [['observacao'=>'', 'produtos'=>[$product]]], $actor);
        self::assertSame(250002, (int)$model->find($id)['valor_centavos']);
        self::assertSame(125001, (int)$model->items($id)[0]['preco_centavos']);
        self::assertSame(250002, (int)$model->items($id)[0]['total_centavos']);
        self::assertSame(0, $model->allocationSummary($id)['remaining']);
        $sheet = (int)$model->sheets($id)[0]['id'];
        $product['quantidade'] = '1';
        $product['preco'] = '0,01';
        try {
            $model->addItem($id, $sheet, $product, (int)$model->find($id)['revisao'], $actor);
            self::fail('O agrupamento não deve liberar o teto financeiro.');
        } catch (DomainException) {
            self::assertCount(1, $model->items($id));
            self::assertSame(0, $model->allocationSummary($id)['remaining']);
        }
    }
}
