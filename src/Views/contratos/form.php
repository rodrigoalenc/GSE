<?php
$draft=$draft ?? [];
$old=static fn(mixed $value): string => is_string($value) ? $value : '';
$formSheets=$record===null && isset($draft['folhas']) && is_array($draft['folhas']) && $draft['folhas']!==[] ? $draft['folhas'] : [['observacao'=>'','produtos'=>[['nome'=>'','marca'=>'','unidade'=>'un','quantidade'=>'','preco'=>'']]]];
?>
<section class="relatorio contract-form-panel">
    <p><a href="<?= e(url($record===null ? 'contrato' : 'contrato/detalhes/'.$record['id'])) ?>">Voltar</a></p>
    <?php if ($record!==null && $draft!==[]): ?><div class="warning-message" role="status"><strong>A tentativa anterior não foi salva.</strong> Os dados atuais do contrato estão no formulário abaixo. Compare antes de reenviar.<br>Texto digitado: <?= e($old($draft['titulo'] ?? '')) ?> · Valor: <?= e($old($draft['valor'] ?? '')) ?> · Fornecedor ID: <?= e($old($draft['fornecedor'] ?? '')) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url($record===null ? 'contrato/criar' : 'contrato/editar/'.$record['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($record!==null): ?><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>"><?php endif; ?>
        <div class="mb-3"><label for="titulo">Título do Pedido / Fornecedor</label><input class="form-control" id="titulo" name="titulo" maxlength="150" required placeholder="Ex: Contrato de Papelaria 2026" value="<?= e($record['titulo'] ?? $old($draft['titulo'] ?? '')) ?>"></div>
        <div class="mb-3"><label for="valor">Valor Total do Pedido (R$)</label><input class="form-control" id="valor" name="valor" inputmode="decimal" required placeholder="Ex: 50000,00" value="<?= e($record===null ? $old($draft['valor'] ?? '') : ($record['valor_centavos']===null ? '' : number_format((int)$record['valor_centavos']/100,2,',',''))) ?>"></div>
        <div class="mb-3"><label for="fornecedor">Fornecedor</label><select class="form-select" id="fornecedor" name="fornecedor"><option value="">Não informado</option><?php foreach ($suppliers as $supplier): if (!(int)$supplier['ativo'] && ($record===null || (int)$record['id_fornecedor']!==(int)$supplier['id'])) { continue; } ?><option value="<?= (int)$supplier['id'] ?>" <?= ($record!==null ? (string)$record['id_fornecedor'] : $old($draft['fornecedor'] ?? ''))===(string)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['nome']) ?><?= (int)$supplier['ativo']===0 ? ' (inativo; vínculo histórico)' : '' ?></option><?php endforeach; ?></select><small>Fornecedores são geridos em Certidões e Fornecedores.</small></div>
        <?php if ($record===null): ?>
        <div data-contract-builder>
            <h3>Notas e produtos do pedido</h3><p>Adicione os produtos de cada nota. O saldo físico começa pendente até a abertura conferida no estoque.</p>
            <div data-contract-sheets><?php foreach ($formSheets as $sheetIndex=>$sheet): if (!is_array($sheet)) { continue; } $products=isset($sheet['produtos']) && is_array($sheet['produtos']) && $sheet['produtos']!==[] ? $sheet['produtos'] : [['nome'=>'','marca'=>'','unidade'=>'un','quantidade'=>'','preco'=>'']]; ?>
            <section class="modulo5-builder-sheet" data-contract-sheet><div class="section-head"><h4>Nota <span data-sheet-number><?= (int)$sheetIndex+1 ?></span></h4><button class="btn btn-outline-danger btn-sm" type="button" data-remove-sheet>Remover nota</button></div>
                <label>Observações da folha<textarea class="form-control" name="folhas[<?= (int)$sheetIndex ?>][observacao]" maxlength="2000"><?= e($old($sheet['observacao'] ?? '')) ?></textarea></label>
                <div data-contract-products><?php foreach ($products as $productIndex=>$product): if (!is_array($product)) { continue; } ?><div class="modulo5-builder-product" data-contract-product>
                    <label>Produto<input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][nome]" maxlength="150" required value="<?= e($old($product['nome'] ?? '')) ?>"></label>
                    <label>Marca<input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][marca]" maxlength="100" value="<?= e($old($product['marca'] ?? '')) ?>"></label>
                    <label>Unidade<input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][unidade]" maxlength="30" required value="<?= e($old($product['unidade'] ?? 'un')) ?>"></label>
                    <label>Quantidade contratada<input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][quantidade]" type="number" min="1" max="1000000" required value="<?= e($old($product['quantidade'] ?? '')) ?>"></label>
                    <label>Valor unitário R$<input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][preco]" inputmode="decimal" required value="<?= e($old($product['preco'] ?? '')) ?>"></label>
                    <button class="btn btn-outline-danger btn-sm" type="button" data-remove-product>Remover produto</button>
                </div><?php endforeach; ?></div><button class="btn btn-outline-primary btn-sm" type="button" data-add-product>Adicionar produto</button>
            </section><?php endforeach; ?></div><button class="btn btn-outline-primary" type="button" data-add-sheet>＋ Adicionar outra nota</button>
            <p class="modulo5-budget" role="status">Soma dos produtos: <strong data-items-total>R$ 0,00</strong> · Saldo do contrato: <strong data-budget-left>R$ 0,00</strong>. Valores conferidos novamente no servidor ao salvar.</p>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Salvar Pedido</button><a class="btn btn-secondary" href="<?= e(url('contrato')) ?>">Cancelar</a>
    </form>
</section>
