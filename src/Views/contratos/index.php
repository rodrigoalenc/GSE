<?php
$params = ['busca' => $search, 'situacao' => $status];
$date = static function (?string $value): string {
    if (!$value) { return '—'; }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y H:i');
    } catch (Exception) { return 'Data indisponível'; }
};
?>
<?php if ($summary !== null): ?>
<section class="contract-overview" aria-label="Visão geral dos pedidos cadastrados">
    <div><h2>Visão geral dos pedidos cadastrados</h2><p>Use esta tela para acompanhar rapidamente os pedidos, a quantidade de notas e o valor total já registrado.</p></div>
    <div class="contract-overview-stats">
        <span><strong><?= (int) $summary['contratos'] ?></strong>Pedidos cadastrados</span>
        <span><strong><?= (int) $summary['notas'] ?></strong>Notas geradas</span>
        <span><strong><?= e(Contrato::money((int) $summary['valor_centavos'])) ?></strong>Valor total<?= (int) $summary['valores_pendentes'] > 0 ? ' · ' . (int) $summary['valores_pendentes'] . ' pendente(s)' : '' ?></span>
        <span><strong><?= (int) $summary['faturadas'] ?></strong>Notas faturadas</span>
    </div>
</section>
<?php endif; ?>

<div class="contract-list-intro"><div><h2>Lista principal</h2><p>Abra os detalhes para acompanhar notas, produtos e saldo de cada pedido.</p></div><div class="contract-list-create-action"><a class="btn-primary" href="<?= e(url('contrato/criar')) ?>">+ Novo Pedido</a></div></div>

<section class="relatorio contract-list-panel" aria-labelledby="contract-list-title">
    <div class="contract-list-heading"><h2 id="contract-list-title"><?= $status === 'ativos' ? 'Pedidos Cadastrados' : 'Pedidos Excluídos' ?></h2>
        <form method="get" action="<?= e(url('contrato')) ?>" class="contract-list-filters">
            <label for="busca">Buscar pedido<input id="busca" name="busca" value="<?= e($search) ?>" placeholder="Título do pedido"></label>
            <label for="situacao">Situação<select id="situacao" name="situacao"><option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option><option value="excluidos" <?= $status === 'excluidos' ? 'selected' : '' ?>>Excluídos</option></select></label>
            <button class="btn-secondary" type="submit">Consultar</button>
        </form>
    </div>
    <?php if ($result['items'] === []): ?><p class="empty-state">Nenhum pedido encontrado para os filtros.</p><?php else: ?>
    <div class="table-scroll"><table class="contract-list-table"><thead><tr><th>ID</th><th>Título do pedido</th><th>Notas</th><th>Valor total</th><th>Faturamento</th><th>Média por nota</th><th><?= $status === 'ativos' ? 'Data de registro' : 'Data de exclusão' ?></th><th>Ações</th></tr></thead><tbody>
        <?php foreach ($result['items'] as $row): ?>
            <?php $noteCount = (int) $row['notas']; $billedCount = (int) $row['notas_faturadas']; ?>
            <tr class="<?= $noteCount > 0 && $billedCount === $noteCount ? 'contract-row-paid' : '' ?>">
                <td class="contract-id">#<?= (int) $row['id'] ?></td>
                <td class="contract-title"><strong><?= e($row['titulo']) ?></strong><small>Pedido criado para controle financeiro e distribuição em notas.</small><?php if ($row['fornecedor']): ?><small><?= e($row['fornecedor']) ?></small><?php endif; ?><?php if ($status === 'excluidos'): ?><small>Pedido excluído</small><?php endif; ?></td>
                <td><span class="contract-pill"><?= $noteCount ?> nota(s)</span></td>
                <td class="contract-money"><?= e(Contrato::money($row['valor_centavos'] === null ? null : (int) $row['valor_centavos'])) ?></td>
                <td><span class="contract-pill <?= $billedCount > 0 ? 'paid' : 'pending' ?>"><?= $billedCount ?> de <?= $noteCount ?> nota(s)</span></td>
                <td><span class="contract-average"><?= $row['valor_centavos'] !== null && $noteCount > 0 ? e(Contrato::money((int) round((int) $row['valor_centavos'] / $noteCount))) : '—' ?></span></td>
                <td class="contract-date"><?php $registeredParts = explode(' ', $date($status === 'ativos' ? $row['criado_em'] : $row['excluido_em']), 2); ?><?= e($registeredParts[0]) ?><?php if (isset($registeredParts[1])): ?><small><?= e($registeredParts[1]) ?></small><?php endif; ?><?php if ($status === 'excluidos'): ?><small>Por <?= e($row['excluido_por_nome'] ?? 'Usuário indisponível') ?></small><?php endif; ?></td>
                <td><div class="contract-row-actions"><a class="btn-secondary contract-details-link" href="<?= e(url('contrato/detalhes/' . $row['id'] . '?' . http_build_query($params + ['pagina' => $result['page']]))) ?>">Ver detalhes</a><?php if ($status === 'ativos'): ?><a class="contract-delete-link" href="<?= e(url('contrato/detalhes/' . $row['id'] . '#delete-contract')) ?>">🗑️ Apagar</a><?php endif; ?></div></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <nav class="contract-pagination" aria-label="Páginas de pedidos"><?php for ($p = 1; $p <= $result['pages']; $p++): ?><a class="btn-secondary <?= $p === $result['page'] ? 'active' : '' ?>" href="<?= e(url('contrato?' . http_build_query($params + ['pagina' => $p]))) ?>"><?= $p ?></a><?php endfor; ?></nav>
    <?php endif; ?>
</section>
