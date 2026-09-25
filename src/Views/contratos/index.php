<section class="relatorio">
    <div class="section-head"><div><h2>Contratos</h2><p><?= (int)$result['total'] ?> contrato(s) ativo(s) nesta busca.</p></div><a class="btn-secondary" href="<?= e(url('contrato/criar')) ?>">Novo contrato</a></div>
    <form method="get" action="<?= e(url('contrato')) ?>" class="mb-3"><label for="busca">Buscar por título</label><div class="d-flex gap-2"><input class="form-control" id="busca" name="busca" value="<?= e($search) ?>"><button class="btn btn-primary">Buscar</button></div></form>
    <?php if ($result['items']===[]): ?><p class="empty-state">Nenhum contrato encontrado.</p><?php else: ?>
    <div class="table-scroll"><table class="table"><thead><tr><th>Número</th><th>Título</th><th>Fornecedor</th><th>Valor contratado</th><th></th></tr></thead><tbody>
    <?php foreach ($result['items'] as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= e($row['titulo']) ?></td><td><?= e($row['fornecedor'] ?? 'Não informado') ?></td><td><?= e(Contrato::money($row['valor_centavos']===null ? null : (int)$row['valor_centavos'])) ?></td><td><a href="<?= e(url('contrato/detalhes/'.$row['id'])) ?>">Abrir</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <nav aria-label="Páginas"><?php for ($p=1;$p<=$result['pages'];$p++): ?><a class="btn btn-sm <?= $p===$result['page'] ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('contrato?busca='.rawurlencode($search).'&pagina='.$p)) ?>"><?= $p ?></a> <?php endfor; ?></nav>
    <?php endif; ?>
</section>
