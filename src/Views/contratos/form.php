<?php
$draft=$draft ?? [];
$old=static fn(mixed $value): string => is_string($value) ? $value : '';
$renderUnitSelect = require __DIR__.'/unit-select.php';
$formSheets=$record===null && isset($draft['folhas']) && is_array($draft['folhas']) && $draft['folhas']!==[] ? $draft['folhas'] : [['observacao'=>'','produtos'=>[['nome'=>'','marca'=>'','unidade'=>'UN','quantidade'=>'','preco'=>'']]]];
?>
<section class="relatorio contract-form-panel">
    <?php if ($record!==null && $draft!==[]): ?><div class="warning-message" role="status"><strong>A tentativa anterior não foi salva.</strong> Os dados atuais do contrato estão no formulário abaixo. Compare antes de reenviar.<br>Texto digitado: <?= e($old($draft['titulo'] ?? '')) ?> · Valor: <?= e($old($draft['valor'] ?? '')) ?> · Fornecedor ID: <?= e($old($draft['fornecedor'] ?? '')) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url($record===null ? 'contrato/criar' : 'contrato/editar/'.$record['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($record!==null): ?><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>"><?php endif; ?>
        <div class="mb-3"><label for="titulo">Título do Pedido / Fornecedor</label><input class="form-control" id="titulo" name="titulo" maxlength="150" required placeholder="Ex: Contrato de Papelaria 2026" value="<?= e($record['titulo'] ?? $old($draft['titulo'] ?? '')) ?>"></div>
        <div class="mb-3"><label for="valor">Valor Total do Pedido (R$)</label><input class="form-control" id="valor" name="valor" inputmode="decimal" required placeholder="Ex: 50000,00" value="<?= e($record===null ? $old($draft['valor'] ?? '') : ($record['valor_centavos']===null ? '' : number_format((int)$record['valor_centavos']/100,2,',',''))) ?>"></div>
        <div class="mb-3"><label for="fornecedor">Fornecedor</label><select class="form-select" id="fornecedor" name="fornecedor"><option value="">Não informado</option><?php foreach ($suppliers as $supplier): if (!(int)$supplier['ativo'] && ($record===null || (int)$record['id_fornecedor']!==(int)$supplier['id'])) { continue; } ?><option value="<?= (int)$supplier['id'] ?>" <?= ($record!==null ? (string)$record['id_fornecedor'] : $old($draft['fornecedor'] ?? ''))===(string)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['nome']) ?><?= (int)$supplier['ativo']===0 ? ' (inativo; vínculo histórico)' : '' ?></option><?php endforeach; ?></select><small>Fornecedores são geridos em Certidões e Fornecedores.</small></div>
        <?php if ($record===null): ?>
        <div data-contract-builder>
            <h3>Produtos do Pedido</h3><p class="contract-builder-hint">Adicione os produtos de cada nota. O saldo físico começa pendente até a abertura conferida no estoque.</p>
            <div data-contract-sheets><?php foreach ($formSheets as $sheetIndex=>$sheet): if (!is_array($sheet)) { continue; } $products=isset($sheet['produtos']) && is_array($sheet['produtos']) && $sheet['produtos']!==[] ? $sheet['produtos'] : [['nome'=>'','marca'=>'','unidade'=>'UN','quantidade'=>'','preco'=>'']]; ?>
            <section class="modulo5-builder-sheet" data-contract-sheet><div class="section-head"><h4>Nota <span data-sheet-number><?= (int)$sheetIndex+1 ?></span></h4><label class="contract-builder-observation">Observações da nota<textarea class="form-control" name="folhas[<?= (int)$sheetIndex ?>][observacao]" maxlength="2000" rows="1"><?= e($old($sheet['observacao'] ?? '')) ?></textarea></label><button class="btn btn-outline-danger btn-sm" type="button" data-remove-sheet>Remover nota</button></div>
                <div data-contract-products><?php foreach ($products as $productIndex=>$product): if (!is_array($product)) { continue; } ?><div class="modulo5-builder-product" data-contract-product>
                    <label><span class="visually-hidden">Produto</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][nome]" maxlength="150" required placeholder="Nome do Produto" value="<?= e($old($product['nome'] ?? '')) ?>"></label>
                    <label><span class="visually-hidden">Marca</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][marca]" maxlength="100" placeholder="Marca" value="<?= e($old($product['marca'] ?? '')) ?>"></label>
                    <label><span class="visually-hidden">Unidade</span><?php $renderUnitSelect('folhas['.(int)$sheetIndex.'][produtos]['.(int)$productIndex.'][unidade]', $old($product['unidade'] ?? 'UN')); ?></label>
                    <label><span class="visually-hidden">Quantidade contratada</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][quantidade]" type="number" min="1" max="1000000" required placeholder="Qtd" title="Quantidade contratada; o estoque físico é conferido separadamente" value="<?= e($old($product['quantidade'] ?? '')) ?>"></label>
                    <label><span class="visually-hidden">Valor unitário R$</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][preco]" inputmode="decimal" required placeholder="Valor Unit (R$)" value="<?= e($old($product['preco'] ?? '')) ?>"></label>
                    <button class="btn btn-outline-danger btn-sm" type="button" data-remove-product aria-label="Remover produto">X</button>
                </div><?php endforeach; ?></div><button class="btn btn-secondary" type="button" data-add-product>+ Adicionar Outro Produto</button>
            </section><?php endforeach; ?></div><button class="btn btn-outline-primary" type="button" data-add-sheet>＋ Adicionar outra nota</button>
            <div class="modulo5-budget" role="status"><p>Soma dos Produtos: <strong data-items-total>R$ 0,00</strong></p><p>Saldo (Orçamento - Produtos): <strong data-budget-left>R$ 0,00</strong></p></div>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">💾 Salvar Pedido</button><a class="btn btn-secondary" href="<?= e(url('contrato')) ?>">Cancelar</a>
    </form>
</section>
