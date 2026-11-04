<?php
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
$statusTabs = [
    '' => 'Todas',
    DvaStatus::SEM_DVA => 'Sem DVA',
    DvaStatus::VENCIDA => 'Vencidas',
    DvaStatus::VENCE_HOJE => 'Vence hoje',
    DvaStatus::A_VENCER => 'A vencer',
    DvaStatus::VIGENTE => 'Vigentes',
];
$firstResult = $result['total'] > 0 ? ((int) $result['page'] - 1) * (int) ($result['per_page'] ?? 20) + 1 : 0;
$lastResult = $result['total'] > 0 ? $firstResult + count($result['items']) - 1 : 0;
?>
<div class="dva-page">
    <p class="dva-page-description">Acompanhe prazos e pendências das declarações de vacina dos alunos.</p>

    <nav class="dva-status-tabs" aria-label="Filtrar por situação da DVA">
        <?php foreach ($statusTabs as $status => $label): ?>
            <?php $tabQuery = array_filter(array_merge($queryBase, ['dva' => $status]), static fn (string $value): bool => $value !== ''); ?>
            <a href="<?= e(url('dva' . ($tabQuery !== [] ? '?' . http_build_query($tabQuery) : ''))) ?>"<?= $filters['dva'] === $status ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

<section class="dva-workspace" aria-labelledby="dva-results-title">
    <form class="student-filters dva-filters" method="get" action="<?= e(url('dva')) ?>" role="search">
        <?php if ($filters['turma'] !== ''): ?><input type="hidden" name="turma" value="<?= e($filters['turma']) ?>"><?php endif; ?>
        <div class="dva-search-field"><label for="q">Buscar aluno</label><div class="dva-search-input"><?= gse_icon('search') ?><input id="q" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Buscar por nome do aluno"></div></div>
        <div>
            <label for="dva">Situação</label>
            <select id="dva" name="dva">
                <option value="">Todas</option>
                <?php foreach (DvaStatus::ALL as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions"><button class="btn-primary" type="submit">Filtrar</button><a class="btn-secondary" href="<?= e(url('dva')) ?>">Limpar</a></div>
    </form>

    <div class="dva-result-heading">
        <h2 id="dva-results-title">Declarações dos alunos</h2>
        <p>Somente alunos ativos <span aria-hidden="true">·</span> <?= (int) $result['total'] ?> registro(s)</p>
    </div>
    <div class="table-scroll">
        <table class="tabela-filtrada dva-table">
            <caption class="visually-hidden">Declarações de vacina dos alunos ativos conforme os filtros selecionados.</caption>
            <thead><tr><th scope="col">Aluno</th><th scope="col">Turma</th><th scope="col">Vencimento</th><th scope="col">Situação</th><th scope="col">Ação</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?><tr><td colspan="5" class="empty-state">Nenhum registro encontrado para os filtros informados.</td></tr><?php endif; ?>
            <?php foreach ($result['items'] as $item): ?>
                <tr>
                    <td class="student-name"><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                    <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
                    <td><?= e($item['data_vencimento'] ? UiPresentation::civilDate($item['data_vencimento']) : '—') ?></td>
                    <td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td>
                    <td><a class="dva-open-link" href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>">Abrir DVA <?= gse_icon('arrow-right') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="dva-table-footer">
        <p>Exibindo <?= $firstResult ?> a <?= $lastResult ?> de <?= (int) $result['total'] ?> registros</p>
    <?php if ($result['pages'] > 1): ?>
        <?php $renderPagination = require __DIR__ . '/../partials/pagination.php'; $renderPagination((int) $result['page'], (int) $result['pages'], static fn (int $p): string => url('dva?' . http_build_query(array_merge($queryBase, ['page' => $p]))), 'Páginas de DVAs'); ?>
    <?php endif; ?>
    </div>
</section>
</div>
