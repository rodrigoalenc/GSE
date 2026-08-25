<section class="passivo-hero" aria-labelledby="passivo-summary-title">
    <div>
        <p class="hero-kicker">Acervo fisico institucional</p>
        <h2 id="passivo-summary-title">Consulta central de caixas e pastas</h2>
        <p>Localize ex-alunos por nome, numero ou caixa sem perder o historico do cadastro.</p>
    </div>
    <div class="passivo-stats" aria-label="Resumo do Arquivo Passivo">
        <span><strong><?= e((string) $summary['caixas']) ?></strong> caixas</span>
        <span><strong><?= e((string) $summary['registros']) ?></strong> registros ativos</span>
        <span><strong><?= e((string) $summary['pendentes']) ?></strong> localizacoes pendentes</span>
    </div>
</section>

<section class="relatorio passivo-toolbar" aria-labelledby="passivo-search-title">
    <div class="section-head">
        <div><h2 id="passivo-search-title">Busca e navegacao</h2><p>Os caracteres %, _ e barra invertida sao pesquisados literalmente.</p></div>
        <span class="result-pill"><?= e((string) $result['total']) ?> resultado(s)</span>
    </div>
    <form class="passivo-filters" method="get" action="<?= e(url('passivo')) ?>">
        <div class="field-wide">
            <label for="passivo-q">Nome ou numero</label>
            <input id="passivo-q" type="search" name="q" maxlength="<?= e((string) Passivo::SEARCH_MAX_LENGTH) ?>" value="<?= e($filters['q']) ?>" placeholder="Ex.: Jose da Silva ou 127">
        </div>
        <div>
            <label for="passivo-caixa">Caixa</label>
            <select id="passivo-caixa" name="caixa">
                <option value="">Todas as caixas</option>
                <?php foreach ($boxes as $box): ?>
                    <option value="<?= e($box['caixa']) ?>" <?= $filters['caixa'] === $box['caixa'] ? 'selected' : '' ?>><?= e($box['caixa']) ?> (<?= e((string) $box['total']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="passivo-ordem">Ordenar por</label>
            <select id="passivo-ordem" name="ordem">
                <option value="nome" <?= $filters['ordem'] === 'nome' ? 'selected' : '' ?>>Nome</option>
                <option value="numero" <?= $filters['ordem'] === 'numero' ? 'selected' : '' ?>>Numero</option>
                <option value="caixa" <?= $filters['ordem'] === 'caixa' ? 'selected' : '' ?>>Caixa</option>
                <option value="recente" <?= $filters['ordem'] === 'recente' ? 'selected' : '' ?>>Mais recentes</option>
            </select>
        </div>
        <input type="hidden" name="ativo" value="<?= e($filters['ativo'] === '' ? 'todos' : $filters['ativo']) ?>">
        <div class="filter-actions">
            <button class="btn-primary" type="submit">Buscar</button>
            <a class="btn-secondary" href="<?= e(url('passivo')) ?>">Limpar</a>
        </div>
    </form>
    <div class="passivo-toolbar-actions">
        <a class="btn-primary" href="<?= e(url('passivo/criar')) ?>">Novo registro</a>
        <?php if ($canAdminister): ?>
            <a class="btn-secondary" href="<?= e(url('passivo/ferramentas')) ?>">Ferramentas</a>
            <a class="btn-secondary" href="<?= e(url('passivo/importar')) ?>">Importar CSV</a>
            <a class="btn-secondary" href="<?= e(url('passivo/inativos')) ?>">Ver inativos (<?= e((string) $summary['inativos']) ?>)</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($filters['q'] === '' && $filters['caixa'] === '' && $filters['ativo'] === '1'): ?>
<section class="relatorio" aria-labelledby="passivo-boxes-title">
    <div class="section-head"><div><h2 id="passivo-boxes-title">Caixas do acervo</h2><p>Abra uma caixa para consultar seu conteudo fisico.</p></div></div>
    <?php if ($boxes === []): ?>
        <p class="empty-state">Nenhuma caixa cadastrada.</p>
    <?php else: ?>
        <div class="passivo-box-grid">
            <?php foreach ($boxes as $box): ?>
                <a class="passivo-box-card" href="<?= e(url('passivo?caixa=' . rawurlencode($box['caixa']) . '&ordem=numero')) ?>">
                    <span class="passivo-box-label">Caixa</span>
                    <strong><?= e($box['caixa']) ?></strong>
                    <small><?= e((string) $box['total']) ?> aluno(s)</small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($filters['caixa'] !== '' && $navigation['lista'] !== []): ?>
<nav class="passivo-box-navigation" aria-label="Navegacao entre caixas">
    <?php if ($navigation['anterior'] !== null): ?><a href="<?= e(url('passivo?caixa=' . rawurlencode($navigation['anterior']) . '&ordem=numero')) ?>">Caixa anterior</a><?php endif; ?>
    <?php foreach ($navigation['lista'] as $box): ?><a class="<?= $box === $filters['caixa'] ? 'active' : '' ?>" <?= $box === $filters['caixa'] ? 'aria-current="page"' : '' ?> href="<?= e(url('passivo?caixa=' . rawurlencode($box) . '&ordem=numero')) ?>"><?= e($box) ?></a><?php endforeach; ?>
    <?php if ($navigation['proxima'] !== null): ?><a href="<?= e(url('passivo?caixa=' . rawurlencode($navigation['proxima']) . '&ordem=numero')) ?>">Proxima caixa</a><?php endif; ?>
</nav>
<?php endif; ?>

<section class="relatorio" aria-labelledby="passivo-results-title">
    <div class="section-head"><div><h2 id="passivo-results-title"><?= $filters['ativo'] === '0' ? 'Registros inativos' : ($filters['caixa'] !== '' ? 'Conteudo da caixa ' . e($filters['caixa']) : 'Registros localizados') ?></h2><p>Ordenacao deterministica e paginacao executadas no banco.</p></div></div>
    <div class="table-scroll">
        <table class="tabela-filtrada passivo-table">
            <thead><tr><th>Nome</th><th>Data de nascimento</th><th>Numero</th><th>Caixa</th><th>Situacao</th><th>Acoes</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?><tr><td colspan="6" class="empty-state">Nenhum registro encontrado.</td></tr><?php endif; ?>
            <?php foreach ($result['items'] as $record): ?>
                <?php $birthTimestamp = $record['data_nascimento'] ? strtotime((string) $record['data_nascimento']) : false; ?>
                <tr>
                    <td><a class="passivo-name" href="<?= e(url('passivo/detalhes/' . (int) $record['id'])) ?>"><?= e((string) $record['nome_completo']) ?></a></td>
                    <td><?= $birthTimestamp !== false ? e(date('d/m/Y', $birthTimestamp)) : ($record['data_nascimento'] ? e((string) $record['data_nascimento']) : '&mdash;') ?></td>
                    <td><?= e((string) ($record['numero'] ?: 'Sem numero')) ?></td>
                    <td><span class="passivo-box-badge"><?= e((string) ($record['caixa'] ?: 'Pendente')) ?></span></td>
                    <td><span class="passivo-status <?= (int) $record['ativo'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $record['ativo'] === 1 ? ((int) $record['localizacao_pendente'] === 1 ? 'Revisao pendente' : 'Ativo') : 'Inativo' ?></span></td>
                    <td><div class="passivo-row-actions"><a href="<?= e(url('passivo/detalhes/' . (int) $record['id'])) ?>">Detalhes</a><a href="<?= e(url('passivo/editar/' . (int) $record['id'])) ?>">Editar</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Paginacao do Arquivo Passivo">
        <?php for ($page = max(1, $result['page'] - 2); $page <= min($result['pages'], $result['page'] + 2); $page++): ?>
            <?php $query = http_build_query(['q' => $filters['q'], 'caixa' => $filters['caixa'], 'ativo' => $filters['ativo'] === '' ? 'todos' : $filters['ativo'], 'ordem' => $filters['ordem'], 'page' => $page]); ?>
            <a class="<?= $page === $result['page'] ? 'active' : '' ?>" <?= $page === $result['page'] ? 'aria-current="page"' : '' ?> href="<?= e(url('passivo?' . $query)) ?>"><?= e((string) $page) ?></a>
        <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
