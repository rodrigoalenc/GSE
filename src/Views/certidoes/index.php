<?php
$state = $filters['estado'];
$route = match ($state) { 'arquivada'=>'certidao/arquivadas', 'excluida'=>'certidao/excluidas', default=>'certidao' };
$columns = []; $rows = []; $cells = [];
foreach ($result['items'] as $item) {
    $columns[(int)$item['id_fornecedor']] = $item['fornecedor'];
    $rows[(int)$item['id_tipo_certidao']] = $item['tipo_certidao'];
    $cells[(int)$item['id_tipo_certidao']][(int)$item['id_fornecedor']][] = $item;
}
?>
<section class="cert-overview">
    <div><p class="cert-eyebrow">CONTROLE DOCUMENTAL</p><h2>Painel central das certidões</h2><p>Acompanhe os vencimentos e preserve o histórico de cada fornecedor.</p></div>
    <div class="cert-stats"><?php foreach ($summary as $key=>$count): ?><a href="<?= e(url('certidao?validade='.$key)) ?>"><strong><?= (int)$count ?></strong><span><?= e(CertidaoStatus::LABELS[$key]) ?></span></a><?php endforeach; ?></div>
</section>
<p class="cert-muted">Resumo de todas as certidões correntes, independente dos filtros abaixo.</p>
<?php require __DIR__.'/nav.php'; ?>
<section class="cert-panel">
    <h2>Filtros</h2>
    <form method="get" action="<?= e(url($route)) ?>" class="cert-filters">
        <div><label for="busca">Buscar fornecedor, tipo ou nº</label><input id="busca" name="busca" maxlength="150" value="<?= e($filters['busca']) ?>"></div>
        <div><label for="fornecedor">Fornecedor</label><select id="fornecedor" name="fornecedor"><option value="">Todos</option><?php foreach ($fornecedores as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (string)$option['id']===$filters['fornecedor'] ? 'selected' : '' ?>><?= e($option['nome']) ?></option><?php endforeach; ?></select></div>
        <div><label for="tipo">Tipo de certidão</label><select id="tipo" name="tipo"><option value="">Todos</option><?php foreach ($tipos as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (string)$option['id']===$filters['tipo'] ? 'selected' : '' ?>><?= e($option['nome']) ?></option><?php endforeach; ?></select></div>
        <div><label for="validade">Validade</label><select id="validade" name="validade"><option value="">Todas</option><?php foreach (CertidaoStatus::LABELS as $key=>$label): ?><option value="<?= e($key) ?>" <?= $filters['validade']===$key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="ano">Ano de vencimento</label><input id="ano" name="ano" inputmode="numeric" maxlength="4" placeholder="Todos os anos" value="<?= e($filters['ano'] === 'todos' ? '' : $filters['ano']) ?>"></div>
        <div class="cert-actions"><button class="btn-primary" type="submit">Filtrar</button><a href="<?= e(url($route)) ?>">Limpar</a></div>
    </form>
</section>
<div class="cert-meta"><p><strong><?= (int)$result['total'] ?></strong> documentos nos filtros · <?= count($result['items']) ?> nesta página · <?= count($columns) ?> fornecedores nesta página.</p><span>Página <?= (int)$result['page'] ?> de <?= (int)$result['pages'] ?></span></div>
<?php if ($result['items'] === []): ?>
    <section class="cert-panel empty-state"><h2>Nenhuma certidão encontrada</h2><p>Revise os filtros ou cadastre uma nova certidão.</p></section>
<?php else: ?>
    <div class="cert-matrix-scroll" tabindex="0" role="region" aria-label="Matriz de certidões; use as setas para rolar horizontalmente">
        <table class="cert-matrix"><caption><?= e($title) ?> — até 25 documentos por página; documentos do mesmo fornecedor podem continuar nas próximas páginas.</caption><thead><tr><th scope="col">Tipo / Fornecedor</th><?php foreach ($columns as $name): ?><th scope="col"><?= e($name) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($rows as $typeId=>$name): ?><tr><th scope="row"><?= e($name) ?></th>
            <?php foreach ($columns as $supplierId=>$supplier): ?><td>
                <?php if (empty($cells[$typeId][$supplierId])): ?><span class="cert-muted">Sem documento nesta página</span><?php endif; ?>
                <?php foreach ($cells[$typeId][$supplierId] ?? [] as $item): ?>
                    <article class="cert-card cert-<?= e($item['validade']) ?>">
                        <div class="cert-card-head"><strong>#<?= (int)$item['id'] ?></strong><span><?= e(CertidaoStatus::LABELS[$item['validade']]) ?></span></div>
                        <p>Vencimento <strong><?= e($item['data_vencimento']) ?></strong></p>
                        <?php if (empty($item['pdf_privado'])): ?><p class="cert-muted">PDF pendente de revisão</p><?php endif; ?>
                        <a href="<?= e(url('certidao/detalhes/'.(int)$item['id'])) ?>">Consultar documento →</a>
                    </article>
                <?php endforeach; ?>
            </td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>
<nav class="cert-pagination" aria-label="Páginas da matriz">
    <?php if ($result['page'] > 1): ?><a class="btn-secondary" href="<?= e(url($route.'?'.http_build_query(array_merge($filters,['page'=>$result['page']-1])))) ?>">← Anterior</a><?php endif; ?>
    <?php if ($result['page'] < $result['pages']): ?><a class="btn-secondary" href="<?= e(url($route.'?'.http_build_query(array_merge($filters,['page'=>$result['page']+1])))) ?>">Próxima →</a><?php endif; ?>
</nav>
