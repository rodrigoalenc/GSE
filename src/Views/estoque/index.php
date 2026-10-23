<?php $context='?'.http_build_query(['busca'=>$search,'pagina'=>$result['page']]); ?>
<section class="relatorio modulo5-hero"><h2>Estoque</h2><p>Confira o que está disponível na escola. Recebeu produtos? Use <strong>Receber</strong>. Utilizou? Use <strong>Registrar consumo</strong>.</p></section>
<section class="relatorio">
<form method="get" action="<?= e(url('estoque')) ?>" class="mb-3"><label for="busca">Buscar produto, marca ou pedido</label><div class="d-flex gap-2"><input id="busca" class="form-control" name="busca" value="<?= e($search) ?>"><button class="btn btn-primary">Buscar</button></div></form>
<p><?= (int)$result['total'] ?> produto(s). Quantidades na unidade indicada em cada produto.</p>
<div class="table-scroll"><table class="table stock-table"><thead><tr><th>Produto</th><th>Disponível agora</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($result['items'] as $item):
    $balance=(int)$item['saldo']; $pending=(int)$item['estoque_inicializado']===0; $uncertain=(int)$item['abertura_sem_comprovacao']===1;
    $low=$item['estoque_minimo']!==null && $balance<=(int)$item['estoque_minimo'];
?><tr id="produto-<?= (int)$item['id'] ?>"><td><strong><?= e($item['nome_produto']) ?><?= $item['marca'] ? ' — '.e($item['marca']) : '' ?></strong><small class="stock-product-source"><a href="<?= e(url('contrato/detalhes/'.$item['id_pedido'])) ?>"><?= e($item['titulo']) ?></a> · Nota <?= (int)$item['numero_pagina'] ?></small></td><td data-label="Disponível agora"><?= $pending || $uncertain ? 'A confirmar' : $balance.' '.e($item['unidade']) ?></td><td data-label="Situação"><span class="stock-status<?= $low && !$pending && !$uncertain ? ' stock-status-low' : '' ?>"><?= $pending ? 'Informe a quantidade inicial' : ($uncertain ? 'Conferência administrativa necessária' : ($balance===0 ? 'Sem estoque' : ($low ? 'Estoque baixo' : 'Normal'))) ?></span></td><td data-label="Ações"><div class="stock-row-actions">
<?php if ($pending): ?><a class="btn btn-primary btn-sm" href="<?= e(url('estoque/iniciar/'.$item['id'].$context)) ?>">Informar quantidade inicial</a>
<?php elseif ($uncertain): ?><?php if (Auth::isAdmin()): ?><a class="btn btn-primary btn-sm" href="<?= e(url('contrato/detalhes/'.$item['id_pedido']).'#old-opening-'.(int)$item['id']) ?>">Conferir quantidade atual</a><?php else: ?><span>Peça a conferência ao administrador.</span><?php endif; ?>
<?php else: ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('estoque/receber/'.$item['id'].$context)) ?>">Receber</a><a class="btn btn-outline-primary btn-sm" href="<?= e(url('estoque/consumir/'.$item['id'].$context)) ?>">Registrar consumo</a><?php endif; ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= e(url('contrato/historico/'.$item['id'].$context.'&origem=estoque')) ?>">Histórico</a></div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($result['items']===[]): ?><p>Nenhum produto encontrado.</p><?php endif; ?>
<?php $renderPagination = require __DIR__ . '/../partials/pagination.php'; $renderPagination((int) $result['page'], (int) $result['pages'], static fn (int $p): string => url('estoque?busca='.rawurlencode($search).'&pagina='.$p), 'Páginas do estoque'); ?>
</section>
<?php if ($result['items']!==[]): ?>
<section class="relatorio"><details class="stock-alerts"><summary>Alertas de estoque</summary><p>Configure avisos de quantidade baixa. O bloqueio por quantidade máxima é opcional e fica na configuração avançada.</p>
<ul class="stock-alert-list"><?php foreach ($result['items'] as $item): ?><li><span><strong><?= e($item['nome_produto']) ?><?= $item['marca'] ? ' — '.e($item['marca']) : '' ?></strong><small class="stock-product-source"><?= e($item['titulo']) ?> · Nota <?= (int)$item['numero_pagina'] ?> · <?= $item['estoque_minimo']===null ? 'Sem aviso de quantidade baixa' : 'Avisar quando restarem '.(int)$item['estoque_minimo'].' '.e($item['unidade']) ?><?= $item['estoque_maximo']===null ? '' : ' · Máximo: '.(int)$item['estoque_maximo'].' '.e($item['unidade']) ?></small></span><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('estoque/alertas/'.$item['id'].$context)) ?>">Configurar alertas</a></li><?php endforeach; ?></ul>
</details></section><?php endif; ?>
