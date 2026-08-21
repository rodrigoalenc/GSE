<section class="page-hero">
    <div><p class="hero-kicker">Administração acadêmica</p><h2>Turmas e anos letivos</h2><p>Organize os vínculos dos alunos sem apagar turmas ou registros históricos.</p></div>
    <div class="hero-stats"><span><strong><?= e((string) count($items)) ?></strong> resultados</span></div>
</section>

<div class="module-toolbar quick-actions">
    <div><h2>Ações rápidas</h2><p>Cadastre uma turma ou retorne à gestão de alunos.</p></div>
    <div class="toolbar-actions"><a class="btn-primary" href="<?= e(url('turma/criar')) ?>">+ Nova turma</a><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar aos alunos</a></div>
</div>

<section class="relatorio">
    <div class="section-head"><div><h2>Filtros</h2><p>Pesquise por nome e situação da turma.</p></div></div>
    <form class="student-filters" method="get" action="<?= e(url('turma')) ?>" role="search">
        <div><label for="q">Nome</label><input id="q" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Nome da turma"></div>
        <div>
            <label for="ativo">Situação</label>
            <select id="ativo" name="ativo"><option value="">Todas</option><option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Ativas</option><option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inativas</option></select>
        </div>
        <div class="filter-actions"><button class="btn-primary" type="submit">Filtrar</button><a class="btn-secondary" href="<?= e(url('turma')) ?>">Limpar</a></div>
    </form>
</section>

<section class="relatorio">
    <div class="section-head"><div><h2>Turmas encontradas</h2><p>A inativação é bloqueada enquanto houver alunos ativos vinculados.</p></div><span class="result-pill"><?= e((string) count($items)) ?> registro(s)</span></div>
    <div class="table-scroll">
        <table class="tabela-filtrada">
            <thead><tr><th>Turma</th><th>Ano letivo</th><th>Situação</th><th>Alunos ativos</th><th>Ações</th></tr></thead>
            <tbody>
            <?php if ($items === []): ?><tr><td colspan="5" class="empty-state">Nenhuma turma encontrada.</td></tr><?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><span class="turma-badge"><?= e((string) $item['nome_turma']) ?></span></td>
                    <td><span class="year-badge"><?= e($item['ano_letivo'] ? (string) $item['ano_letivo'] : 'Legado — preencher') ?></span></td>
                    <td><span class="badge-status <?= (int) $item['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $item['ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                    <td><?= e((string) $item['alunos_ativos']) ?></td>
                    <td><div class="action-group"><a class="btn-acao" href="<?= e(url('turma/editar/' . (int) $item['id'])) ?>">Editar</a><form class="inline-form" method="post" action="<?= e(url('turma/status/' . (int) $item['id'])) ?>" data-confirm-status="<?= (int) $item['ativo'] === 1 ? 'Inativar esta turma?' : 'Reativar esta turma?' ?>"><input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ativo" value="<?= (int) $item['ativo'] === 1 ? '0' : '1' ?>"><button class="btn-acao" type="submit"><?= (int) $item['ativo'] === 1 ? 'Inativar' : 'Reativar' ?></button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
