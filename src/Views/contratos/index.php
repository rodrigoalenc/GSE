<?php
$params = ['busca' => $search, 'situacao' => $status];
$date = static function (?string $value): string {
    if (!$value) { return '—'; }
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y H:i'); }
    catch (Exception) { return 'Data indisponível'; }
};
?>
<section class="relatorio modulo5-hero">
    <div class="section-head"><div><h2>Contratos</h2><p>Consulte contratos, notas e valores registrados.</p></div><a class="btn btn-primary" href="<?= e(url('contrato/criar')) ?>">Novo contrato</a></div>
    <?php if ($summary !== null): ?>
    <div class="modulo5-metrics" aria-label="Resumo dos contratos ativos nesta busca">
        <div><strong><?= (int)$summary['contratos'] ?></strong><span>Contratos ativos</span></div>
        <div><strong><?= (int)$summary['notas'] ?></strong><span>Notas ativas</span></div>
        <div><strong><?= e(Contrato::money((int)$summary['valor_centavos'])) ?></strong><span>Valor contratado conciliado<?= (int)$summary['valores_pendentes']>0 ? ' ('.(int)$summary['valores_pendentes'].' pendente(s))' : '' ?></span></div>
        <div><strong><?= (int)$summary['faturadas'] ?> / <?= (int)$summary['notas'] ?></strong><span>Notas faturadas</span></div>
    </div>
    <p class="modulo5-hint">Indicadores consideram todos os contratos ativos encontrados na busca, em todas as páginas. O valor soma somente contratos com valor conferido; notas faturadas têm data de faturamento registrada.</p>
    <?php endif; ?>
</section>
<section class="relatorio">
    <form method="get" action="<?= e(url('contrato')) ?>" class="modulo5-filters">
        <label for="busca">Buscar por título<input class="form-control" id="busca" name="busca" value="<?= e($search) ?>"></label>
        <label for="situacao">Situação<select class="form-select" id="situacao" name="situacao"><option value="ativos" <?= $status==='ativos'?'selected':'' ?>>Ativos</option><option value="excluidos" <?= $status==='excluidos'?'selected':'' ?>>Excluídos</option></select></label>
        <button class="btn btn-primary">Consultar</button>
    </form>
    <p><?= (int)$result['total'] ?> contrato(s) <?= $status==='ativos'?'ativo(s)':'excluído(s)' ?> nesta busca.</p>
    <?php if ($result['items']===[]): ?><p class="empty-state">Nenhum contrato encontrado para os filtros.</p><?php else: ?>
    <div class="table-scroll"><table class="table"><thead><tr><th>Número</th><th>Título</th><th>Fornecedor</th><th>Notas</th><th>Valor contratado</th><th>Faturamento</th><th><?= $status==='ativos'?'Registro':'Exclusão' ?></th><th>Acesso</th></tr></thead><tbody>
    <?php foreach ($result['items'] as $row): ?><tr>
        <td><?= (int)$row['id'] ?></td><td><?= e($row['titulo']) ?><?= $status==='excluidos'?' (excluído)':'' ?></td>
        <td><?= e($row['fornecedor'] ?? 'Não informado') ?></td><td><?= (int)$row['notas'] ?></td>
        <td><?= e(Contrato::money($row['valor_centavos']===null ? null : (int)$row['valor_centavos'])) ?></td>
        <td><?= (int)$row['notas_faturadas'] ?> de <?= (int)$row['notas'] ?> nota(s)</td>
        <td><?= $date($status==='ativos' ? $row['criado_em'] : $row['excluido_em']) ?><?php if ($status==='excluidos'): ?><br><small>Por <?= e($row['excluido_por_nome'] ?? 'Usuário indisponível') ?></small><?php endif; ?></td>
        <td><a href="<?= e(url('contrato/detalhes/'.$row['id'].'?'.http_build_query($params+['pagina'=>$result['page']]))) ?>">Detalhes</a></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <nav aria-label="Páginas"><?php for ($p=1;$p<=$result['pages'];$p++): ?><a class="btn btn-sm <?= $p===$result['page'] ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url('contrato?'.http_build_query($params+['pagina'=>$p]))) ?>"><?= $p ?></a> <?php endfor; ?></nav>
    <?php endif; ?>
</section>
