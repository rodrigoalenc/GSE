<?php $queryBase = array_filter($filters, static fn (string $value): bool => $value !== ''); ?>
<section class="relatorio">
    <form class="student-filters dva-filters" method="get" action="<?= e(url('dva')) ?>">
        <div><label for="q">Aluno</label><input id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="100"></div>
        <div><label for="dva">Situação</label><select id="dva" name="dva"><option value="">Todas</option><?php foreach (DvaStatus::ALL as $status): ?><option value="<?= e($status) ?>" <?= $filters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option><?php endforeach; ?></select></div>
        <div class="filter-actions"><button class="btn-primary" type="submit">Filtrar</button><a class="btn-secondary" href="<?= e(url('dva')) ?>">Limpar</a></div>
    </form>
</section>
<section class="relatorio">
    <p class="result-summary"><?= e((string) $result['total']) ?> aluno(s) ativo(s).</p>
    <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Aluno</th><th>Turma</th><th>Situação</th><th>Vencimento</th><th>Ação</th></tr></thead><tbody>
        <?php if ($result['items'] === []): ?><tr><td colspan="5" class="empty-state">Nenhum registro encontrado.</td></tr><?php endif; ?>
        <?php foreach ($result['items'] as $item): ?><tr><td><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td><td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td><td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td><td><?= e($item['data_vencimento'] ? date('d/m/Y', strtotime((string) $item['data_vencimento'])) : '—') ?></td><td><a class="btn-acao" href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>"><?= $item['dva_id'] ? 'Renovar' : 'Registrar' ?></a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($result['pages'] > 1): ?><nav class="pagination" aria-label="Paginação de DVAs"><?php for ($number = 1; $number <= $result['pages']; $number++): ?><a class="<?= $number === $result['page'] ? 'active' : '' ?>" href="<?= e(url('dva?' . http_build_query(array_merge($queryBase, ['page' => $number])))) ?>"><?= e((string) $number) ?></a><?php endfor; ?></nav><?php endif; ?>
</section>
