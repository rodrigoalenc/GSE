<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PedidoTest extends DatabaseTestCase
{
    public function testFluxosDePedido(): void
    {
        $pedido = new \Pedido();
        $produtos = [
            ['nome' => 'Caderno', 'marca' => 'Marca A', 'unidade' => 'un', 'quantidade' => 2, 'valor_unitario' => 10],
            ['nome' => 'Caneta', 'marca' => 'Marca B', 'unidade' => 'cx', 'quantidade' => 1, 'valor_unitario' => 5],
        ];

        $this->assertTrue($pedido->salvarPedidoCompleto('Pedido de material', 25.0, 2, $produtos));
        $this->assertCount(1, $pedido->listarTodos());
        $this->assertSame('Pedido de material', $pedido->buscarPorId(1)['titulo'] ?? null);
        $this->assertCount(2, $pedido->buscarPaginas(1));
        $this->assertCount(2, $pedido->buscarProdutos(1));
        $this->assertSame(25.0, (float) $pedido->buscarPagina(1, 1)['valor_pagina']);
        $this->assertTrue($pedido->paginaExiste(1, 2));
        $this->assertSame(2, $pedido->contarPaginas(1));
        $this->assertTrue($pedido->atualizarObservacaoPagina(1, 1, 'Obs'));
        $this->assertTrue($pedido->atualizarDataFaturamentoPagina(1, 1, '2026-01-10'));
        $this->assertTrue($pedido->adicionarProdutoUnico(1, 2, 'Lapis', 'Marca C', 'un', 3, 2));

        $this->assertSame('Caderno', $pedido->buscarProdutoPorId(1)['nome_produto'] ?? null);
        $this->assertSame('Pedido de material', $pedido->buscarProdutoPorIdPedido(1)['titulo'] ?? null);
        $this->assertTrue($pedido->atualizarProduto(1, 'Caderno 2', 'Marca A', 'un', 2, 12));
        $this->assertTrue($pedido->excluirProduto(2));
        $this->assertTrue($pedido->adicionarPagina(1));
        $this->assertSame(4, $pedido->duplicarPagina(1, 1));
        $this->assertTrue($pedido->excluirPagina(1, 3));
        $this->assertTrue($pedido->atualizarDadosGerais(1, 'Pedido atualizado', 30));

        $novosProdutos = [
            ['nome' => 'Borracha', 'marca' => 'Marca D', 'unidade' => 'un', 'quantidade' => 4, 'valor_unitario' => 1.5],
        ];
        $this->assertTrue($pedido->atualizarPedidoCompleto(1, 'Pedido completo atualizado', 6.0, 1, $novosProdutos));
        $this->assertSame(1, $pedido->contarPaginas(1));
        $this->assertFalse($pedido->excluirPagina(1, 1));
        $this->assertTrue($pedido->excluir(1));
        $this->assertFalse($pedido->buscarPorId(1));
    }
}
