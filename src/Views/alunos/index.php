<?php
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
?>
<section class="page-hero">
    <div>
        <h2>Central de cadastro e acompanhamento dos alunos</h2>
        <p>Pesquise rapidamente por nome, revise a turma atual, abra o perfil completo e mantenha os dados de contato e DVA sempre atualizados.</p>
    </div>
    <div class="hero-stats" aria-label="Resumo da consulta">
        <span><strong><?= e((string) $result['total']) ?></strong> Total de alunos</span>
        <span><strong><?= e((string) $result['page']) ?></strong> página atual</span>
        <span><strong><?= e((string) $result['pages']) ?></strong> total de páginas</span>
    </div>
</section>

<div class="module-toolbar quick-actions">
    <div><h2>Ações rápidas</h2><p>Cadastre novos alunos ou use a busca para localizar um registro específico.</p></div>
    <div class="toolbar-actions">
        <a class="btn-primary" href="<?= e(url('aluno/criar')) ?>">+ Novo Aluno</a>
        <a class="btn-secondary" href="<?= e(url('dva')) ?>">Painel de DVAs</a>
        <?php if (Auth::isAdmin()): ?>
            <a class="btn-secondary" href="<?= e(url('turma')) ?>">Gerenciar turmas</a>
        <?php endif; ?>
    </div>
</div>

<section class="relatorio">
    <div class="section-head"><div><h2>Filtros</h2><p>Combine os critérios para localizar o cadastro necessário.</p></div></div>
    <form class="student-filters" method="get" action="<?= e(url('aluno')) ?>" role="search">
        <div>
            <label for="q">Nome</label>
            <input id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Pesquisar aluno">
        </div>
        <div>
            <label for="turma">Turma</label>
            <select id="turma" name="turma">
                <option value="">Todas</option>
                <?php foreach ($turmas as $class): ?>
                    <option value="<?= e((string) $class['id']) ?>" <?= (string) $class['id'] === $filters['turma'] ? 'selected' : '' ?>>
                        <?= e((string) $class['nome_turma']) ?><?= $class['ano_letivo'] ? ' — ' . e((string) $class['ano_letivo']) : '' ?><?= (int) $class['ativo'] === 0 ? ' (inativa)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="ativo">Situação</label>
            <select id="ativo" name="ativo">
                <option value="1" <?= $filters['ativo'] === '1' ? 'selected' : '' ?>>Ativos</option>
                <option value="0" <?= $filters['ativo'] === '0' ? 'selected' : '' ?>>Inativos</option>
                <option value="todos" <?= $filters['ativo'] === '' ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div>
            <label for="dva">DVA</label>
            <select id="dva" name="dva">
                <option value="">Todas</option>
                <?php foreach (DvaStatus::ALL as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn-primary" type="submit">Filtrar</button>
            <a class="btn-secondary" href="<?= e(url('aluno')) ?>">Limpar</a>
        </div>
    </form>
</section>

<section class="relatorio">
    <div class="section-head">
        <div><h2>Alunos Cadastrados</h2><p>Abra o perfil para detalhes completos ou entre em edição para atualizar dados rapidamente.</p></div>
        <span class="result-pill"><?= e((string) $result['total']) ?> registro(s)</span>
    </div>
    <div class="table-scroll">
        <table class="tabela-filtrada student-table">
            <thead><tr><th>Nome</th><th>Turma</th><th>Nascimento</th><th>Aluno</th><th>DVA</th><th>Vencimento</th><th>Ações</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?>
                <tr><td colspan="7" class="empty-state">Nenhum aluno encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td class="student-name"><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                        <td><span class="turma-badge"><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></span></td>
                        <td><?= e(date('d/m/Y', strtotime((string) $item['data_nascimento']))) ?></td>
                        <td><span class="badge-status <?= (int) $item['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $item['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                        <td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td>
                        <td><?= e($item['data_vencimento'] ? date('d/m/Y', strtotime((string) $item['data_vencimento'])) : '—') ?></td>
                        <td class="table-actions">
                            <a class="btn-acao" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>">Perfil</a>
                            <a class="btn-acao" href="<?= e(url('aluno/editar/' . (int) $item['id'])) ?>">Editar</a>
                            <?php if ((int) $item['ativo'] === 1): ?>
                                <a class="btn-acao" href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>"><?= $item['dva_id'] ? 'Renovar DVA' : 'Registrar DVA' ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Paginação de alunos">
            <?php for ($number = 1; $number <= $result['pages']; $number++): ?>
                <a class="<?= $number === $result['page'] ? 'active' : '' ?>" href="<?= e(url('aluno?' . http_build_query(array_merge($queryBase, ['page' => $number])))) ?>" <?= $number === $result['page'] ? 'aria-current="page"' : '' ?> aria-label="Página <?= e((string) $number) ?>"><?= e((string) $number) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
