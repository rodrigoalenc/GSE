<?php
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
?>
<section class="page-hero">
    <div>
        <p class="hero-kicker">Acompanhamento documental</p>
        <h2>Central de DVAs</h2>
        <p>Priorize vencimentos sem perder versões anteriores ou alterar o histórico.</p>
    </div>
    <div class="hero-stats"><span><strong><?= e((string) $result['total']) ?></strong> alunos ativos</span></div>
</section>

<section class="status-legend" aria-label="Legenda das situações da DVA">
    <?php foreach (DvaStatus::ALL as $status): ?>
        <span class="dva-badge dva-<?= e($status) ?>"><?= e(DvaStatus::label($status)) ?></span>
    <?php endforeach; ?>
</section>

<section class="relatorio">
    <div class="section-head"><div><h2>Filtros</h2><p>Localize alunos pelo nome e pela situação documental.</p></div></div>
    <form class="student-filters dva-filters" method="get" action="<?= e(url('dva')) ?>" role="search">
        <div><label for="q">Aluno</label><input id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Nome do aluno"></div>
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
</section>

<section class="relatorio">
    <div class="section-head">
        <div><h2>Resultados</h2><p>Somente alunos ativos integram as pendências operacionais.</p></div>
        <span class="result-pill"><?= e((string) $result['total']) ?> registro(s)</span>
    </div>
    <div class="table-scroll">
        <table class="tabela-filtrada">
            <thead><tr><th>Aluno</th><th>Turma</th><th>Situação</th><th>Vencimento</th><th>Ação</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?><tr><td colspan="5" class="empty-state">Nenhum registro encontrado para os filtros informados.</td></tr><?php endif; ?>
            <?php foreach ($result['items'] as $item): ?>
                <tr>
                    <td class="student-name"><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                    <td><span class="turma-badge"><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></span></td>
                    <td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td>
                    <td><?= e($item['data_vencimento'] ? date('d/m/Y', strtotime((string) $item['data_vencimento'])) : '—') ?></td>
                    <td><a class="btn-acao" href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>"><?= $item['dva_id'] ? 'Renovar DVA' : 'Registrar DVA' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Paginação de DVAs">
            <?php for ($number = 1; $number <= $result['pages']; $number++): ?>
                <a class="<?= $number === $result['page'] ? 'active' : '' ?>" href="<?= e(url('dva?' . http_build_query(array_merge($queryBase, ['page' => $number])))) ?>" <?= $number === $result['page'] ? 'aria-current="page"' : '' ?>><?= e((string) $number) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
