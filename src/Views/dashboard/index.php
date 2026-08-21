<section class="dashboard-overview" aria-labelledby="dashboard-summary">
    <div class="section-head dashboard-heading">
        <div>
            <p class="hero-kicker">Visão operacional</p>
            <h2 id="dashboard-summary">Indicadores principais</h2>
            <p>Alunos e situações de DVA que exigem acompanhamento.</p>
        </div>
        <a class="btn-secondary" href="<?= e(url('dva')) ?>">Abrir central de DVAs</a>
    </div>
    <div class="stats-container dashboard-stats">
        <a class="stat-card total stat-link" href="<?= e(url('aluno?ativo=1')) ?>"><h3>Alunos ativos</h3><span class="stat-number"><?= e((string) $moduloDois['alunos_ativos']) ?></span></a>
        <a class="stat-card neutral stat-link" href="<?= e(url('aluno?ativo=0')) ?>"><h3>Alunos inativos</h3><span class="stat-number"><?= e((string) $moduloDois['alunos_inativos']) ?></span></a>
        <a class="stat-card neutral stat-link" href="<?= e(url('dva?dva=sem_dva')) ?>"><h3>Sem DVA</h3><span class="stat-number"><?= e((string) $moduloDois['sem_dva']) ?></span></a>
        <a class="stat-card danger stat-link" href="<?= e(url('dva?dva=vencida')) ?>"><h3>DVA vencida</h3><span class="stat-number"><?= e((string) $moduloDois['vencidas']) ?></span></a>
        <a class="stat-card pending stat-link" href="<?= e(url('dva?dva=vence_hoje')) ?>"><h3>Vence hoje</h3><span class="stat-number"><?= e((string) $moduloDois['vence_hoje']) ?></span></a>
        <a class="stat-card warning stat-link" href="<?= e(url('dva?dva=a_vencer')) ?>"><h3>A vencer</h3><span class="stat-number"><?= e((string) $moduloDois['a_vencer']) ?></span></a>
        <a class="stat-card success stat-link" href="<?= e(url('dva?dva=vigente')) ?>"><h3>Vigente</h3><span class="stat-number"><?= e((string) $moduloDois['vigentes']) ?></span></a>
    </div>
</section>

<section class="dashboard-grid">
    <article class="relatorio birthday-panel" aria-labelledby="birthday-title">
        <div class="section-head"><div><h2 id="birthday-title">Aniversariantes</h2><p>Datas importantes do mês.</p></div></div>
        <h3>Hoje</h3>
        <?php if ($aniversariantesHoje === []): ?><p class="empty-state">Nenhum aniversariante hoje.</p><?php else: ?><ul class="birthday-list"><?php foreach ($aniversariantesHoje as $item): ?><li><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a><small><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
        <h3>Próximos do mês</h3>
        <?php if ($aniversariantesMes === []): ?><p class="empty-state">Nenhum aniversariante restante neste mês.</p><?php else: ?><ul class="birthday-list"><?php foreach ($aniversariantesMes as $item): ?><li><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a><small><?= e(date('d/m', strtotime((string) $item['data_nascimento']))) ?> — <?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    </article>

    <article class="relatorio priority-panel">
        <div class="section-head"><div><h2>Pendências prioritárias</h2><p>Registros que exigem atenção primeiro.</p></div><a href="<?= e(url('dva')) ?>">Ver todas</a></div>
        <?php if ($pendencias === []): ?><p class="empty-state">Nenhuma pendência de DVA.</p><?php else: ?>
            <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Aluno</th><th>Turma</th><th>DVA</th></tr></thead><tbody>
                <?php foreach ($pendencias as $item): ?><tr><td><a class="student-link" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td><td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td><td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
</section>

<section class="relatorio module-one-summary">
    <div class="section-head"><div><h2>Segurança e controle de acesso</h2><p>Resumo secundário do Módulo 1 integrado ao painel.</p></div></div>
    <div class="security-metrics"><span><strong><?= e((string) $estatisticas['total']) ?></strong> contas cadastradas</span><span><strong><?= e((string) $estatisticas['ativos']) ?></strong> contas ativas</span><span><strong><?= e((string) $estatisticas['administradores']) ?></strong> administradores ativos</span></div>
</section>
