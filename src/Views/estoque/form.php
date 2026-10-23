<?php $unit=match ($product['unidade']) { 'UN' => 'unidades', 'K' => 'quilogramas', 'Litros' => 'litros', default => $product['unidade'] }; ?>
<section class="relatorio stock-form-card">
<p><a href="<?= e(url($back)) ?>">Voltar ao estoque</a></p>
<h2><?= e($product['nome_produto']) ?><?= $product['marca'] ? ' — '.e($product['marca']) : '' ?></h2>
<p><?= e($product['contrato_titulo']) ?> · Nota <?= (int)$product['numero_pagina'] ?> · Unidade: <?= e($unit) ?></p>
<?php if ((int)$product['estoque_inicializado']===1 && !(int)$product['abertura_sem_comprovacao']): ?><p>Disponível agora: <strong><?= (int)$product['saldo'] ?> <?= e($unit) ?></strong></p><?php endif; ?>
<?php if ($error!==''): ?><p class="error-message" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($blocked!==''): ?><p class="warning-message"><?= e($blocked) ?></p>
<?php if ((int)$product['estoque_inicializado']===0 && $mode!=='iniciar'): ?><a class="btn btn-primary" href="<?= e(url('estoque/iniciar/'.$product['id'].$suffix)) ?>">Informar quantidade inicial</a>
<?php elseif ((int)$product['abertura_sem_comprovacao']===1 && Auth::isAdmin()): ?><a href="<?= e(url('contrato/detalhes/'.$product['id_pedido']).'#old-opening-'.(int)$product['id']) ?>">Conferir quantidade atual</a><?php endif; ?>
<?php else: ?>
<form method="post" action="<?= e(url('estoque/'.$mode.'/'.$product['id'].$suffix)) ?>" class="stock-action-form">
<input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revisao" value="<?= (int)$product['revisao'] ?>">
<?php if ($mode==='alertas'): ?>
<p>Aviso atual: <?= $product['estoque_minimo']===null ? 'desativado' : 'quando restarem '.(int)$product['estoque_minimo'].' '.e($unit) ?>. Bloqueio atual por máximo: <?= $product['estoque_maximo']===null ? 'desativado' : (int)$product['estoque_maximo'].' '.e($unit) ?>.</p>
<p>Deixe os campos vazios se não precisar de aviso ou bloqueio.</p>
<label>Avisar quando restarem <input type="number" name="minimo" min="0" max="1000000000" value="<?= e($minimum) ?>"> <span><?= e($unit) ?> (opcional)</span></label>
<details<?= $maximum!=='' ? ' open' : '' ?>><summary>Configuração avançada</summary><label>Bloquear recebimentos acima de <input type="number" name="maximo" min="1" max="1000000000" value="<?= e($maximum) ?>"> <span><?= e($unit) ?> (opcional)</span></label><p>Se informado, o máximo também limita a quantidade inicial.</p></details>
<button class="btn btn-primary">Salvar alertas</button>
<?php else: ?>
<input type="hidden" name="chave" value="<?= e($key) ?>">
<?php if ($preview!==null): ?>
<input type="hidden" name="quantidade" value="<?= (int)$preview['quantity'] ?>">
<div class="stock-confirmation" role="status">
<?php if ($mode==='iniciar'): ?><p>Você vai confirmar que há <strong><?= (int)$preview['quantity'] ?> <?= e($unit) ?></strong> disponíveis na escola agora.</p>
<?php else: ?><p>Você vai registrar <?= $mode==='consumir' ? 'o consumo' : 'o recebimento' ?> de <strong><?= (int)$preview['quantity'] ?> <?= e($unit) ?></strong>. A quantidade disponível passará de <strong><?= (int)$preview['before'] ?></strong> para <strong><?= (int)$preview['after'] ?></strong>.</p><?php endif; ?>
</div>
<div class="stock-form-actions"><button name="etapa" value="confirmar" class="btn btn-primary"><?= $mode==='iniciar' ? 'Confirmar quantidade inicial' : ($mode==='consumir' ? 'Confirmar consumo' : 'Confirmar recebimento') ?></button><button name="etapa" value="editar" class="btn btn-secondary">Alterar quantidade</button></div>
<?php else: ?>
<?php if ($mode==='iniciar'): ?><h3>Quantas unidades estão disponíveis na escola agora?</h3><p>Conte o que já está na escola, inclusive se a quantidade for zero. Depois de confirmar, registre as próximas entregas em Receber.</p><?php endif; ?>
<label><?= $mode==='consumir' ? 'Quantidade utilizada' : ($mode==='receber' ? 'Quantidade recebida' : 'Quantidade') ?><input name="quantidade" type="number" min="<?= $mode==='iniciar' ? 0 : 1 ?>" max="<?= $mode==='consumir' ? (int)$product['saldo'] : 1000000000 ?>" required value="<?= e($quantity) ?>" autofocus> <span><?= e($unit) ?></span></label>
<button class="btn btn-primary" name="etapa" value="revisar"><?= $mode==='iniciar' ? 'Conferir quantidade inicial' : ($mode==='consumir' ? 'Conferir consumo' : 'Conferir recebimento') ?></button>
<?php endif; ?>
<?php endif; ?>
</form>
<?php endif; ?>
</section>
