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
    <?php $renderPagination = require __DIR__ . '/../partials/pagination.php'; $renderPagination((int) $result['page'], (int) $result['pages'], $pageUrl, 'Páginas do Arquivo Passivo (' . $paginationPosition . ')', false, 'passivo-pagination'); ?>
    <?php endif; ?>
</div>
