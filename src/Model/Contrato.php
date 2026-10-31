<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';

use src\Core\SqliteTransaction;

require_once ROOT_PATH . '/src/Core/RequestReceipt.php';
require_once ROOT_PATH . '/src/Services/ContratoCertidaoService.php';

/** @phpstan-import-type ExpiredCertificate from ContratoCertidaoService */
final class Contrato extends Model
{
    public const MAX_SHEETS = 20;
    public const MAX_ITEMS = 100;

    public function __construct(private readonly ?CertidaoStatus $certificateDates = null)
    {
        parent::__construct();
    }

    private function certificateRules(): ContratoCertidaoService
    {
        return new ContratoCertidaoService(self::getConexao(), $this->certificateDates);
    }

    /** @return list<ExpiredCertificate> */
    public function expiredCertificates(?int $supplierId): array
    {
        return $supplierId === null ? [] : $this->certificateRules()->expired($supplierId);
    }

    /** @return array<int,list<ExpiredCertificate>> */
    public function expiredCertificateGroups(): array
    {
        $groups = [];
        foreach ($this->certificateRules()->expired() as $certificate) {
            $groups[$certificate['id_fornecedor']][] = $certificate;
        }
        return $groups;
    }

    public static function integer(mixed $value, int $min = 0, int $max = 1000000000): int
    {
        if (!is_string($value) && !is_int($value)) { throw new DomainException('Número inválido.'); }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) { throw new DomainException('Número fora dos limites permitidos.'); }
        return $number;
    }

    public static function cents(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,8}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,2})(?:,[0-9]{1,2})?$/D', $value) !== 1) {
            throw new DomainException('Valor inválido. Use 1250,00 ou 1.250,00, com até duas casas decimais e valor máximo de R$ 999.999.999,99.');
        }
        [$whole, $fraction] = array_pad(explode(',', str_replace('.', '', $value), 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function money(?int $cents): string
    {
        return $cents === null ? 'Legado: revisão pendente' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function list(string $search = '', int $page = 1, string $status = 'ativos'): array
    {
        if (!in_array($status, ['ativos', 'excluidos'], true)) { throw new DomainException('Filtro de pedidos inválido.'); }
        $page = max(1, $page);
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100)) . '%';
        $where = 'WHERE p.excluido_em IS ' . ($status === 'ativos' ? 'NULL' : 'NOT NULL') . ' AND p.titulo LIKE ? ESCAPE \'\\\'';
        $count = self::$pdo->prepare('SELECT COUNT(*) FROM pedidos p ' . $where);
        $count->execute([$term]);
        $total = (int) $count->fetchColumn();
        $page = min($page, max(1, (int) ceil($total / 20)));
        $query = self::$pdo->prepare('SELECT p.*, f.nome AS fornecedor, gse_name(u.nome,u.id) AS excluido_por_nome, '
            . '(SELECT COUNT(*) FROM pedido_paginas pg WHERE pg.id_pedido=p.id AND pg.excluido_em IS NULL) AS notas, '
            . '(SELECT COUNT(*) FROM pedido_paginas pg WHERE pg.id_pedido=p.id AND pg.excluido_em IS NULL AND pg.data_faturamento IS NOT NULL) AS notas_faturadas '
            . 'FROM pedidos p LEFT JOIN lista_fornecedores f ON f.id=p.id_fornecedor '
            . 'LEFT JOIN usuarios u ON u.id=p.excluido_por ' . $where . ' ORDER BY p.id DESC LIMIT 20 OFFSET ?');
        $query->bindValue(1, $term);
        $query->bindValue(2, ($page - 1) * 20, PDO::PARAM_INT);
        $query->execute();
        return ['items' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    /** @return array<string,mixed> */
    public function summary(string $search = ''): array
    {
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100)) . '%';
        $query = self::$pdo->prepare("SELECT COUNT(*) AS contratos, COALESCE(SUM(p.valor_centavos),0) AS valor_centavos,
            SUM(CASE WHEN p.valor_centavos IS NULL THEN 1 ELSE 0 END) AS valores_pendentes,
            COALESCE(SUM((SELECT COUNT(*) FROM pedido_paginas pg WHERE pg.id_pedido=p.id AND pg.excluido_em IS NULL)),0) AS notas,
            COALESCE(SUM((SELECT COUNT(*) FROM pedido_paginas pg WHERE pg.id_pedido=p.id AND pg.excluido_em IS NULL AND pg.data_faturamento IS NOT NULL)),0) AS faturadas
            FROM pedidos p WHERE p.excluido_em IS NULL AND p.titulo LIKE ? ESCAPE '\\'");
        $query->execute([$term]);
        return $query->fetch();
    }

    /** @return list<array<string,mixed>> */
    public function suppliers(): array
    {
        return self::$pdo->query('SELECT id,nome,ativo FROM lista_fornecedores ORDER BY nome')->fetchAll();
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $query = self::$pdo->prepare('SELECT p.*, f.nome AS fornecedor, gse_name(u.nome,u.id) AS excluido_por_nome FROM pedidos p LEFT JOIN lista_fornecedores f ON f.id=p.id_fornecedor LEFT JOIN usuarios u ON u.id=p.excluido_por WHERE p.id=?');
        $query->execute([$id]);
        return $query->fetch() ?: throw new DomainException('Pedido não encontrado.');
    }

    /** @return list<array<string,mixed>> */
    public function sheets(int $contractId): array
    {
        $this->assertCapacity($contractId);
        $query = self::$pdo->prepare('SELECT * FROM pedido_paginas WHERE id_pedido=? ORDER BY numero_pagina,id');
        $query->execute([$contractId]);
        return $query->fetchAll();
    }

    /** @return array{original_limit:?int,additive_limit:?int,original_allocated:?int,additive_allocated:?int,total_allocated:?int,has_additive:bool,legacy:bool,final_limit:?int,remaining:?int,original_remaining:?int,additive_remaining:?int} */
    public function allocationSummary(int $contractId): array
    {
        $contract=$this->find($contractId);
        $allocation=$this->allocationGroups($contractId);
        $originalLimit=$contract['valor_centavos']===null ? null : (int)$contract['valor_centavos'];
        $additiveLimit=$originalLimit===null ? null : intdiv($originalLimit,4);
        $originalAllocated=$allocation[0]['pending'] ? null : $allocation[0]['total'];
        $additiveAllocated=$allocation[1]['pending'] ? null : $allocation[1]['total'];
        $total=$originalAllocated===null || $additiveAllocated===null ? null : $originalAllocated+$additiveAllocated;
        $query=self::$pdo->prepare('SELECT 1 FROM pedido_paginas WHERE id_pedido=? AND aditivo=1 AND excluido_em IS NULL LIMIT 1');
        $query->execute([$contractId]);
        $hasAdditive=$query->fetchColumn()!==false;
        $finalLimit=$originalLimit===null ? null : $originalLimit+($hasAdditive ? intdiv($originalLimit,4) : 0);
        return [
            'original_limit'=>$originalLimit,'additive_limit'=>$additiveLimit,
            'original_allocated'=>$originalAllocated,'additive_allocated'=>$additiveAllocated,'total_allocated'=>$total,
            'has_additive'=>$hasAdditive,'legacy'=>$originalLimit===null || $total===null,'final_limit'=>$finalLimit,
            'remaining'=>$finalLimit===null || $total===null ? null : $finalLimit-$total,
            'original_remaining'=>$originalLimit===null || $originalAllocated===null ? null : $originalLimit-$originalAllocated,
            'additive_remaining'=>$additiveLimit===null || $additiveAllocated===null ? null : $additiveLimit-$additiveAllocated,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function items(int $contractId, ?int $sheetNumber = null): array
    {
        $arguments = [$contractId];
        if ($sheetNumber === null) { $this->assertCapacity($contractId); }
        else {
            $arguments[] = $sheetNumber;
            $count = self::$pdo->prepare('SELECT COUNT(*) FROM pedido_produtos WHERE id_pedido=? AND numero_pagina=?');
            $count->execute($arguments);
            if ((int)$count->fetchColumn() > self::MAX_ITEMS) { throw new DomainException('Esta nota ultrapassa 100 produtos e precisa de revisão técnica antes da impressão.'); }
        }
        $scope = $sheetNumber === null ? '' : ' AND i.numero_pagina=?';
        $query = self::$pdo->prepare("SELECT i.*, COALESCE((SELECT SUM(m.quantidade) FROM estoque_movimentos m WHERE m.produto_id=i.id),0) AS saldo, EXISTS(SELECT 1 FROM modulo5_valores_legados v WHERE v.tabela='pedido_produtos' AND v.registro_id=i.id) AS legado, CASE WHEN i.estoque_inicializado=1 AND NOT EXISTS(SELECT 1 FROM estoque_movimentos m WHERE m.produto_id=i.id) AND NOT EXISTS(SELECT 1 FROM modulo5_operacoes o WHERE o.recurso_id=i.id AND o.tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida')) THEN 1 ELSE 0 END AS abertura_sem_comprovacao FROM pedido_produtos i WHERE i.id_pedido=?" . $scope . ' ORDER BY i.numero_pagina,i.id');
        $query->execute($arguments);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function movements(int $itemId): array
    {
        $query = self::$pdo->prepare('SELECT m.*,gse_name(u.nome,u.id) AS usuario,i.nome_produto,i.unidade,i.numero_pagina,p.id AS contrato_id,p.titulo AS contrato_titulo FROM estoque_movimentos m JOIN usuarios u ON u.id=m.usuario_id JOIN pedido_produtos i ON i.id=m.produto_id JOIN pedidos p ON p.id=i.id_pedido WHERE m.produto_id=? ORDER BY m.id DESC');
        $query->execute([$itemId]);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function stockHistory(int $itemId): array
    {
        $history=$this->movements($itemId);
        $query=self::$pdo->prepare("SELECT NULL AS id,i.id AS produto_id,'abertura' AS tipo,0 AS quantidade,
            CASE WHEN o.tipo='abertura_zero_conferida' THEN 'Contagem física atual confirmada' ELSE 'Quantidade inicial confirmada' END AS motivo,
            o.usuario_id,o.criado_em,o.idempotencia,NULL AS movimento_original_id,gse_name(u.nome,u.id) AS usuario,
            i.nome_produto,i.unidade,i.numero_pagina,p.id AS contrato_id,p.titulo AS contrato_titulo
            FROM modulo5_operacoes o JOIN pedido_produtos i ON i.id=o.recurso_id
            JOIN pedidos p ON p.id=i.id_pedido JOIN usuarios u ON u.id=o.usuario_id
            WHERE o.recurso_id=? AND o.tipo IN ('abertura_estoque','abertura_zero_conferida')
            AND NOT EXISTS(SELECT 1 FROM estoque_movimentos m WHERE m.idempotencia=o.idempotencia)");
        $query->execute([$itemId]);
        $history=array_merge($history,$query->fetchAll());
        usort($history,static fn(array $a,array $b): int=>strcmp((string)$b['criado_em'],(string)$a['criado_em']) ?: (int)$b['id']<=>(int)$a['id']);
        return $history;
    }

    /** @return array<string,mixed> */
    public function itemForHistory(int $itemId): array
    {
        $query = self::$pdo->prepare('SELECT i.*,p.titulo AS contrato_titulo FROM pedido_produtos i JOIN pedidos p ON p.id=i.id_pedido WHERE i.id=?');
        $query->execute([$itemId]);
        return $query->fetch() ?: throw new DomainException('Produto não encontrado.');
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function stockList(string $search='',int $page=1): array
    {
        $term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],mb_substr(trim($search),0,100)).'%';
        $from=" FROM pedido_produtos i JOIN pedidos p ON p.id=i.id_pedido WHERE p.excluido_em IS NULL AND i.excluido_em IS NULL AND EXISTS(SELECT 1 FROM pedido_paginas pg WHERE pg.id_pedido=i.id_pedido AND pg.numero_pagina=i.numero_pagina AND pg.excluido_em IS NULL) AND (i.nome_produto LIKE ? ESCAPE '\\' OR i.marca LIKE ? ESCAPE '\\' OR p.titulo LIKE ? ESCAPE '\\')";
        $count=self::$pdo->prepare('SELECT COUNT(*)'.$from); $count->execute([$term,$term,$term]); $total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/20)); $page=max(1,min($page,$pages));
        $q=self::$pdo->prepare("SELECT i.*,p.titulo,COALESCE((SELECT SUM(m.quantidade) FROM estoque_movimentos m WHERE m.produto_id=i.id),0) AS saldo, EXISTS(SELECT 1 FROM modulo5_valores_legados v WHERE v.tabela='pedido_produtos' AND v.registro_id=i.id) AS legado, CASE WHEN i.estoque_inicializado=1 AND NOT EXISTS(SELECT 1 FROM estoque_movimentos m WHERE m.produto_id=i.id) AND NOT EXISTS(SELECT 1 FROM modulo5_operacoes o WHERE o.recurso_id=i.id AND o.tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida')) THEN 1 ELSE 0 END AS abertura_sem_comprovacao".$from.' ORDER BY i.id DESC LIMIT 20 OFFSET ?');
        $q->bindValue(1,$term); $q->bindValue(2,$term); $q->bindValue(3,$term); $q->bindValue(4,($page-1)*20,PDO::PARAM_INT); $q->execute();
        return ['items'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }

    public function create(string $title, string $money, string $supplier, int $actor, ?string $requestToken = null): int
    {
        $title = $this->title($title); $cents = self::cents($money); $supplierId = $this->supplier($supplier, null);
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($title,$cents,$supplierId,$actor,$requestToken): int {
            $result = RequestReceipt::run($pdo,$actor,'contract.create',$requestToken,[$title,$cents,$supplierId], function () use ($pdo,$title,$cents,$supplierId,$actor): int {
                $this->certificateRules()->assertCanCreate($supplierId);
                $query = $pdo->prepare('INSERT INTO pedidos(titulo,valor_total,valor_centavos,qtd_paginas,id_fornecedor) VALUES(?,?,?,?,?)');
                $query->execute([$title,$cents/100,$cents,1,$supplierId]);
                $id = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO pedido_paginas(id_pedido,numero_pagina,valor_pagina,valor_centavos,observacao) VALUES(?,1,0,0,\'\')')->execute([$id]);
                $this->audit($pdo,'created','contrato',$id,$actor);
                return $id;
            });
            if ($result === false) { throw new LogicException('Resultado de pedido inválido.'); }
            return $result;
        });
    }

    /** @param array<mixed> $sheets */
    public function createDetailed(string $title, string $money, string $supplier, array $sheets, int $actor, ?string $requestToken = null): int
    {
        $title = $this->title($title);
        $cents = self::cents($money);
        $supplierId = $this->supplier($supplier, null);
        if ($sheets === [] || count($sheets) > self::MAX_SHEETS) { throw new DomainException('Informe de 1 a 20 notas.'); }
        $validated = [];
        $allocated = 0;
        $itemCount = 0;
        foreach ($sheets as $sheet) {
            if (!is_array($sheet)) { throw new DomainException('Nota inválida.'); }
            $note = $sheet['observacao'] ?? '';
            $products = $sheet['produtos'] ?? [];
            if (!is_string($note) || mb_strlen($note) > 2000 || !is_array($products)) { throw new DomainException('Dados da nota inválidos.'); }
            $normalized = [];
            foreach ($products as $product) {
                if (!is_array($product)) { throw new DomainException('Produto inválido.'); }
                foreach (['nome','marca','unidade','quantidade','preco'] as $field) {
                    if (!is_string($product[$field] ?? null)) { throw new DomainException('Campo de produto inválido: '.$field); }
                }
                $name = $this->title($product['nome']);
                $brand = trim($product['marca']);
                $unit = trim($product['unidade']);
                if ($unit === '' || mb_strlen($unit)>30 || mb_strlen($brand)>100 || preg_match('/[\p{C}]/u',$unit.$brand)) {
                    throw new DomainException('Unidade ou marca inválida.');
                }
                $quantity = self::integer($product['quantidade'],1,1000000);
                $price = self::cents($product['preco']);
                if ($price > intdiv(PHP_INT_MAX,$quantity)) { throw new DomainException('Total fora do limite.'); }
                $total = $quantity*$price;
                if ($allocated > PHP_INT_MAX-$total) { throw new DomainException('Total fora do limite.'); }
                $allocated += $total;
                $normalized[] = [$name,$brand,$unit,$quantity,$price,$total];
                if (++$itemCount > self::MAX_ITEMS) { throw new DomainException('Limite de 100 produtos por pedido.'); }
            }
            $validated[] = [trim($note),$normalized];
        }
        if ($allocated > $cents) { throw new DomainException('Itens ultrapassam o valor contratado.'); }
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($title,$cents,$supplierId,$validated,$actor,$requestToken): int {
            $result = RequestReceipt::run($pdo,$actor,'contract.create',$requestToken,[$title,$cents,$supplierId,$validated], function () use ($pdo,$title,$cents,$supplierId,$actor,$validated): int {
                $this->certificateRules()->assertCanCreate($supplierId);
                $pdo->prepare('INSERT INTO pedidos(titulo,valor_total,valor_centavos,qtd_paginas,id_fornecedor) VALUES(?,?,?,?,?)')
                    ->execute([$title,$cents/100,$cents,count($validated),$supplierId]);
                $id = (int)$pdo->lastInsertId();
                $insertSheet = $pdo->prepare('INSERT INTO pedido_paginas(id_pedido,numero_pagina,valor_pagina,valor_centavos,observacao) VALUES(?,?,?,?,?)');
                $insertItem = $pdo->prepare('INSERT INTO pedido_produtos(id_pedido,numero_pagina,nome_produto,marca,unidade,quantidade,valor_unitario,valor_total,quantidade_contratada,preco_centavos,total_centavos) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($validated as $index => [$note,$products]) {
                    $number = $index+1;
                    $sheetTotal = array_sum(array_column($products,5));
                    $insertSheet->execute([$id,$number,$sheetTotal/100,$sheetTotal,$note]);
                    foreach ($products as [$name,$brand,$unit,$quantity,$price,$total]) {
                        $insertItem->execute([$id,$number,$name,$brand,$unit,$quantity,$price/100,$total/100,$quantity,$price,$total]);
                    }
                }
                $this->audit($pdo,'created','contrato',$id,$actor);
                return $id;
            });
            if ($result === false) { throw new LogicException('Resultado de pedido inválido.'); }
            return $result;
        });
    }

    public function update(int $id, string $title, string $money, string $supplier, int $revision, int $actor): void
    {
        $title = $this->title($title); $cents = self::cents($money);
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id,$title,$cents,$supplier,$revision,$actor): void {
            $old = $this->find($id);
            $this->active($old);
            $supplierId = $this->supplier($supplier, $old['id_fornecedor'] === null ? null : (int)$old['id_fornecedor']);
            $this->assertAllocationLimits($id,$cents);
            $query = $pdo->prepare('UPDATE pedidos SET titulo=?,valor_total=?,valor_centavos=?,id_fornecedor=?,revisao=revisao+1 WHERE id=? AND revisao=?');
            $query->execute([$title,$cents/100,$cents,$supplierId,$id,$revision]);
            if ($query->rowCount() !== 1) { throw new DomainException('Pedido alterado por outra pessoa. Atualize a página.'); }
            $this->audit($pdo,'updated','contrato',$id,$actor);
        });
    }

    public function reconcileLegacyItem(int $contractId,int $itemId,int $quantity,string $price,int $revision,int $actor): void
    {
        $cents=self::cents($price);
        if ($quantity<1 || $quantity>1000000 || $cents>intdiv(PHP_INT_MAX,$quantity)) { throw new DomainException('Quantidade ou preço fora do limite.'); }
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$itemId,$quantity,$cents,$revision,$actor): void {
            $item=$this->item($contractId,$itemId); $this->active($this->find($contractId));
            $this->activeItemSheet($contractId,(int)$item['numero_pagina']);
            if ($item['excluido_em']!==null) { throw new DomainException('Produto excluído não pode ser conciliado.'); }
            if ($item['total_centavos']!==null || (int)$item['revisao']!==$revision) { throw new DomainException('Item já conciliado ou alterado.'); }
            $pdo->prepare('UPDATE pedido_produtos SET quantidade_contratada=?,preco_centavos=?,total_centavos=?,revisao=revisao+1 WHERE id=?')->execute([$quantity,$cents,$quantity*$cents,$itemId]);
            $contract=$this->find($contractId);
            if ($contract['valor_centavos']!==null) { $this->assertAllocationLimits($contractId,(int)$contract['valor_centavos'],false); }
            $q=$pdo->prepare('SELECT COUNT(*) FROM pedido_produtos WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL AND total_centavos IS NULL');
            $q->execute([$contractId,$item['numero_pagina']]);
            if ((int)$q->fetchColumn()===0) { $this->recalculate($contractId,(int)$item['numero_pagina']); }
            $pdo->prepare('UPDATE pedidos SET revisao=revisao+1 WHERE id=?')->execute([$contractId]);
            $this->audit($pdo,'legacy_item_reconciled','produto',$itemId,$actor);
        });
    }

    public function reconcileLegacyContract(int $id,string $money,int $revision,int $actor): void
    {
        $cents=self::cents($money);
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($id,$cents,$revision,$actor): void {
            $contract=$this->find($id); $this->active($contract);
            if ($contract['valor_centavos']!==null || (int)$contract['revisao']!==$revision) { throw new DomainException('Pedido já conciliado ou alterado.'); }
            $this->assertAllocationLimits($id,$cents);
            $pdo->prepare('UPDATE pedidos SET valor_centavos=?,revisao=revisao+1 WHERE id=?')->execute([$cents,$id]);
            $this->audit($pdo,'legacy_contract_reconciled','contrato',$id,$actor);
        });
    }

    public function addSheet(int $id, string $note, int $revision, int $actor, ?int $copyId = null, ?string $key = null, bool $additive = false): int
    {
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id,$note,$revision,$actor,$copyId,$key,$additive): int {
            $contract = $this->find($id); $this->active($contract);
            $this->certificateRules()->assertCanCreate($contract['id_fornecedor'] === null ? null : (int) $contract['id_fornecedor']);
            if ((int)$contract['revisao'] !== $revision) { throw new DomainException('Pedido alterado por outra pessoa. Atualize a página.'); }
            $copy = $copyId === null ? null : $this->sheet($id,$copyId);
            if ($copy !== null && $copy['excluido_em'] !== null) { throw new DomainException('Nota excluída não pode ser duplicada.'); }
            $copiedItems = 0;
            if ($copy !== null) {
                $count = $pdo->prepare('SELECT COUNT(*) FROM pedido_produtos WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL');
                $count->execute([$id, $copy['numero_pagina']]);
                $copiedItems = (int) $count->fetchColumn();
            }
            $this->assertCapacity($id, 1, $copiedItems);
            $isAdditive=$copy === null ? $additive : (int)$copy['aditivo']===1;
            if ($isAdditive) { $this->ensureAllocation($id,0,true); }
            if ($copy !== null) {
                if ($key === null || preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new DomainException('Chave de duplicação inválida. Atualize o formulário.'); }
                $pdo->prepare('INSERT INTO modulo5_operacoes(idempotencia,tipo,recurso_id,usuario_id,criado_em) VALUES(?,?,?,?,?)')->execute([$key,'duplicar_folha',$copyId,$actor,gmdate('Y-m-d H:i:s')]);
            }
            $number = (int)$pdo->query('SELECT COALESCE(MAX(numero_pagina),0)+1 FROM pedido_paginas WHERE id_pedido=' . $id)->fetchColumn();
            $pdo->prepare('INSERT INTO pedido_paginas(id_pedido,numero_pagina,valor_pagina,valor_centavos,observacao,aditivo) VALUES(?,?,0,0,?,?)')->execute([$id,$number,$copy === null ? mb_substr(trim($note),0,2000) : $copy['observacao'],$isAdditive ? 1 : 0]);
            $sheetId = (int)$pdo->lastInsertId();
            if ($copy !== null) {
                foreach ($this->items($id) as $item) {
                    if ((int)$item['numero_pagina'] !== (int)$copy['numero_pagina'] || $item['excluido_em'] !== null) { continue; }
                    if ($item['total_centavos'] === null) { throw new DomainException('Item legado precisa de conciliação antes da cópia.'); }
                    $this->ensureAllocation($id,(int)$item['total_centavos'],$isAdditive);
                    $pdo->prepare('INSERT INTO pedido_produtos(id_pedido,numero_pagina,nome_produto,marca,unidade,quantidade,valor_unitario,valor_total,quantidade_contratada,preco_centavos,total_centavos) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$number,$item['nome_produto'],$item['marca'],$item['unidade'],$item['quantidade'],$item['valor_unitario'],$item['valor_total'],$item['quantidade_contratada'],$item['preco_centavos'],$item['total_centavos']]);
                }
                $this->recalculate($id,$number);
            }
            $pdo->prepare('UPDATE pedidos SET qtd_paginas=qtd_paginas+1,revisao=revisao+1 WHERE id=?')->execute([$id]);
            $this->audit($pdo,$copy === null ? ($isAdditive ? 'additive_sheet_created' : 'sheet_created') : 'sheet_duplicated','contrato',$id,$actor);
            return $sheetId;
        });
    }

    /** @param array<string,mixed> $data */
    public function addItem(int $contractId, int $sheetId, array $data, int $revision, int $actor): int
    {
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($contractId,$sheetId,$data,$revision,$actor): int {
            $contract=$this->find($contractId); $this->active($contract);
            $sheet=$this->sheet($contractId,$sheetId);
            if ($sheet['excluido_em'] !== null || (int)$contract['revisao'] !== $revision) { throw new DomainException('Nota indisponível ou pedido alterado. Atualize a página.'); }
            $this->assertCapacity($contractId, 0, 1);
            $name=$this->title((string)($data['nome'] ?? ''));
            $brand=mb_substr(trim((string)($data['marca'] ?? '')),0,100);
            $unit=mb_substr(trim((string)($data['unidade'] ?? '')),0,30);
            if ($unit === '' || preg_match('/[\p{C}]/u',$unit.$brand)) { throw new DomainException('Unidade ou marca inválida.'); }
            $quantity=self::integer($data['quantidade'] ?? null,1,1000000);
            $price=self::cents($data['preco'] ?? null);
            if ($price > intdiv(PHP_INT_MAX,$quantity)) { throw new DomainException('Total fora do limite.'); }
            $total=$quantity*$price; $this->ensureAllocation($contractId,$total,(int)$sheet['aditivo']===1);
            $query=$pdo->prepare('INSERT INTO pedido_produtos(id_pedido,numero_pagina,nome_produto,marca,unidade,quantidade,valor_unitario,valor_total,quantidade_contratada,preco_centavos,total_centavos) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $query->execute([$contractId,$sheet['numero_pagina'],$name,$brand,$unit,$quantity,$price/100,$total/100,$quantity,$price,$total]);
            $itemId=(int)$pdo->lastInsertId();
            $this->recalculate($contractId,(int)$sheet['numero_pagina']);
            $pdo->prepare('UPDATE pedidos SET revisao=revisao+1 WHERE id=?')->execute([$contractId]);
            $this->audit($pdo,'item_created','produto',$itemId,$actor);
            return $itemId;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateItem(int $contractId, int $itemId, array $data, int $revision, int $actor): void
    {
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($contractId,$itemId,$data,$revision,$actor): void {
            $old=$this->item($contractId,$itemId); $this->active($this->find($contractId));
            $sheet=$this->activeItemSheet($contractId,(int)$old['numero_pagina']);
            if ($old['excluido_em'] !== null || (int)$old['revisao'] !== $revision || $old['total_centavos'] === null) { throw new DomainException('Produto alterado ou legado pendente.'); }
            $name=$this->title((string)($data['nome'] ?? ''));
            $brand=mb_substr(trim((string)($data['marca'] ?? '')),0,100);
            $unit=mb_substr(trim((string)($data['unidade'] ?? '')),0,30);
            if ($unit === '' || preg_match('/[\p{C}]/u',$unit.$brand)) { throw new DomainException('Unidade ou marca inválida.'); }
            $quantity=self::integer($data['quantidade'] ?? null,1,1000000);
            $price=self::cents($data['preco'] ?? null);
            if ($price > intdiv(PHP_INT_MAX,$quantity)) { throw new DomainException('Total fora do limite.'); }
            $total=$quantity*$price;
            $this->ensureAllocation($contractId,$total-(int)$old['total_centavos'],(int)$sheet['aditivo']===1);
            if ($unit !== $old['unidade']) {
                $movement = $pdo->prepare('SELECT 1 FROM estoque_movimentos WHERE produto_id=? LIMIT 1');
                $movement->execute([$itemId]);
                if ($movement->fetchColumn()) { throw new DomainException('Unidade não pode mudar após movimentações. Cadastre outro produto para usar outra unidade.'); }
            }
            $q=$pdo->prepare('UPDATE pedido_produtos SET nome_produto=?,marca=?,unidade=?,quantidade=?,valor_unitario=?,valor_total=?,quantidade_contratada=?,preco_centavos=?,total_centavos=?,revisao=revisao+1 WHERE id=? AND revisao=?');
            $q->execute([$name,$brand,$unit,$quantity,$price/100,$total/100,$quantity,$price,$total,$itemId,$revision]);
            if ($q->rowCount()!==1) { throw new DomainException('Produto alterado por outra pessoa.'); }
            $this->recalculate($contractId,(int)$old['numero_pagina']);
            $pdo->prepare('UPDATE pedidos SET revisao=revisao+1 WHERE id=?')->execute([$contractId]);
            $this->audit($pdo,'item_updated','produto',$itemId,$actor);
        });
    }

    public function updateSheet(int $contractId,int $sheetId,string $note,int $revision,int $actor): void
    {
        if (mb_strlen($note)>2000) { throw new DomainException('Observação muito longa.'); }
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$sheetId,$note,$revision,$actor): void {
            $this->active($this->find($contractId)); $sheet=$this->sheet($contractId,$sheetId);
            if ($sheet['excluido_em']!==null || (int)$sheet['revisao']!==$revision) { throw new DomainException('Nota alterada ou indisponível.'); }
            $pdo->prepare('UPDATE pedido_paginas SET observacao=?,revisao=revisao+1 WHERE id=?')->execute([trim($note),$sheetId]);
            $this->audit($pdo,'sheet_updated','folha',$sheetId,$actor);
        });
    }

    public function billSheet(int $contractId,int $sheetId,string $date,string $reason,int $revision,int $actor,bool $admin): void
    {
        $parsed=$date==='' ? null : DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if ($date!=='' && ($parsed===false || $parsed->format('Y-m-d')!==$date)) { throw new DomainException('Data de faturamento inválida.'); }
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$sheetId,$date,$reason,$revision,$actor,$admin): void {
            $this->active($this->find($contractId)); $sheet=$this->sheet($contractId,$sheetId);
            if ($sheet['excluido_em']!==null || (int)$sheet['revisao']!==$revision) { throw new DomainException('Nota alterada ou indisponível.'); }
            if ($sheet['data_faturamento']!==null && (!$admin || mb_strlen(trim($reason))<3)) { throw new DomainException('Correção de faturamento exige administrador e motivo.'); }
            $pdo->prepare('UPDATE pedido_paginas SET data_faturamento=?,revisao=revisao+1 WHERE id=?')->execute([$date==='' ? null : $date,$sheetId]);
            AuditLogger::recordRequired($pdo,'contrato.sheet_billed',AuditLogger::SUCCESS,$actor,null,'Anterior: '.($sheet['data_faturamento'] ?? 'sem data').'; nova: '.($date ?: 'sem data').'; motivo: '.mb_substr(trim($reason),0,180),'folha',$sheetId);
        });
    }

    public function initializeStock(int $contractId,int $itemId,int $quantity,int $revision,int $actor,string $key,bool $admin=false): void
    {
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$itemId,$quantity,$revision,$actor,$key,$admin): void {
            $item=$this->item($contractId,$itemId);
            $this->writeStockConfiguration($pdo,$contractId,$itemId,
                $item['estoque_minimo']===null ? null : (int)$item['estoque_minimo'],
                $item['estoque_maximo']===null ? null : (int)$item['estoque_maximo'],
                $revision,$actor,$quantity,$key,$admin);
        });
    }

    public function configureStock(int $contractId, int $itemId, ?int $minimum, ?int $maximum, int $revision, int $actor, ?int $opening = null, ?string $key = null, bool $admin = false): void
    {
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($contractId,$itemId,$minimum,$maximum,$revision,$actor,$opening,$key,$admin): void {
            $this->writeStockConfiguration($pdo,$contractId,$itemId,$minimum,$maximum,$revision,$actor,$opening,$key,$admin);
        });
    }

    private function writeStockConfiguration(PDO $pdo,int $contractId,int $itemId,?int $minimum,?int $maximum,int $revision,int $actor,?int $opening,?string $key,bool $admin): void
    {
        $item=$this->item($contractId,$itemId); $this->active($this->find($contractId));
        $this->activeItemSheet($contractId,(int)$item['numero_pagina']);
        if ($item['excluido_em'] !== null || (int)$item['revisao'] !== $revision) { throw new DomainException('Produto alterado por outra pessoa. Atualize a página.'); }
        $this->validateStockLimits($minimum,$maximum);
        $balance=$this->balance($itemId);
        if ($maximum !== null && $balance > $maximum) { throw new DomainException('Máximo inferior ao saldo atual.'); }
        if ($opening !== null && (int)$item['estoque_inicializado'] === 1) { throw new DomainException('Estoque já inicializado.'); }
        if ($opening !== null && !$admin) {
            $q=$pdo->prepare("SELECT 1 FROM modulo5_valores_legados WHERE tabela='pedido_produtos' AND registro_id=? LIMIT 1");
            $q->execute([$itemId]);
            if ($q->fetchColumn()) { throw new DomainException('Abertura de item legado exige administrador.'); }
        }
        if ($opening !== null && ($opening < 0 || $opening > 1000000000 || ($maximum !== null && $opening > $maximum))) { throw new DomainException('Quantidade inicial inválida.'); }
        if ($opening !== null) {
            if ($key === null || preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new DomainException('Chave de abertura inválida. Atualize o formulário.'); }
            $pdo->prepare('INSERT INTO modulo5_operacoes(idempotencia,tipo,recurso_id,usuario_id,criado_em) VALUES(?,?,?,?,?)')->execute([$key,'abertura_estoque',$itemId,$actor,gmdate('Y-m-d H:i:s')]);
        }
        $pdo->prepare('UPDATE pedido_produtos SET estoque_minimo=?,estoque_maximo=?,estoque_inicializado=?,revisao=revisao+1 WHERE id=?')
            ->execute([$minimum,$maximum,$opening === null ? (int)$item['estoque_inicializado'] : 1,$itemId]);
        if ($opening !== null && $opening > 0) {
            $this->insertMovement($pdo,$itemId,'abertura',$opening,'Quantidade inicial conferida',$actor,$key ?? '',null);
        }
        $this->audit($pdo,$opening === null ? 'stock_configured' : 'stock_opened','produto',$itemId,$actor);
    }

    public function confirmOldZeroOpening(int $contractId,int $itemId,int $revision,int $actor,string $reason,string $key): void
    {
        $item=$this->item($contractId,$itemId);
        $this->recoverOldOpening($contractId,$itemId,0,(string)$item['unidade'],
            $item['estoque_minimo']===null ? null : (int)$item['estoque_minimo'],
            $item['estoque_maximo']===null ? null : (int)$item['estoque_maximo'],$revision,$actor,$reason,$key,true);
    }

    public function recoverOldOpening(int $contractId,int $itemId,int $physicalQuantity,string $unit,?int $minimum,?int $maximum,int $revision,int $actor,string $reason,string $key,bool $physicalCountConfirmed=false): void
    {
        $reason=trim($reason);
        if (!$physicalCountConfirmed) { throw new DomainException('Confirme expressamente a contagem física atual e a unidade.'); }
        if (!mb_check_encoding($reason,'UTF-8') || mb_strlen($reason)<10 || mb_strlen($reason)>180 || preg_match('/[\p{C}]/u',$reason)) {
            throw new DomainException('Descreva a conferência e o documento de apoio em 10 a 180 caracteres.');
        }
        if (!mb_check_encoding($unit,'UTF-8') || $unit==='' || mb_strlen($unit)>30 || preg_match('/[\p{C}]/u',$unit)) { throw new DomainException('Unidade da contagem inválida.'); }
        $this->validateStockLimits($minimum,$maximum);
        if ($physicalQuantity<0 || $physicalQuantity>1000000000 || ($maximum!==null && $physicalQuantity>$maximum)) { throw new DomainException('Contagem física fora dos limites do estoque.'); }
        if (preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new DomainException('Chave de confirmação inválida.'); }
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$itemId,$physicalQuantity,$unit,$minimum,$maximum,$revision,$actor,$reason,$key): void {
            $q=$pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND tipo='administrador' AND ativo=1"); $q->execute([$actor]);
            if (!$q->fetchColumn()) { throw new DomainException('Recuperação de abertura antiga exige administrador ativo.'); }
            $item=$this->item($contractId,$itemId); $this->active($this->find($contractId));
            $q=$pdo->prepare('SELECT 1 FROM pedido_paginas WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL');
            $q->execute([$contractId,$item['numero_pagina']]);
            if (!$q->fetchColumn()) { throw new DomainException('Produto sem nota ativa vinculada ao pedido.'); }
            if ($item['excluido_em']!==null || (int)$item['revisao']!==$revision || (int)$item['estoque_inicializado']!==1 || $this->balance($itemId)!==0) {
                throw new DomainException('Estoque alterado. Atualize a página antes de confirmar.');
            }
            if ($unit!==$item['unidade']) { throw new DomainException('Confirme a contagem na unidade atual do produto.'); }
            $q=$pdo->prepare('SELECT 1 FROM estoque_movimentos WHERE produto_id=? LIMIT 1'); $q->execute([$itemId]);
            if ($q->fetchColumn()) { throw new DomainException('Há movimentos; esta recuperação não se aplica.'); }
            $q=$pdo->prepare("SELECT 1 FROM modulo5_operacoes WHERE recurso_id=? AND tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida') LIMIT 1"); $q->execute([$itemId]);
            if ($q->fetchColumn()) { throw new DomainException('Abertura já confirmada.'); }
            $q=$pdo->prepare('SELECT 1 FROM modulo5_operacoes WHERE idempotencia=? UNION SELECT 1 FROM estoque_movimentos WHERE idempotencia=? LIMIT 1');
            $q->execute([$key,$key]);
            if ($q->fetchColumn()) { throw new DomainException('Operação já enviada. Atualize o formulário.'); }
            $type=$physicalQuantity===0 ? 'abertura_zero_conferida' : 'abertura_legada_conferida';
            $pdo->prepare('INSERT INTO modulo5_operacoes(idempotencia,tipo,recurso_id,usuario_id,criado_em) VALUES(?,?,?,?,?)')
                ->execute([$key,$type,$itemId,$actor,gmdate('Y-m-d H:i:s')]);
            if ($physicalQuantity>0) {
                $this->insertMovement($pdo,$itemId,'abertura',$physicalQuantity,'Conferência física atual; documento: '.$reason,$actor,$key,null);
            }
            $q=$pdo->prepare('UPDATE pedido_produtos SET estoque_minimo=?,estoque_maximo=?,revisao=revisao+1 WHERE id=? AND revisao=?');
            $q->execute([$minimum,$maximum,$itemId,$revision]);
            if ($q->rowCount()!==1) { throw new DomainException('Estoque alterado por outra pessoa. Atualize a página.'); }
            $description='Contagem atual: '.$physicalQuantity.' '.$unit.'; limites: '.($minimum===null ? 'sem mínimo' : $minimum).' a '.($maximum===null ? 'sem máximo' : $maximum).'; documento: '.$reason;
            AuditLogger::recordRequired($pdo,$physicalQuantity===0 ? 'contrato.old_zero_opening_confirmed' : 'contrato.old_opening_recovered',
                AuditLogger::SUCCESS,$actor,null,$description,'produto',$itemId);
        });
    }

    public function move(int $contractId, int $itemId, string $type, int $quantity, string $reason, string $key, int $actor, int $revision, ?int $originalId = null): void
    {
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($contractId,$itemId,$type,$quantity,$reason,$key,$actor,$revision,$originalId): void {
            $item=$this->item($contractId,$itemId); $this->active($this->find($contractId));
            $this->activeItemSheet($contractId,(int)$item['numero_pagina']);
            if ($item['excluido_em'] !== null || (int)$item['estoque_inicializado'] !== 1) { throw new DomainException('Estoque não inicializado ou produto indisponível.'); }
            $q=$pdo->prepare("SELECT 1 FROM estoque_movimentos WHERE produto_id=? UNION SELECT 1 FROM modulo5_operacoes WHERE recurso_id=? AND tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida') LIMIT 1");
            $q->execute([$itemId,$itemId]);
            if (!$q->fetchColumn()) { throw new DomainException('Abertura antiga sem comprovação. Peça a conferência administrativa da contagem física atual.'); }
            if ((int)$item['revisao']!==$revision) { throw new DomainException('Estoque alterado por outra pessoa. Atualize a página.'); }
            if (!in_array($type,['entrada','saida','estorno'],true) || $quantity < 1 || $quantity > 1000000000) { throw new DomainException('Movimentação inválida.'); }
            $reason=trim($reason);
            if (mb_strlen($reason)<3 || mb_strlen($reason)>300 || preg_match('/[\p{C}]/u',$reason)) { throw new DomainException('Informe um motivo de 3 a 300 caracteres.'); }
            if ($type === 'estorno') {
                if ($originalId === null || $quantity !== 1) { throw new DomainException('Movimento original obrigatório.'); }
                $q=$pdo->prepare('SELECT * FROM estoque_movimentos WHERE id=? AND produto_id=? AND tipo IN (\'entrada\',\'saida\',\'abertura\')');
                $q->execute([$originalId,$itemId]); $original=$q->fetch();
                if (!$original) { throw new DomainException('Movimento original inválido.'); }
                $quantity=-(int)$original['quantidade'];
            } elseif ($originalId !== null) { throw new DomainException('Referência indevida.'); }
            else { $quantity=$type === 'saida' ? -$quantity : $quantity; }
            $next=$this->balance($itemId)+$quantity;
            if ($next<0 || $next>1000000000 || ($item['estoque_maximo']!==null && $next>(int)$item['estoque_maximo'])) { throw new DomainException('Saldo insuficiente ou acima do máximo.'); }
            $this->insertMovement($pdo,$itemId,$type,$quantity,$reason,$actor,$key,$originalId);
            $pdo->prepare('UPDATE pedido_produtos SET revisao=revisao+1 WHERE id=?')->execute([$itemId]);
            $this->audit($pdo,'stock_'.$type,'produto',$itemId,$actor);
        });
    }

    /** @param array<mixed> $lines */
    public function receiveSheet(int $contractId,int $sheetId,array $lines,string $key,int $actor): void
    {
        if ($lines===[]) { throw new DomainException('Informe os produtos para receber.'); }
        $normalized=[];
        $hasReceipt=false;
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new DomainException('Produto de recebimento inválido.'); }
            $itemId=self::integer($line['id'] ?? null,1);
            if (isset($normalized[$itemId])) { throw new DomainException('Produto repetido no recebimento.'); }
            $quantity=self::integer($line['quantity'] ?? null);
            $normalized[$itemId]=['id'=>$itemId,'revision'=>self::integer($line['revision'] ?? null,1),'quantity'=>$quantity];
            $hasReceipt=$hasReceipt || $quantity>0;
        }
        if (!$hasReceipt) { throw new DomainException('Informe a quantidade recebida de pelo menos um produto.'); }
        ksort($normalized);
        SqliteTransaction::immediate(self::$pdo,function(PDO $pdo) use ($contractId,$sheetId,$normalized,$key,$actor): void {
            RequestReceipt::run($pdo,$actor,'stock.sheet_receive',$key,[$contractId,$sheetId,array_values($normalized)],function() use ($pdo,$contractId,$sheetId,$normalized,$key,$actor): int {
                $this->active($this->find($contractId));
                $sheet=$this->sheet($contractId,$sheetId);
                if ($sheet['excluido_em']!==null) { throw new DomainException('Nota indisponível para recebimento.'); }
                foreach ($normalized as $line) {
                    $item=$this->item($contractId,$line['id']);
                    if ($item['excluido_em']!==null || (int)$item['numero_pagina']!==(int)$sheet['numero_pagina']) {
                        throw new DomainException('Produto não pertence à nota ativa.');
                    }
                    if ((int)$item['revisao']!==$line['revision']) { throw new DomainException('Estoque alterado por outra pessoa. Confira novamente o recebimento.'); }
                    if ($line['quantity']===0) { continue; }
                    if ((int)$item['estoque_inicializado']!==1) { throw new DomainException('Confirme a quantidade inicial do produto em Estoque antes de registrar o recebimento.'); }
                    $q=$pdo->prepare("SELECT 1 FROM estoque_movimentos WHERE produto_id=? UNION SELECT 1 FROM modulo5_operacoes WHERE recurso_id=? AND tipo IN ('abertura_estoque','abertura_zero_conferida','abertura_legada_conferida') LIMIT 1");
                    $q->execute([$line['id'],$line['id']]);
                    if (!$q->fetchColumn()) { throw new DomainException('Quantidade inicial antiga sem comprovação. Solicite a conferência administrativa.'); }
                    $next=$this->balance($line['id'])+$line['quantity'];
                    if ($next>1000000000 || ($item['estoque_maximo']!==null && $next>(int)$item['estoque_maximo'])) { throw new DomainException('O recebimento ultrapassa o máximo permitido para o produto.'); }
                    $movementKey=substr(hash('sha256','sheet-receive:'.$actor.':'.$key.':'.$line['id']),0,32);
                    $this->insertMovement($pdo,$line['id'],'entrada',$line['quantity'],'Recebimento da nota '.$sheet['numero_pagina'].' do pedido '.$contractId,$actor,$movementKey,null);
                    $pdo->prepare('UPDATE pedido_produtos SET revisao=revisao+1 WHERE id=?')->execute([$line['id']]);
                    $this->audit($pdo,'stock_entrada','produto',$line['id'],$actor);
                }
                $this->audit($pdo,'sheet_received','folha',$sheetId,$actor);
                return $sheetId;
            });
        });
    }

    public function delete(int $contractId, ?int $sheetId, ?int $itemId, int $revision, int $actor): void
    {
        if ($sheetId !== null && $itemId !== null) { throw new DomainException('Selecione apenas um recurso para excluir.'); }
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($contractId,$sheetId,$itemId,$revision,$actor): void {
            $contract=$this->find($contractId); $this->active($contract);
            if ((int)$contract['revisao']!==$revision) { throw new DomainException('Pedido alterado. Atualize a página.'); }
            $where=$itemId !== null ? 'id=? AND id_pedido=?' : ($sheetId !== null ? 'id=? AND id_pedido=?' : 'id=?');
            $table=$itemId !== null ? 'pedido_produtos' : ($sheetId !== null ? 'pedido_paginas' : 'pedidos');
            if ($itemId !== null) { $this->item($contractId,$itemId); }
            if ($sheetId !== null) { $this->sheet($contractId,$sheetId); }
            foreach ($this->items($contractId) as $item) {
                if ($item['excluido_em'] !== null) { continue; }
                if ($itemId !== null && (int)$item['id'] !== $itemId) { continue; }
                if ($sheetId !== null && (int)$item['numero_pagina'] !== (int)$this->sheet($contractId,$sheetId)['numero_pagina']) { continue; }
                if ((int)$item['saldo'] !== 0) { throw new DomainException('Há saldo físico. Registre a destinação antes da exclusão.'); }
            }
            $pdo->prepare('UPDATE '.$table.' SET excluido_em=?,excluido_por=? WHERE '.$where.' AND excluido_em IS NULL')->execute($itemId !== null ? [gmdate('Y-m-d H:i:s'),$actor,$itemId,$contractId] : ($sheetId !== null ? [gmdate('Y-m-d H:i:s'),$actor,$sheetId,$contractId] : [gmdate('Y-m-d H:i:s'),$actor,$contractId]));
            if ($itemId !== null) { $item=$this->item($contractId,$itemId); $this->recalculate($contractId,(int)$item['numero_pagina']); }
            if ($sheetId !== null && $itemId === null) {
                $sheet=$this->sheet($contractId,$sheetId);
                $pdo->prepare('UPDATE pedido_produtos SET excluido_em=?,excluido_por=? WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL')->execute([gmdate('Y-m-d H:i:s'),$actor,$contractId,$sheet['numero_pagina']]);
            }
            $pdo->prepare('UPDATE pedidos SET revisao=revisao+1 WHERE id=?')->execute([$contractId]);
            $this->audit($pdo,'deleted',$itemId !== null ? 'produto' : ($sheetId !== null ? 'folha' : 'contrato'),$itemId ?? $sheetId ?? $contractId,$actor);
        });
    }

    private function title(string $value): string
    {
        $value=trim($value);
        if (mb_strlen($value)<2 || mb_strlen($value)>150 || preg_match('/[\p{C}]/u',$value)) { throw new DomainException('Informe título ou nome de 2 a 150 caracteres.'); }
        return $value;
    }

    private function supplier(string $raw, ?int $old): ?int
    {
        if ($raw === '') { return null; }
        $id=self::integer($raw,1);
        $q=self::$pdo->prepare('SELECT ativo FROM lista_fornecedores WHERE id=?'); $q->execute([$id]);
        $active=$q->fetchColumn();
        if ($active === false || ((int)$active !== 1 && $old !== $id)) { throw new DomainException('Selecione fornecedor ativo.'); }
        return $id;
    }

    /** @param array<string,mixed> $contract */
    private function active(array $contract): void
    {
        if ($contract['excluido_em'] !== null) { throw new DomainException('Pedido excluído. Histórico disponível somente para consulta.'); }
    }

    /** @return array<string,mixed> */
    public function sheet(int $contractId,int $sheetId): array
    {
        $q=self::$pdo->prepare('SELECT * FROM pedido_paginas WHERE id=? AND id_pedido=?'); $q->execute([$sheetId,$contractId]);
        return $q->fetch() ?: throw new DomainException('Nota não pertence ao pedido.');
    }

    /** @return array<string,mixed> */
    private function item(int $contractId,int $itemId): array
    {
        $q=self::$pdo->prepare('SELECT * FROM pedido_produtos WHERE id=? AND id_pedido=?'); $q->execute([$itemId,$contractId]);
        return $q->fetch() ?: throw new DomainException('Produto não pertence ao pedido.');
    }

    /** @return array<string,mixed> */
    private function activeItemSheet(int $contractId,int $number): array
    {
        $q=self::$pdo->prepare('SELECT * FROM pedido_paginas WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL');
        $q->execute([$contractId,$number]);
        return $q->fetch() ?: throw new DomainException('Produto sem nota ativa vinculada ao pedido.');
    }

    /** @return array{0:array{total:int,pending:bool},1:array{total:int,pending:bool}} */
    private function allocationGroups(int $contractId): array
    {
        $groups=[['total'=>0,'pending'=>false],['total'=>0,'pending'=>false]];
        $q=self::$pdo->prepare('SELECT COALESCE(pg.aditivo,0) AS aditivo, COALESCE(SUM(i.total_centavos),0) AS total,
            SUM(CASE WHEN i.total_centavos IS NULL OR pg.id IS NULL THEN 1 ELSE 0 END) AS pending
            FROM pedido_produtos i LEFT JOIN pedido_paginas pg ON pg.id_pedido=i.id_pedido AND pg.numero_pagina=i.numero_pagina
            WHERE i.id_pedido=? AND i.excluido_em IS NULL AND (pg.id IS NULL OR pg.excluido_em IS NULL)
            GROUP BY COALESCE(pg.aditivo,0)');
        $q->execute([$contractId]);
        foreach ($q->fetchAll() as $row) {
            $index=(int)$row['aditivo']===1 ? 1 : 0;
            $groups[$index]=['total'=>(int)$row['total'],'pending'=>(int)$row['pending']>0];
        }
        return $groups;
    }

    private function assertAllocationLimits(int $contractId,int $limit,bool $requireComplete=true): void
    {
        $groups=$this->allocationGroups($contractId);
        if ($requireComplete && ($groups[0]['pending'] || $groups[1]['pending'])) {
            throw new DomainException('Valores legados precisam de conciliação antes desta alteração.');
        }
        $this->assertGroupLimits($groups[0]['total'],$groups[1]['total'],$limit);
    }

    private function assertGroupLimits(int $original,int $additive,int $limit): void
    {
        if ($original>$limit) { throw new DomainException('Itens das notas comuns ultrapassam o teto original do pedido.'); }
        if ($additive>intdiv($limit,4)) { throw new DomainException('Aditivos ultrapassam o limite total de 25% do teto original do pedido.'); }
    }

    private function ensureAllocation(int $contractId,int $extra,bool $additive): void
    {
        $contract=$this->find($contractId); $groups=$this->allocationGroups($contractId);
        if ($groups[0]['pending'] || $groups[1]['pending'] || $contract['valor_centavos']===null) {
            throw new DomainException('Valores legados precisam de conciliação antes de incluir itens.');
        }
        $groups[$additive ? 1 : 0]['total']+=$extra;
        $this->assertGroupLimits($groups[0]['total'],$groups[1]['total'],(int)$contract['valor_centavos']);
    }

    private function recalculate(int $contractId,int $number): void
    {
        $q=self::$pdo->prepare('SELECT COUNT(*) AS n,COUNT(total_centavos) AS converted,COALESCE(SUM(total_centavos),0) AS total FROM pedido_produtos WHERE id_pedido=? AND numero_pagina=? AND excluido_em IS NULL');
        $q->execute([$contractId,$number]); $row=$q->fetch();
        if ((int)$row['n']!==(int)$row['converted']) { throw new DomainException('Nota contém valores legados pendentes.'); }
        self::$pdo->prepare('UPDATE pedido_paginas SET valor_pagina=CASE WHEN valor_centavos IS NULL THEN valor_pagina ELSE ? END,valor_centavos=?,revisao=revisao+1 WHERE id_pedido=? AND numero_pagina=?')->execute([(int)$row['total']/100,$row['total'],$contractId,$number]);
    }

    private function balance(int $itemId): int
    {
        $q=self::$pdo->prepare('SELECT COALESCE(SUM(quantidade),0) FROM estoque_movimentos WHERE produto_id=?'); $q->execute([$itemId]);
        return (int)$q->fetchColumn();
    }

    private function validateStockLimits(?int $minimum,?int $maximum): void
    {
        if (($minimum!==null && ($minimum<0 || $minimum>1000000000))
            || ($maximum!==null && ($maximum<0 || $maximum>1000000000))
            || ($minimum!==null && $maximum!==null && $maximum<$minimum)) {
            throw new DomainException('Limites de estoque incoerentes.');
        }
    }

    private function insertMovement(PDO $pdo,int $itemId,string $type,int $quantity,string $reason,int $actor,string $key,?int $original): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new DomainException('Chave de operação inválida. Atualize o formulário.'); }
        $pdo->prepare('INSERT INTO estoque_movimentos(produto_id,tipo,quantidade,motivo,usuario_id,criado_em,idempotencia,movimento_original_id) VALUES(?,?,?,?,?,?,?,?)')->execute([$itemId,$type,$quantity,$reason,$actor,gmdate('Y-m-d H:i:s'),$key,$original]);
    }

    private function audit(PDO $pdo,string $action,string $resource,int $id,int $actor): void
    {
        AuditLogger::recordRequired($pdo,'contrato.'.$action,AuditLogger::SUCCESS,$actor,null,'Módulo 5: '.$action,$resource,$id);
    }

    /** Includes excluded history: repeated create/delete operations cannot evade the quota. */
    private function assertCapacity(int $contractId, int $extraSheets = 0, int $extraItems = 0): void
    {
        $query = self::$pdo->prepare('SELECT (SELECT COUNT(*) FROM pedido_paginas WHERE id_pedido=?) AS sheets, (SELECT COUNT(*) FROM pedido_produtos WHERE id_pedido=?) AS items');
        $query->execute([$contractId, $contractId]);
        $counts = $query->fetch();
        if ((int) $counts['sheets'] + $extraSheets > self::MAX_SHEETS || (int) $counts['items'] + $extraItems > self::MAX_ITEMS) {
            throw new DomainException('O pedido aceita até 20 notas e 100 produtos no total, incluindo o histórico excluído. Use outro pedido para novos lançamentos. Pedidos antigos acima desse limite precisam de revisão técnica antes de abrir o conjunto completo.');
        }
    }
}
