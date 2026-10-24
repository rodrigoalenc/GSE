<?php
$draft=$draft ?? [];
$old=static fn(mixed $value): string => is_string($value) ? $value : '';
$renderUnitSelect = require __DIR__.'/unit-select.php';
$formSheets=$record===null && isset($draft['folhas']) && is_array($draft['folhas']) && $draft['folhas']!==[] ? $draft['folhas'] : [['observacao'=>'','produtos'=>[['nome'=>'','marca'=>'','unidade'=>'UN','quantidade'=>'','preco'=>'']]]];
?>
<?php $dynamicCertificateGuard = $record === null; require __DIR__ . '/certidoes-alerta.php'; ?>
<?php if ($record !== null): ?><div class="contract-edit-back"><a class="btn-secondary" href="<?= e(url('contrato/detalhes/' . $record['id'])) ?>">Voltar</a></div><?php endif; ?>
<section class="relatorio contract-form-panel <?= $record !== null ? 'contract-edit-panel' : '' ?>">
    <?php if ($record!==null && $draft!==[]): ?><div class="warning-message" role="status"><strong>A tentativa anterior não foi salva.</strong> Os dados atuais do pedido estão no formulário abaixo. Compare antes de reenviar.<br>Texto digitado: <?= e($old($draft['titulo'] ?? '')) ?> · Valor: <?= e($old($draft['valor'] ?? '')) ?> · Fornecedor: confira a seleção abaixo</div><?php endif; ?>
    <form method="post" action="<?= e(url($record===null ? 'contrato/criar' : 'contrato/editar/'.$record['id'])) ?>" <?= $record===null ? 'data-contract-certificate-form data-pedido-wizard' : '' ?>>
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($record===null): ?><input type="hidden" name="_request_token" value="<?= e(bin2hex(random_bytes(16))) ?>"><?php endif; ?>
        <?php if ($record!==null): ?><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>"><?php endif; ?>
        <?php if ($record===null): ?>
        <nav class="pedido-step-nav" aria-label="Etapas do novo pedido" data-pedido-progress hidden><ol><li data-pedido-progress-item>1. Dados do pedido</li><li data-pedido-progress-item>2. Notas e produtos</li><li data-pedido-progress-item>3. Conferir e salvar</li></ol></nav>
        <p class="pedido-step-status" data-pedido-step-status role="status" aria-live="polite" hidden></p>
        <fieldset class="pedido-step" data-pedido-step="0"><legend tabindex="-1">1. Dados do pedido</legend>
        <?php endif; ?>
        <div class="mb-3"><label for="titulo">Título do pedido</label><input class="form-control" id="titulo" name="titulo" minlength="2" maxlength="150" required placeholder="Ex: Pedido de papelaria 2026" value="<?= e($record['titulo'] ?? $old($draft['titulo'] ?? '')) ?>"></div>
        <div class="mb-3"><label for="valor">Valor total do pedido (R$)</label><input class="form-control" id="valor" name="valor" inputmode="decimal" aria-describedby="money-format" pattern="(?:0|[1-9][0-9]{0,8}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,2})(?:,[0-9]{1,2})?" required placeholder="Ex: 1.250,00" value="<?= e($record===null ? $old($draft['valor'] ?? '') : ($record['valor_centavos']===null ? '' : number_format((int)$record['valor_centavos']/100,2,',',''))) ?>"></div>
        <small id="money-format">Use 1250,00 ou 1.250,00. Até duas casas decimais; máximo de R$ 999.999.999,99.</small><div class="mb-3"><label for="fornecedor">Fornecedor (opcional)</label><select class="form-select" id="fornecedor" name="fornecedor"><option value="">Não informado</option><?php foreach ($suppliers as $supplier): if (!(int)$supplier['ativo'] && ($record===null || (int)$record['id_fornecedor']!==(int)$supplier['id'])) { continue; } ?><option value="<?= (int)$supplier['id'] ?>" <?= ($record!==null ? (string)$record['id_fornecedor'] : $old($draft['fornecedor'] ?? ''))===(string)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['nome']) ?><?= (int)$supplier['ativo']===0 ? ' (inativo; vínculo histórico)' : '' ?></option><?php endforeach; ?></select><small>Fornecedores são geridos em Certidões e Fornecedores.</small></div>
        <?php if ($record===null): ?>
        </fieldset>
        <fieldset class="pedido-step" data-pedido-step="1"><legend tabindex="-1">2. Notas e produtos</legend>
        <div data-contract-builder>
            <p class="contract-builder-hint">Adicione os produtos de cada nota. Preços podem ser informados como 1250,00 ou 1.250,00. Para começar o controle, informe na tela Estoque a quantidade disponível na escola. Depois, registre quanto chegou em Registrar recebimento.</p>
            <div data-contract-sheets><?php foreach ($formSheets as $sheetIndex=>$sheet): if (!is_array($sheet)) { continue; } $products=isset($sheet['produtos']) && is_array($sheet['produtos']) && $sheet['produtos']!==[] ? $sheet['produtos'] : [['nome'=>'','marca'=>'','unidade'=>'UN','quantidade'=>'','preco'=>'']]; ?>
            <section class="modulo5-builder-sheet" data-contract-sheet><div class="section-head"><h4>Nota <span data-sheet-number><?= (int)$sheetIndex+1 ?></span></h4><label class="contract-builder-observation">Observações da nota (opcional)<textarea class="form-control" name="folhas[<?= (int)$sheetIndex ?>][observacao]" maxlength="2000" rows="1"><?= e($old($sheet['observacao'] ?? '')) ?></textarea></label><button class="btn btn-outline-danger btn-sm" type="button" data-remove-sheet>Remover nota</button></div>
                <div data-contract-products><?php foreach ($products as $productIndex=>$product): if (!is_array($product)) { continue; } ?><div class="modulo5-builder-product" data-contract-product>
                    <label><span class="contract-field-label">Produto</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][nome]" minlength="2" maxlength="150" required placeholder="Nome do produto" value="<?= e($old($product['nome'] ?? '')) ?>"></label>
                    <label><span class="contract-field-label">Marca (opcional)</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][marca]" maxlength="100" placeholder="Marca" value="<?= e($old($product['marca'] ?? '')) ?>"></label>
                    <label><span class="contract-field-label">Unidade</span><?php $renderUnitSelect('folhas['.(int)$sheetIndex.'][produtos]['.(int)$productIndex.'][unidade]', $old($product['unidade'] ?? 'UN')); ?></label>
                    <label><span class="contract-field-label">Quantidade contratada</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][quantidade]" type="number" min="1" max="1000000" required placeholder="Quantidade" title="Quantidade contratada; o estoque físico é conferido separadamente" value="<?= e($old($product['quantidade'] ?? '')) ?>"></label>
                    <label><span class="contract-field-label">Valor unitário R$</span><input class="form-control" name="folhas[<?= (int)$sheetIndex ?>][produtos][<?= (int)$productIndex ?>][preco]" inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,8}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,2})(?:,[0-9]{1,2})?" required placeholder="Ex: 1.250,00" value="<?= e($old($product['preco'] ?? '')) ?>"></label>
                    <button class="btn btn-outline-danger btn-sm" type="button" data-remove-product aria-label="Remover produto">X</button>
                </div><?php endforeach; ?></div><button class="btn btn-secondary" type="button" data-add-product>+ Adicionar Outro Produto</button>
            </section><?php endforeach; ?></div><button class="btn btn-outline-primary" type="button" data-add-sheet>＋ Adicionar outra nota</button>
            <div class="modulo5-budget" role="status"><p>Soma dos Produtos: <strong data-items-total>R$ 0,00</strong></p><p>Saldo (Orçamento - Produtos): <strong data-budget-left>R$ 0,00</strong></p></div>
        </div>
        </fieldset>
        <fieldset class="pedido-step" data-pedido-step="2"><legend tabindex="-1">3. Conferir e salvar</legend>
            <p>Confira título, fornecedor, valores, notas e produtos antes de salvar. O estoque físico será registrado separadamente.</p>
            <div class="pedido-review" data-pedido-review hidden></div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" <?= $record===null ? 'data-contract-create-submit' : '' ?> <?= $record===null && $creationBlocked ? 'disabled' : '' ?>><?= $record !== null ? 'Salvar alterações' : 'Salvar pedido' ?></button>
        <?php if ($record===null): ?></fieldset>
        <div class="pedido-step-actions" data-pedido-controls hidden><button class="btn btn-secondary" type="button" data-pedido-back>Voltar</button><button class="btn btn-primary" type="button" data-pedido-next>Continuar</button></div><?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(url('contrato')) ?>">Cancelar</a>
    </form>
</section>
