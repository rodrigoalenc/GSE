<?php
$canViewHealth = $canViewHealth ?? AccessPolicy::allows('student.health');
$canViewContacts = $canViewContacts ?? AccessPolicy::allows('student.contacts');
$canManageStudent = $canManageStudent ?? AccessPolicy::allows('student.manage');
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
if ($filters['ativo'] === '') {
    $queryBase['ativo'] = 'todos';
}
$canArchiveBatch = Auth::isAdmin();
$archiveSelectionScope = url('aluno') . '|' . (string) ($_SESSION['usuario_id'] ?? 0);
$previewPhone = static function (mixed $value): string {
    $digits = (string) preg_replace('/\D/', '', (string) $value);
    return match (strlen($digits)) {
        11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
        10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
        default => 'Não informado',
    };
};
$previewData = static function (array $item) use ($canViewHealth, $canViewContacts, $previewPhone): array {
    $data = [
        'name' => (string) $item['nome_completo'],
        'birth' => UiPresentation::civilDate($item['data_nascimento']),
        'className' => (string) ($item['nome_turma'] ?: 'Sem turma'),
        'year' => (string) ($item['ano_letivo'] ?? ''),
        'profile' => url('aluno/perfil/' . (int) $item['id']),
    ];
    if ($canViewContacts) {
        $data['studentPhone'] = $previewPhone($item['telefone_aluno'] ?? '');
        $data['guardianPhone'] = $previewPhone($item['telefone_responsavel'] ?? '');
    }
    if ($canViewHealth) {
        $data['dvaStatus'] = (string) $item['dva_status'];
        $data['dvaLabel'] = DvaStatus::label((string) $item['dva_status']);
        $data['dvaDate'] = $item['data_vencimento'] ? 'Vencimento: ' . UiPresentation::civilDate($item['data_vencimento']) : 'Nenhuma declaração registrada.';
    }
    return $data;
};
$selectedPreview = $result['items'] !== [] ? $previewData($result['items'][0]) : null;
$firstResult = $result['total'] > 0 ? ((int) $result['page'] - 1) * (int) ($result['per_page'] ?? 15) + 1 : 0;
$lastResult = min((int) $result['total'], $firstResult + count($result['items']) - 1);
?>
<div class="students-workspace" data-student-workspace>
<div class="students-page-toolbar">
    <div class="students-page-context"><span class="students-count"><?= e((string) $result['total']) ?> alunos</span><p>Consulte os cadastros e acompanhe a documentação escolar.</p></div>
    <div class="toolbar-actions">
        <?php if ($canManageStudent): ?><a class="btn-primary" href="<?= e(url('aluno/criar')) ?>"><span aria-hidden="true">+</span> Novo aluno</a><?php endif; ?>
        <?php if (Auth::isAdmin()): ?><a class="btn-secondary" href="<?= e(url('aluno/importar')) ?>">Importar PDF</a><?php endif; ?>
        <?php if ($canViewHealth || Auth::isAdmin()): ?>
        <details class="students-more-actions"><summary>Mais ações</summary><div>
            <?php if ($canViewHealth): ?><a href="<?= e(url('dva')) ?>">Painel de DVAs</a><?php endif; ?>
            <?php if (Auth::isAdmin()): ?><a href="<?= e(url('aluno/movimentar')) ?>">Movimentar alunos</a><a href="<?= e(url('turma')) ?>">Gerenciar turmas</a><?php endif; ?>
        </div></details>
        <?php endif; ?>
    </div>
</div>

<div class="students-detail-layout">
<section class="relatorio students-list-card" aria-label="Lista de alunos">
    <form class="student-filters" method="post" action="<?= e(url('aluno')) ?>" role="search" data-list-state-form><?= list_state_fields() ?>
        <div>
            <label for="q">Buscar por nome</label>
            <input id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Nome do aluno">
        </div>
        <div>
            <label for="turma">Turma</label>
            <select id="turma" name="turma">
                <option value="">Todas as turmas</option>
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
        <?php if ($canViewHealth): ?>
        <div>
            <label for="dva">DVA</label>
            <select id="dva" name="dva">
                <option value="">Todas</option>
                <?php foreach (DvaStatus::ALL as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-actions">
            <button class="btn-primary" type="submit">Filtrar</button>
            <a class="btn-secondary" href="<?= e(url('aluno')) ?>">Limpar</a>
        </div>
    </form>
    <?php if ($canArchiveBatch): ?>
        <form id="student-archive-selection" class="student-batch-toolbar" method="post" action="<?= e(url('aluno/arquivar-lote/selecionar')) ?>" data-student-archive-selection data-selection-scope="<?= e($archiveSelectionScope) ?>" data-selection-limit="200">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="student-batch-summary">
                <strong>Ações em lote</strong>
                <span data-student-selection-summary role="status" aria-live="polite" aria-atomic="true">Selecione alunos para arquivar ou regredir.</span>
                <noscript><small>Sem JavaScript, selecione os alunos desta página e continue.</small></noscript>
            </div>
            <div class="student-batch-actions">
                <button class="btn-secondary" type="button" data-student-select-page disabled>Marcar página</button>
                <button class="btn-secondary" type="button" data-student-clear-selection disabled>Limpar seleção</button>
                <button class="btn-primary" type="submit" data-student-selection-submit>Escolher caixa e conferir</button>
                <button class="btn-secondary" type="submit" formaction="<?= e(url('aluno/regredir')) ?>" data-student-regress-submit hidden>Regredir aluno(s)</button>
                <noscript><button class="btn-secondary" type="submit" formaction="<?= e(url('aluno/regredir')) ?>">Regredir aluno(s)</button></noscript>
            </div>
            <div data-student-offpage-selection></div>
        </form>
    <?php endif; ?>
    <div class="table-scroll">
        <table class="tabela-filtrada student-table">
            <thead><tr><?php if ($canArchiveBatch): ?><th class="student-selection-cell" scope="col"><span class="visually-hidden">Selecionar</span></th><?php endif; ?><th scope="col">Aluno</th><th scope="col">Nascimento</th><th scope="col">Turma</th><th scope="col">Situação</th><?php if ($canViewHealth): ?><th scope="col">DVA</th><?php endif; ?><th scope="col">Ações</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?>
                <tr><td colspan="<?= 5 + ($canViewHealth ? 1 : 0) + ($canArchiveBatch ? 1 : 0) ?>" class="empty-state">Nenhum aluno encontrado. Tente ajustar os filtros da consulta.</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $index => $item): ?>
                    <tr class="<?= $index === 0 ? 'is-preview-selected' : '' ?>" data-student-preview-row data-student-preview="<?= e(json_encode($previewData($item), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>">
                        <?php if ($canArchiveBatch): ?><td class="student-selection-cell"><input type="checkbox" name="alunos[]" value="<?= e((string) $item['id']) ?>" form="student-archive-selection" data-student-selection-id aria-label="Selecionar <?= e((string) $item['nome_completo']) ?> para arquivar ou regredir" <?= (int) ($item['no_passivo'] ?? 0) === 1 ? 'disabled data-student-selection-unavailable' : '' ?>><?php if ((int) ($item['no_passivo'] ?? 0) === 1): ?><small class="student-selection-unavailable">Já no Arquivo Passivo</small><?php endif; ?></td><?php endif; ?>
                        <td class="student-name"><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>" data-student-preview-name aria-controls="student-detail-preview"<?= $index === 0 ? ' aria-current="true"' : '' ?>><?= e((string) $item['nome_completo']) ?></a></td>
                        <td><?= e(UiPresentation::civilDate($item['data_nascimento'])) ?></td>
                        <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
                        <td><span class="badge-status <?= (int) $item['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $item['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                        <?php if ($canViewHealth): ?>
                        <td><span class="dva-badge dva-<?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span></td>
                        <?php endif; ?>
                        <td class="table-actions">
                            <details class="student-row-actions"><summary aria-label="Ações para <?= e((string) $item['nome_completo']) ?>" title="Ações do aluno"><span aria-hidden="true">⋮</span><span class="visually-hidden">Ações</span></summary><div>
                            <a class="btn-acao" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>">Perfil</a>
                            <?php if ($canManageStudent): ?><a class="btn-acao" href="<?= e(url('aluno/editar/' . (int) $item['id'])) ?>">Editar</a><?php endif; ?>
                            <?php if ($canViewHealth && (int) $item['ativo'] === 1): ?>
                                <a class="btn-acao" href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>"><?= $item['dva_id'] ? 'Renovar DVA' : 'Registrar DVA' ?></a>
                            <?php endif; ?>
                            </div></details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="students-list-footer"><p class="result-summary">Exibindo <?= e((string) $firstResult) ?> a <?= e((string) max(0, $lastResult)) ?> de <?= e((string) $result['total']) ?> alunos</p>
    <?php if ($result['pages'] > 1): ?>
        <?php $renderPagination = require __DIR__ . '/../partials/pagination.php'; $renderPagination((int) $result['page'], (int) $result['pages'], static fn (int $p): string => url('aluno?' . http_build_query(array_merge($queryBase, ['page' => $p]))), 'Páginas de alunos'); ?>
    <?php endif; ?>
    </div>
</section>
<aside id="student-detail-preview" class="student-detail-preview" aria-labelledby="student-preview-title" data-student-preview-card>
    <div class="student-preview-heading"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg><h2 id="student-preview-title">Detalhes do aluno</h2></div>
    <?php if ($selectedPreview !== null): ?>
    <div class="student-preview-body">
        <p class="student-preview-hint">Uma visão rápida do cadastro selecionado.</p>
        <dl class="student-preview-fields">
            <div><dt>Nome</dt><dd data-preview-field="name"><?= e($selectedPreview['name']) ?></dd></div>
            <div><dt>Nascimento</dt><dd data-preview-field="birth"><?= e($selectedPreview['birth']) ?></dd></div>
            <div><dt>Turma</dt><dd data-preview-field="className"><?= e($selectedPreview['className']) ?></dd><dd class="student-preview-secondary" data-preview-field="year"><?= e($selectedPreview['year']) ?></dd></div>
            <?php if ($canViewContacts): ?>
            <div class="student-preview-contacts"><dt>Contatos</dt><dd><span>Aluno</span><span data-preview-field="studentPhone"><?= e($selectedPreview['studentPhone']) ?></span></dd><dd><span>Responsável</span><span data-preview-field="guardianPhone"><?= e($selectedPreview['guardianPhone']) ?></span></dd></div>
            <?php endif; ?>
            <?php if ($canViewHealth): ?>
            <div><dt>DVA</dt><dd><span class="dva-badge dva-<?= e($selectedPreview['dvaStatus']) ?>" data-preview-dva><?= e($selectedPreview['dvaLabel']) ?></span></dd><dd class="student-preview-secondary" data-preview-field="dvaDate"><?= e($selectedPreview['dvaDate']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <a class="btn-primary student-preview-profile" href="<?= e($selectedPreview['profile']) ?>" data-preview-profile>Abrir perfil <span aria-hidden="true">↗</span></a>
        <button type="button" class="student-preview-return" data-student-preview-return hidden>Voltar à lista</button>
        <noscript><p class="student-preview-hint">Abra o perfil na lista para consultar os demais alunos.</p></noscript>
    </div>
    <?php else: ?>
    <p class="student-preview-empty">Os detalhes serão exibidos aqui quando houver alunos na consulta.</p>
    <?php endif; ?>
</aside>
</div>
<p class="visually-hidden" data-student-preview-announcement role="status" aria-live="polite" aria-atomic="true"></p>
</div>
