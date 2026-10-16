<section class="page-intro">
    <h2>Importar alunos pelo relatório de alocados</h2>
    <p>Envie o PDF original do SGDE. Somente alunos com situação <strong>Em curso</strong> entram na importação; Transferido e outras situações são ignoradas.</p>
</section>

<section class="relatorio form-shell">
    <div class="section-head"><div><h2>1. Selecionar relatório</h2><p>Até 10 MiB, 200 páginas e 5.000 alunos por arquivo.</p></div></div>
    <form class="student-form" method="post" enctype="multipart/form-data" action="<?= e(url('aluno/importar/preview')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
            <div class="field-wide">
                <label for="arquivo-pdf">Relatório de Alunos Alocados (PDF)</label>
                <input id="arquivo-pdf" type="file" name="arquivo_pdf" accept=".pdf,application/pdf" required>
                <p class="form-help">Use o arquivo exportado pelo SGDE, com texto selecionável.</p>
            </div>
            <div>
                <label for="ano-letivo">Ano letivo das turmas</label>
                <input id="ano-letivo" type="number" name="ano_letivo" min="2000" max="<?= e((string) ((int) gmdate('Y') + 5)) ?>" value="<?= e((string) $year) ?>" required>
                <p class="form-help">O relatório não informa o ano letivo. Confirme o ano da matrícula.</p>
            </div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit">Ler PDF e gerar prévia</button><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar aos alunos</a></div>
    </form>
</section>

<?php if ($preview !== null): ?>
<section class="relatorio" aria-labelledby="import-preview-title">
    <div class="section-head"><div><h2 id="import-preview-title">2. Conferir importação de <?= e((string) $preview['year']) ?></h2><p><?= e((string) $preview['total']) ?> alunos em <?= e((string) $preview['pages']) ?> página(s). A prévia expira em 15 minutos.</p></div></div>
    <div class="student-import-counts" aria-label="Resumo da importação">
        <span><strong><?= e((string) $preview['counts']['importar']) ?></strong> para importar</span>
        <span><strong><?= e((string) $preview['counts']['duplicado']) ?></strong> já cadastrados ou repetidos</span>
        <span><strong><?= e((string) $preview['counts']['revisar']) ?></strong> para revisão</span>
        <span><strong><?= e((string) $preview['ignored']) ?></strong> ignorados por situação</span>
    </div>
    <p><strong>Situações no relatório:</strong>
        <?php foreach ($preview['statuses'] as $status => $count): ?>
            <span><?= e((string) $status) ?>: <?= e((string) $count) ?>. </span>
        <?php endforeach; ?>
    </p>
    <?php if ($preview['classes_new'] !== []): ?>
        <p><strong>Turmas que serão criadas:</strong> <?= e(implode('; ', $preview['classes_new'])) ?> (<?= e((string) $preview['year']) ?>).</p>
    <?php endif; ?>
    <p class="form-help">A identificação usa nome e data de nascimento. Cadastros existentes mantêm seus dados e histórico. Turmas inativas e vínculos que precisam de conferência aparecem para revisão.</p>
    <div class="table-scroll student-import-table">
        <table class="tabela-filtrada student-table">
            <thead><tr><th>Aluno em curso</th><th>Nascimento</th><th>Turma</th><th>Resultado</th></tr></thead>
            <tbody>
                <?php foreach ($preview['items'] as $item): ?>
                <tr>
                    <td><?= e((string) $item['nome_completo']) ?></td>
                    <td><?= e(date('d/m/Y', strtotime((string) $item['data_nascimento']))) ?></td>
                    <td><?= e((string) $item['turma']) ?></td>
                    <td><strong><?= e(match ($item['action']) { 'importar' => 'Importar', 'duplicado' => 'Ignorar duplicidade', default => 'Revisar' }) ?></strong><br><?= e((string) $item['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview['items'] === []): ?><tr><td colspan="4" class="empty-state">Nenhum aluno em curso neste relatório.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($preview['counts']['importar'] > 0): ?>
    <form method="post" action="<?= e(url('aluno/importar/confirmar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="preview_token" value="<?= e((string) $preview['token']) ?>">
        <div class="form-actions"><button class="btn-primary" type="submit">Confirmar importação de <?= e((string) $preview['counts']['importar']) ?> aluno(s)</button></div>
    </form>
    <?php else: ?><p class="warning-message" role="status">Não há novos alunos aptos para importar nesta prévia.</p><?php endif; ?>
</section>
<?php endif; ?>
