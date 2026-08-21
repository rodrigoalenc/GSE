<?php
$queryBase = array_filter($filters, static fn (string $value): bool => $value !== '');
?>
<section class="relatorio">
    <div class="section-head">
        <div><h2>Filtros de auditoria</h2><p>Consulte eventos imutáveis de segurança por ação, resultado, recurso e período.</p></div>
    </div>
    <form class="audit-filters" method="get" action="<?= e(url('auditoria')) ?>">
        <div>
            <label for="action">Ação</label>
            <input id="action" name="action" value="<?= e($filters['action']) ?>" maxlength="80" placeholder="Ex.: student.created">
        </div>
        <div>
            <label for="result">Resultado</label>
            <select id="result" name="result">
                <option value="">Todos</option>
                <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>Sucesso</option>
                <option value="failure" <?= $filters['result'] === 'failure' ? 'selected' : '' ?>>Falha</option>
                <option value="blocked" <?= $filters['result'] === 'blocked' ? 'selected' : '' ?>>Bloqueado</option>
            </select>
        </div>
        <div>
            <label for="resource_type">Tipo de recurso</label>
            <select id="resource_type" name="resource_type">
                <option value="">Todos</option>
                <option value="student" <?= $filters['resource_type'] === 'student' ? 'selected' : '' ?>>Aluno</option>
                <option value="dva" <?= $filters['resource_type'] === 'dva' ? 'selected' : '' ?>>DVA</option>
                <option value="class" <?= $filters['resource_type'] === 'class' ? 'selected' : '' ?>>Turma</option>
                <option value="user" <?= $filters['resource_type'] === 'user' ? 'selected' : '' ?>>Usuário</option>
            </select>
        </div>
        <div>
            <label for="from">De</label>
            <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
        </div>
        <div>
            <label for="to">Até</label>
            <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
        </div>
        <div class="audit-actions">
            <button class="btn-primary" type="submit">Filtrar</button>
            <a class="btn-secondary" href="<?= e(url('auditoria')) ?>">Limpar</a>
        </div>
    </form>
</section>

<section class="relatorio">
    <div class="section-head">
        <div><h2>Eventos registrados</h2><p>Horários apresentados em UTC para correlação operacional.</p></div>
        <span class="result-pill"><?= e((string) $result['total']) ?> registro(s)</span>
    </div>
    <div class="table-scroll">
        <table class="tabela-filtrada audit-table">
            <thead>
            <tr>
                <th>Data/hora UTC</th>
                <th>Ação</th>
                <th>Resultado</th>
                <th>Responsável</th>
                <th>Usuário afetado</th>
                <th>Recurso</th>
                <th>IP</th>
                <th>Requisição</th>
                <th>Descrição</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['items'] === []): ?>
                <tr><td colspan="9" class="empty-state">Nenhum evento encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td><?= e((string) $item['occurred_at']) ?></td>
                        <td><code><?= e((string) $item['action']) ?></code></td>
                        <td><span class="badge-status badge-audit-<?= e((string) $item['result']) ?>"><?= e((string) $item['result']) ?></span></td>
                        <td><?= e($item['actor_user_id'] === null ? '—' : '#' . (string) $item['actor_user_id']) ?></td>
                        <td><?= e($item['target_user_id'] === null ? '—' : '#' . (string) $item['target_user_id']) ?></td>
                        <td><?= e($item['resource_type'] === null ? '—' : (string) $item['resource_type'] . ' #' . (string) $item['resource_id']) ?></td>
                        <td><?= e((string) ($item['ip_address'] ?? '—')) ?></td>
                        <td><code><?= e(mb_substr((string) $item['request_id'], 0, 12)) ?>…</code></td>
                        <td><?= e((string) $item['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Paginação da auditoria">
            <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
                <?php $pageUrl = url('auditoria?' . http_build_query(array_merge($queryBase, ['page' => $page]))); ?>
                <a class="<?= $page === $result['page'] ? 'active' : '' ?>" href="<?= e($pageUrl) ?>" <?= $page === $result['page'] ? 'aria-current="page"' : '' ?>><?= e((string) $page) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
