<div class="audit-info-box" role="note">
    <strong>ℹ️ Informação:</strong>
    <span>Aqui você vê as últimas 500 ações realizadas no sistema. Use a pesquisa para filtrar.</span>
</div>

<section class="relatorio audit-log-panel">
    <div class="toolbar-logs">
        <div class="search-group">
            <label for="filtroPainel">Pesquisar nos Logs:</label>
            <input type="search" id="filtroPainel" placeholder="Digite usuário, ação ou detalhe..." class="sistema" aria-controls="corpoLogs">
        </div>
        <form method="post" action="<?= e(url('auditoria')) ?>" data-confirm-status="<?= e($retentionDays === 365 ? 'Tem certeza que deseja apagar permanentemente os logs com mais de 1 ano?' : 'Tem certeza que deseja apagar permanentemente os logs com mais de ' . $retentionDays . ' dias?') ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="acao" value="limpar">
            <button type="submit" class="btn-secondary">🧹 Limpar Antigos (<?= e($retentionDays === 365 ? '> 1 ano' : '> ' . $retentionDays . ' dias') ?>)</button>
        </form>
    </div>

    <div class="table-scroll">
        <table class="tabela-filtrada tabela-logs">
            <thead>
                <tr><th>Data/Hora</th><th>Usuário</th><th>Ação</th><th>Detalhes</th></tr>
            </thead>
            <tbody id="corpoLogs">
                <?php if ($logs === []): ?>
                    <tr><td colspan="4" class="empty-state">Nenhum registro de log encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <?php $entry = AuditPresentation::logEntry($log); ?>
                    <tr class="item-filtrado">
                        <td class="col-data"><?= e($entry['date']) ?></td>
                        <td class="col-user filtro-user" title="Nome consultado no cadastro atual."><?= e($entry['user']) ?></td>
                        <td class="col-acao filtro-acao <?= e($entry['action_class']) ?>"><?= e($entry['action']) ?></td>
                        <td class="col-detalhe filtro-detalhe" title="Identificação dos registros conforme o cadastro atual."><?= e($entry['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr id="logs-sem-resultados" hidden><td colspan="4" class="empty-state">Nenhum registro de log encontrado.</td></tr>
            </tbody>
        </table>
    </div>
</section>
<script src="<?= e(url('assets/js/auditoria.js')) ?>" defer></script>
