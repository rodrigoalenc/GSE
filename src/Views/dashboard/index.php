<section class="stats-container" aria-label="Resumo do módulo de segurança">
    <article class="stat-card total">
        <h3>Contas cadastradas</h3>
        <span class="stat-number"><?= e((string) $estatisticas['total']) ?></span>
    </article>
    <article class="stat-card active-users">
        <h3>Contas ativas</h3>
        <span class="stat-number"><?= e((string) $estatisticas['ativos']) ?></span>
    </article>
    <article class="stat-card admins">
        <h3>Administradores ativos</h3>
        <span class="stat-number"><?= e((string) $estatisticas['administradores']) ?></span>
    </article>
    <article class="stat-card security-status">
        <h3>Módulo 1</h3>
        <span class="stat-label">Segurança ativa</span>
    </article>
</section>

<section class="relatorio module-notice">
    <h2>Autenticação e Controle de Usuários</h2>
    <p>Este ambiente está concentrado na administração segura de acessos. Os módulos acadêmicos serão disponibilizados em etapas futuras.</p>
</section>
