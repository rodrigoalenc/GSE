<div class="audit-info-box" role="note">
    <strong>Backup do banco de dados:</strong>
    <span>Esta cópia contém somente os registros do banco de dados. Os PDFs devem ser incluídos separadamente no backup completo, conforme o procedimento de manutenção.</span>
</div>

<?php if ($erros): ?>
    <div class="error-message" role="alert">
        <?php foreach ($erros as $erro): ?><p><?= e($erro) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="relatorio usuario-profile-card">
    <form id="backup-create-form" class="usuario-form" method="post" action="<?= e(url('backup/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <h3>Criar backup do banco</h3>
        <label for="backup-senha-atual">Confirme sua senha atual:</label>
        <input type="password" id="backup-senha-atual" name="senha_atual" autocomplete="current-password" maxlength="128" required>
        <div class="form-actions">
            <button class="btn-primary" type="submit">Criar backup</button>
        </div>
    </form>
</section>

<section class="relatorio audit-report">
    <h2>Backups manuais disponíveis</h2>
    <p class="audit-results-meta">Até 200 backups mais recentes · <?= e((string) $result['total']) ?> disponível(is) · Horários em UTC.</p>
    <div class="table-scroll">
        <table class="tabela-filtrada">
            <thead><tr><th>Arquivo</th><th>Data/hora UTC</th><th>Tamanho</th><th>Ação</th></tr></thead>
            <tbody>
            <?php if ($result['items'] === []): ?>
                <tr><td colspan="4" class="empty-state">Nenhum backup manual disponível.</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td class="text-break"><?= e($item['nome']) ?></td>
                        <td><?= e(gmdate('d/m/Y H:i:s', $item['modificado'])) ?></td>
                        <td><?= e(number_format($item['bytes'], 0, ',', '.')) ?> bytes</td>
                        <td><a class="btn-secondary" href="<?= e(url('backup/baixar/' . rawurlencode($item['nome']))) ?>">Baixar</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Paginação dos backups">
            <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
                <a class="<?= $page === $result['page'] ? 'active' : '' ?>" href="<?= e(url('backup?page=' . $page)) ?>" <?= $page === $result['page'] ? 'aria-current="page"' : '' ?>><?= e((string) $page) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
