<?php
$firstRecord = $result['total'] === 0 ? 0 : ($result['page'] - 1) * $result['per_page'] + 1;
$lastRecord = min($result['page'] * $result['per_page'], $result['total']);
$pageUrl = static fn (int $page): string => url('passivo?' . http_build_query([
    'q' => $filters['q'],
    'caixa' => $filters['caixa'],
    'localizacao' => $filters['localizacao'],
    'ativo' => $filters['ativo'] === '' ? 'todos' : $filters['ativo'],
    'ordem' => $filters['ordem'],
    'page' => $page,
]) . '#passivo-results-title');
?>
<div class="passivo-pagination-bar">
    <p class="passivo-page-summary">Exibindo <?= e((string) $firstRecord) ?>–<?= e((string) $lastRecord) ?> de <?= e((string) $result['total']) ?> registros · Página <?= e((string) $result['page']) ?> de <?= e((string) $result['pages']) ?></p>
    <?php if ($result['pages'] > 1): ?>
    <nav class="passivo-pagination" aria-label="Páginas do Arquivo Passivo (<?= e($paginationPosition) ?>)">
        <?php if ($result['page'] > 1): ?>
            <a href="<?= e($pageUrl(1)) ?>" aria-label="Primeira página">Primeira</a>
            <a href="<?= e($pageUrl($result['page'] - 1)) ?>" rel="prev">Anterior</a>
        <?php else: ?>
            <span aria-disabled="true">Anterior</span>
        <?php endif; ?>
        <?php for ($page = max(1, $result['page'] - 2); $page <= min($result['pages'], $result['page'] + 2); $page++): ?>
            <a href="<?= e($pageUrl($page)) ?>" aria-label="Página <?= e((string) $page) ?>" <?= $page === $result['page'] ? 'aria-current="page"' : '' ?>><?= e((string) $page) ?></a>
        <?php endfor; ?>
        <?php if ($result['page'] < $result['pages']): ?>
            <a href="<?= e($pageUrl($result['page'] + 1)) ?>" rel="next">Próxima</a>
            <a href="<?= e($pageUrl($result['pages'])) ?>" aria-label="Última página">Última</a>
        <?php else: ?>
            <span aria-disabled="true">Próxima</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
