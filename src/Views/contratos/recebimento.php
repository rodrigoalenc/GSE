<?php $back='contrato/detalhes/'.$record['id'].'#folha-'.$sheet['id']; ?>
<nav class="contract-detail-actions" aria-label="Ações do recebimento"><a class="btn-secondary" href="<?= e(url($back)) ?>">Voltar à nota</a><a class="btn-secondary" href="<?= e(url('estoque')) ?>">Consultar estoque</a></nav>
<section class="relatorio">
<h2>Recebimento da nota <?= (int)$sheet['numero_pagina'] ?></h2>
<p><?= e($record['titulo']) ?></p>
<?php if ($error!==''): ?><p class="error-message" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($preview!==null): ?>
<h3>Confira antes de confirmar</h3>
<p>Você vai registrar o recebimento dos produtos abaixo. A quantidade disponível será atualizada nesta confirmação.</p>
<div class="table-scroll"><table class="table stock-receipt-table"><thead><tr><th>Produto</th><th>Recebido agora</th><th>Disponível antes</th><th>Disponível depois</th></tr></thead><tbody>
<?php foreach ($preview['rows'] as $row): $item=$row['item']; ?>
<tr><td data-label="Produto"><?= e($item['nome_produto']) ?><?= $item['marca']!=='' ? ' — '.e($item['marca']) : '' ?></td><td data-label="Recebido agora"><?= (int)$row['quantity'] ?> <?= e($item['unidade']) ?></td><td data-label="Disponível antes"><?= (int)$item['saldo'] ?> <?= e($item['unidade']) ?></td><td data-label="Disponível depois"><?= (int)$row['after'] ?> <?= e($item['unidade']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p>Confirme somente o que chegou agora. Produtos recebidos anteriormente já estão incluídos na quantidade disponível.</p>
<form method="post" action="<?= e(url('contrato/recebimento/'.$record['id'])) ?>" id="confirm-note-receipt">
<input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="folha" value="<?= (int)$sheet['id'] ?>"><input type="hidden" name="chave" value="<?= e($key) ?>">
<div class="contract-inline-actions"><button class="btn-primary" name="etapa" value="confirmar">Confirmar recebimento</button><button class="btn-secondary" name="etapa" value="editar">Corrigir quantidades</button></div>
</form>
<?php else: ?>
<p>Informe quanto realmente chegou agora. As quantidades da nota estão preenchidas para conferência. Use zero nos produtos que não chegaram.</p>
<p>O recebimento soma à quantidade disponível. Confira se esta entrega já foi registrada no histórico de cada produto.</p>
<?php if ($items===[]): ?><p>Nenhum produto ativo nesta nota. Adicione os produtos antes de registrar o recebimento.</p><?php else: ?>
<form method="post" action="<?= e(url('contrato/recebimento/'.$record['id'])) ?>" id="note-receipt-form">
<input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="folha" value="<?= (int)$sheet['id'] ?>"><input type="hidden" name="chave" value="<?= e($key) ?>"><input type="hidden" name="etapa" value="revisar">
<div class="table-scroll"><table class="table stock-receipt-table"><thead><tr><th>Produto</th><th>Quantidade da nota</th><th>Disponível agora</th><th>Recebido agora</th></tr></thead><tbody>
<?php foreach ($items as $item): $itemId=(int)$item['id']; $pending=(int)$item['estoque_inicializado']===0; $uncertain=(int)$item['abertura_sem_comprovacao']===1; ?>
<tr><td data-label="Produto"><strong><?= e($item['nome_produto']) ?><?= $item['marca']!=='' ? ' — '.e($item['marca']) : '' ?></strong><br><a href="<?= e(url('contrato/historico/'.$itemId)) ?>">Histórico</a></td><td data-label="Quantidade da nota"><?= e($item['quantidade_contratada'] ?? $item['quantidade']) ?> <?= e($item['unidade']) ?></td><td data-label="Disponível agora">
<?php if ($pending): ?>Quantidade inicial ainda não informada.<br><a href="<?= e(url('estoque/iniciar/'.$itemId)) ?>">Confirmar quantidade inicial</a>
<?php elseif ($uncertain): ?>Quantidade anterior precisa de conferência.<br><?php if ($isAdmin): ?><a href="<?= e(url('contrato/detalhes/'.$record['id'].'#old-opening-'.$itemId)) ?>" data-open-details>Registrar contagem atual</a><?php else: ?>Solicite a conferência ao administrador.<?php endif; ?>
<?php else: ?><?= (int)$item['saldo'] ?> <?= e($item['unidade']) ?><?php endif; ?>
</td><td data-label="Recebido agora"><label for="received-<?= $itemId ?>">Quantidade em <?= e($item['unidade']) ?></label><input id="received-<?= $itemId ?>" name="quantidades[<?= $itemId ?>]" type="number" min="0" max="1000000000" step="1" required value="<?= e($quantities[$itemId] ?? '0') ?>"><input type="hidden" name="revisoes[<?= $itemId ?>]" value="<?= e($revisions[$itemId]) ?>"><?php if ($pending || $uncertain): ?><small>Deixe zero até confirmar a quantidade inicial.</small><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="contract-inline-actions"><button class="btn-primary">Revisar recebimento</button><a class="btn-secondary" href="<?= e(url($back)) ?>">Cancelar</a></div>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
