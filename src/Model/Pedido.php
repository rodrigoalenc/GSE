<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Pedido extends Model
{
    public function recalcularValorPagina($id_pedido, $numero_pagina)
    {
        $sql = "SELECT SUM(valor_total) as total FROM pedido_produtos WHERE id_pedido = ? AND numero_pagina = ?";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$id_pedido, $numero_pagina]);
        $total = $stmt->fetch()['total'] ?? 0;

        $sqlUpd = "UPDATE pedido_paginas SET valor_pagina = ? WHERE id_pedido = ? AND numero_pagina = ?";
        self::$pdo->prepare($sqlUpd)->execute([$total, $id_pedido, $numero_pagina]);
    }

    public function salvarPedidoCompleto($titulo, $valor_total, $qtd_paginas, $produtos)
    {
        try {
            self::$pdo->beginTransaction();

            $sqlPedido = "INSERT INTO pedidos (titulo, valor_total, qtd_paginas) VALUES (?, ?, ?)";
            $stmt = self::$pdo->prepare($sqlPedido);
            $stmt->execute([$titulo, $valor_total, $qtd_paginas]);
            $id_pedido = self::$pdo->lastInsertId();

            $sqlPagina = "INSERT INTO pedido_paginas (id_pedido, numero_pagina, valor_pagina, observacao, data_faturamento) VALUES (?, ?, 0, '', NULL)";
            $stmtPagina = self::$pdo->prepare($sqlPagina);
            for ($i = 1; $i <= $qtd_paginas; $i++) {
                $stmtPagina->execute([$id_pedido, $i]);
            }

            $sqlProduto = "INSERT INTO pedido_produtos (id_pedido, numero_pagina, nome_produto, marca, unidade, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtProduto = self::$pdo->prepare($sqlProduto);

            foreach ($produtos as $p) {
                $valor_total_produto = $p['quantidade'] * $p['valor_unitario'];
                $stmtProduto->execute([
                    $id_pedido,
                    1,
                    $p['nome'],
                    $p['marca'] ?? '',
                    $p['unidade'],
                    $p['quantidade'],
                    $p['valor_unitario'],
                    $valor_total_produto
                ]);
            }

            self::$pdo->commit();
            $this->recalcularValorPagina($id_pedido, 1);
            return true;
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }

    public function atualizarPedidoCompleto($id, $titulo, $valor_total, $qtd_paginas, $produtos)
    {
        try {
            self::$pdo->beginTransaction();

            $sqlPedido = "UPDATE pedidos SET titulo = ?, valor_total = ?, qtd_paginas = ? WHERE id = ?";
            self::$pdo->prepare($sqlPedido)->execute([$titulo, $valor_total, $qtd_paginas, $id]);

            self::$pdo->prepare("DELETE FROM pedido_paginas WHERE id_pedido = ?")->execute([$id]);
            self::$pdo->prepare("DELETE FROM pedido_produtos WHERE id_pedido = ?")->execute([$id]);

            $sqlPagina = "INSERT INTO pedido_paginas (id_pedido, numero_pagina, valor_pagina, observacao, data_faturamento) VALUES (?, ?, 0, '', NULL)";
            $stmtPagina = self::$pdo->prepare($sqlPagina);
            for ($i = 1; $i <= $qtd_paginas; $i++) {
                $stmtPagina->execute([$id, $i]);
            }

            $sqlProduto = "INSERT INTO pedido_produtos (id_pedido, numero_pagina, nome_produto, marca, unidade, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtProduto = self::$pdo->prepare($sqlProduto);

            foreach ($produtos as $p) {
                $valor_total_produto = $p['quantidade'] * $p['valor_unitario'];
                $stmtProduto->execute([
                    $id,
                    1,
                    $p['nome'],
                    $p['marca'] ?? '',
                    $p['unidade'],
                    $p['quantidade'],
                    $p['valor_unitario'],
                    $valor_total_produto
                ]);
            }

            self::$pdo->commit();
            $this->recalcularValorPagina($id, 1);
            return true;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }

    public function atualizarDadosGerais($id, $titulo, $valor_total)
    {
        try {
            $sql = "UPDATE pedidos SET titulo = ?, valor_total = ? WHERE id = ?";
            return self::$pdo->prepare($sql)->execute([$titulo, $valor_total, $id]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function listarTodos()
    {
        return self::$pdo->query("SELECT * FROM pedidos ORDER BY id DESC")->fetchAll();
    }

    public function buscarPorId($id)
    {
        $stmt = self::$pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function buscarProdutos($id_pedido)
    {
        $stmt = self::$pdo->prepare("SELECT * FROM pedido_produtos WHERE id_pedido = ?");
        $stmt->execute([$id_pedido]);
        return $stmt->fetchAll();
    }

    public function buscarPaginas($id_pedido)
    {
        $stmt = self::$pdo->prepare("SELECT * FROM pedido_paginas WHERE id_pedido = ? ORDER BY numero_pagina ASC");
        $stmt->execute([$id_pedido]);
        return $stmt->fetchAll();
    }

    public function buscarPagina($id_pedido, $numero_pagina)
    {
        $stmt = self::$pdo->prepare("SELECT * FROM pedido_paginas WHERE id_pedido = ? AND numero_pagina = ?");
        $stmt->execute([$id_pedido, $numero_pagina]);
        return $stmt->fetch();
    }

    public function paginaExiste($id_pedido, $numero_pagina)
    {
        $stmt = self::$pdo->prepare("SELECT 1 FROM pedido_paginas WHERE id_pedido = ? AND numero_pagina = ?");
        $stmt->execute([$id_pedido, $numero_pagina]);
        return (bool)$stmt->fetchColumn();
    }

    public function contarPaginas($id_pedido)
    {
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM pedido_paginas WHERE id_pedido = ?");
        $stmt->execute([$id_pedido]);
        return (int)$stmt->fetchColumn();
    }

    public function atualizarObservacaoPagina($id_pedido, $numero_pagina, $observacao)
    {
        $stmt = self::$pdo->prepare("UPDATE pedido_paginas SET observacao = ? WHERE id_pedido = ? AND numero_pagina = ?");
        return $stmt->execute([$observacao, $id_pedido, $numero_pagina]);
    }

    public function atualizarDataFaturamentoPagina($id_pedido, $numero_pagina, $data_faturamento)
    {
        $stmt = self::$pdo->prepare("UPDATE pedido_paginas SET data_faturamento = ? WHERE id_pedido = ? AND numero_pagina = ?");
        return $stmt->execute([$data_faturamento, $id_pedido, $numero_pagina]);
    }

    public function excluir($id)
    {
        try {
            self::$pdo->beginTransaction();
            self::$pdo->prepare("DELETE FROM pedido_produtos WHERE id_pedido = ?")->execute([$id]);
            self::$pdo->prepare("DELETE FROM pedido_paginas WHERE id_pedido = ?")->execute([$id]);
            self::$pdo->prepare("DELETE FROM pedidos WHERE id = ?")->execute([$id]);
            self::$pdo->commit();
            return true;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }

    public function adicionarProdutoUnico($id_pedido, $numero_pagina, $nome, $marca, $unidade, $quantidade, $valor_unitario)
    {
        $valor_total = $quantidade * $valor_unitario;
        $sql = "INSERT INTO pedido_produtos (id_pedido, numero_pagina, nome_produto, marca, unidade, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $resultado = self::$pdo->prepare($sql)->execute([$id_pedido, $numero_pagina, $nome, $marca, $unidade, $quantidade, $valor_unitario, $valor_total]);

        if ($resultado) {
            $this->recalcularValorPagina($id_pedido, $numero_pagina);
        }
        return $resultado;
    }

    public function buscarProdutoPorId($id_produto)
    {
        $stmt = self::$pdo->prepare("SELECT * FROM pedido_produtos WHERE id = ?");
        $stmt->execute([$id_produto]);
        return $stmt->fetch();
    }

    public function buscarProdutoPorIdPedido($id_produto)
    {
        $stmt = self::$pdo->prepare("SELECT p.*, c.id AS id_pedido, c.titulo FROM pedido_produtos p JOIN pedidos c ON c.id = p.id_pedido WHERE p.id = ?");
        $stmt->execute([$id_produto]);
        return $stmt->fetch();
    }

    public function atualizarProduto($id_produto, $nome, $marca, $unidade, $quantidade, $valor_unitario)
    {
        $produto = $this->buscarProdutoPorId($id_produto);
        if (!$produto) {
            return false;
        }

        $valor_total = $quantidade * $valor_unitario;
        $sql = "UPDATE pedido_produtos SET nome_produto = ?, marca = ?, unidade = ?, quantidade = ?, valor_unitario = ?, valor_total = ? WHERE id = ?";
        $resultado = self::$pdo->prepare($sql)->execute([$nome, $marca, $unidade, $quantidade, $valor_unitario, $valor_total, $id_produto]);

        if ($resultado) {
            $this->recalcularValorPagina($produto['id_pedido'], $produto['numero_pagina']);
        }
        return $resultado;
    }

    public function excluirProduto($id_produto)
    {
        $produto = $this->buscarProdutoPorId($id_produto);
        if (!$produto) {
            return false;
        }

        $resultado = self::$pdo->prepare("DELETE FROM pedido_produtos WHERE id = ?")->execute([$id_produto]);

        if ($resultado) {
            $this->recalcularValorPagina($produto['id_pedido'], $produto['numero_pagina']);
        }
        return $resultado;
    }

    public function excluirPagina($id_pedido, $numero_pagina)
    {
        try {
            if ($this->contarPaginas($id_pedido) <= 1) {
                return false;
            }

            self::$pdo->beginTransaction();
            self::$pdo->prepare("DELETE FROM pedido_produtos WHERE id_pedido = ? AND numero_pagina = ?")->execute([$id_pedido, $numero_pagina]);
            self::$pdo->prepare("DELETE FROM pedido_paginas WHERE id_pedido = ? AND numero_pagina = ?")->execute([$id_pedido, $numero_pagina]);

            self::$pdo->prepare("UPDATE pedido_produtos SET numero_pagina = numero_pagina - 1 WHERE id_pedido = ? AND numero_pagina > ?")->execute([$id_pedido, $numero_pagina]);
            self::$pdo->prepare("UPDATE pedido_paginas SET numero_pagina = numero_pagina - 1 WHERE id_pedido = ? AND numero_pagina > ?")->execute([$id_pedido, $numero_pagina]);
            self::$pdo->prepare("UPDATE pedidos SET qtd_paginas = qtd_paginas - 1 WHERE id = ?")->execute([$id_pedido]);

            self::$pdo->commit();
            return true;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }

    public function adicionarPagina($id_pedido)
    {
        try {
            self::$pdo->beginTransaction();
            $stmtMax = self::$pdo->prepare("SELECT MAX(numero_pagina) as max_pagina FROM pedido_paginas WHERE id_pedido = ?");
            $stmtMax->execute([$id_pedido]);
            $nova_pagina = ($stmtMax->fetch()['max_pagina'] ?? 0) + 1;

            self::$pdo->prepare("INSERT INTO pedido_paginas (id_pedido, numero_pagina, valor_pagina, observacao, data_faturamento) VALUES (?, ?, 0, '', NULL)")->execute([$id_pedido, $nova_pagina]);
            self::$pdo->prepare("UPDATE pedidos SET qtd_paginas = qtd_paginas + 1 WHERE id = ?")->execute([$id_pedido]);

            self::$pdo->commit();
            return true;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }

    public function duplicarPagina($id_pedido, $numero_pagina_origem)
    {
        try {
            self::$pdo->beginTransaction();

            $stmtMax = self::$pdo->prepare("SELECT MAX(numero_pagina) as max_pagina FROM pedido_paginas WHERE id_pedido = ?");
            $stmtMax->execute([$id_pedido]);
            $nova_pagina = ($stmtMax->fetch()['max_pagina'] ?? 0) + 1;

            $paginaOrigem = $this->buscarPagina($id_pedido, $numero_pagina_origem);
            $observacaoPagina = trim((string)($paginaOrigem['observacao'] ?? ''));

            self::$pdo->prepare("INSERT INTO pedido_paginas (id_pedido, numero_pagina, valor_pagina, observacao, data_faturamento) VALUES (?, ?, 0, ?, NULL)")->execute([$id_pedido, $nova_pagina, $observacaoPagina]);

            $stmtProd = self::$pdo->prepare("SELECT * FROM pedido_produtos WHERE id_pedido = ? AND numero_pagina = ?");
            $stmtProd->execute([$id_pedido, $numero_pagina_origem]);
            $produtos = $stmtProd->fetchAll();

            $sqlInsertProd = "INSERT INTO pedido_produtos (id_pedido, numero_pagina, nome_produto, marca, unidade, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtIns = self::$pdo->prepare($sqlInsertProd);

            foreach ($produtos as $p) {
                $stmtIns->execute([
                    $id_pedido,
                    $nova_pagina,
                    $p['nome_produto'],
                    $p['marca'],
                    $p['unidade'],
                    $p['quantidade'],
                    $p['valor_unitario'],
                    $p['valor_total']
                ]);
            }

            self::$pdo->prepare("UPDATE pedidos SET qtd_paginas = qtd_paginas + 1 WHERE id = ?")->execute([$id_pedido]);
            self::$pdo->commit();

            $this->recalcularValorPagina($id_pedido, $nova_pagina);
            return $nova_pagina;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            return false;
        }
    }
}
