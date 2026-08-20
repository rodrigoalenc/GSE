<?php
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
?>
<div class="module-toolbar">
    <div class="toolbar-actions">
        <a class="btn-primary" href="<?= e(url('aluno/criar')) ?>">+ Novo aluno</a>
        <a class="btn-secondary" href="<?= e(url('dva')) ?>">Painel de DVAs</a>
        <?php if (Auth::isAdmin()): ?>
            <a class="btn-secondary" href="<?= e(url('turma')) ?>">Gerenciar turmas</a>
        <?php endif; ?>
    </div>
</div>

<section class="relatorio">
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
    <p class="result-summary"><?= e((string) $result['total']) ?> aluno(s) encontrado(s).</p>
    <div class="table-scroll">
        <table class="tabela-filtrada student-table">
            <thead><tr><th>Nome</th><th>Turma</th><th>Nascimento</th><th>Aluno</th><th>DVA</th><th>Vencimento</th><th>Ações</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?>
                <tr><td colspan="7" class="empty-state">Nenhum aluno encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                        <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
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
                <a class="<?= $number === $result['page'] ? 'active' : '' ?>" href="<?= e(url('aluno?' . http_build_query(array_merge($queryBase, ['page' => $number])))) ?>" <?= $number === $result['page'] ? 'aria-current="page"' : '' ?>><?= e((string) $number) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
