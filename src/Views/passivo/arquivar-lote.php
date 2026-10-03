<?php
$hasBatchPreview = is_array($preview);
$selectedActiveCount = count(array_filter($students, static fn (array $student): bool => (int) $student['ativo'] === 1));
$boxMode = ($data['tipo_caixa'] ?? 'existente') === 'nova' ? 'nova' : 'existente';
$batchBirthDate = static function (?string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

    return $date !== false && $date->format('Y-m-d') === $value ? $date->format('d/m/Y') : 'Não informado';
};
?>
<section class="page-intro passivo-batch-intro">
    <p class="hero-kicker">Envio em lote</p>
    <h2>Enviar alunos ao Arquivo Passivo</h2>
    <p>Escolha uma caixa e confira a posição de cada aluno antes de confirmar. Os alunos ativos serão inativados; os cadastros e todo o histórico de DVA serão preservados.</p>
</section>

<?php if ($errors !== []): ?>
    <div class="error-message passivo-batch-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e((string) $error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="relatorio passivo-batch-shell" aria-labelledby="batch-selection-title">
    <div class="section-head"><div><h2 id="batch-selection-title">1. Alunos selecionados</h2><p><?= e((string) count($students)) ?> aluno(s) no lote; <?= e((string) $selectedActiveCount) ?> ativo(s) serão inativados na confirmação.</p></div><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar para a seleção</a></div>
    <div class="table-scroll">
        <table class="tabela-filtrada passivo-batch-table">
            <thead><tr><th scope="col">Aluno</th><th scope="col">Nascimento</th><th scope="col">Turma</th><th scope="col">Situação atual</th></tr></thead>
            <tbody>
                <?php if ($students === []): ?><tr><td colspan="4" class="empty-state">Nenhum aluno selecionado. Volte à lista para selecionar os alunos.</td></tr><?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <tr><td><?= e((string) $student['nome_completo']) ?></td><td><?= e($batchBirthDate($student['data_nascimento'])) ?></td><td><?= e((string) ($student['nome_turma'] ?: 'Sem turma')) ?></td><td><?= (int) $student['ativo'] === 1 ? 'Ativo — será inativado' : 'Inativo' ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="relatorio passivo-form-shell passivo-batch-box-shell" aria-labelledby="batch-box-title">
    <div class="section-head"><div><h2 id="batch-box-title">2. Escolher a caixa</h2><p>Os números serão atribuídos automaticamente depois da maior posição numérica já usada nesta caixa. Os números existentes permanecerão iguais.</p></div></div>
    <form class="passivo-form" method="post" action="<?= e(url('aluno/arquivar-lote/preview')) ?>" data-archive-batch-box>
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="selecao" value="<?= e($selectionToken) ?>">
        <div class="passivo-form-grid passivo-batch-box-grid">
            <div><label for="archive-batch-box-mode">Destino</label><select id="archive-batch-box-mode" name="tipo_caixa" data-archive-box-mode><option value="existente" <?= $boxMode === 'existente' ? 'selected' : '' ?>>Caixa existente</option><option value="nova" <?= $boxMode === 'nova' ? 'selected' : '' ?>>Nova caixa</option></select></div>
            <div data-archive-box-field="existente"><label for="archive-batch-existing-box">Caixa existente</label><select id="archive-batch-existing-box" name="caixa_existente" class="passivo-box-input"><option value="">Selecione uma caixa</option><?php foreach ($boxes as $box): ?><option value="<?= e((string) $box['caixa']) ?>" <?= (string) $box['caixa'] === (string) ($data['caixa_existente'] ?? '') ? 'selected' : '' ?>><?= e((string) $box['caixa']) ?></option><?php endforeach; ?></select><?php if ($boxes === []): ?><small>Nenhuma caixa cadastrada. Escolha “Nova caixa”.</small><?php endif; ?></div>
            <div data-archive-box-field="nova"><label for="archive-batch-new-box">Identificação da nova caixa</label><input id="archive-batch-new-box" name="caixa_nova" class="passivo-box-input" maxlength="<?= e((string) Passivo::BOX_MAX_LENGTH) ?>" value="<?= e((string) ($data['caixa_nova'] ?? '')) ?>" placeholder="Ex.: 25"></div>
        </div>
        <noscript><p class="passivo-form-tip">Preencha somente o campo correspondente ao destino escolhido.</p></noscript>
        <div class="form-actions"><button class="btn-primary" type="submit" <?= $students === [] ? 'disabled' : '' ?>><?= $hasBatchPreview ? 'Gerar outra prévia' : 'Gerar prévia do envio' ?></button><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Cancelar</a></div>
    </form>
</section>

<?php if ($hasBatchPreview): ?>
<section class="relatorio passivo-preview passivo-batch-shell" aria-labelledby="batch-preview-title">
    <div class="section-head"><div><h2 id="batch-preview-title">3. Conferir e confirmar o envio</h2><p>A prévia apenas mostra as posições. Os alunos serão enviados quando você confirmar abaixo.</p></div></div>
    <dl class="passivo-student-summary passivo-batch-preview-summary"><div><dt>Caixa de destino</dt><dd><?= e((string) $preview['caixa']) ?></dd></div><div><dt>Posições na prévia</dt><dd><?= e((string) $preview['primeiro_numero']) ?> a <?= e((string) $preview['ultimo_numero']) ?> — <?= e((string) $preview['total']) ?> aluno(s)</dd></div></dl>
    <div class="table-scroll">
        <table class="tabela-filtrada passivo-batch-table">
            <thead><tr><th scope="col">Posição</th><th scope="col">Aluno</th><th scope="col">Nascimento</th><th scope="col">Turma</th></tr></thead>
            <tbody><?php foreach ($preview['alunos'] as $student): ?><tr><td><strong><?= e((string) $student['numero']) ?></strong></td><td><?= e((string) $student['nome_completo']) ?></td><td><?= e($batchBirthDate($student['data_nascimento'])) ?></td><td><?= e((string) ($student['nome_turma'] ?: 'Sem turma')) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <form class="passivo-form passivo-batch-confirm-form" method="post" action="<?= e(url('aluno/arquivar-lote/confirmar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="selecao" value="<?= e($selectionToken) ?>">
        <input type="hidden" name="preview_token" value="<?= e((string) $previewToken) ?>">
        <label class="passivo-confirm"><input type="checkbox" name="confirmar" value="1" required> Confirmo a caixa e as posições exibidas e autorizo o envio do lote ao Arquivo Passivo, com a inativação dos alunos ativos e a preservação dos cadastros e das DVAs.</label>
        <div class="form-actions"><button class="btn-primary" type="submit">Confirmar envio de <?= e((string) $preview['total']) ?> aluno(s)</button><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Cancelar</a></div>
    </form>
</section>
<?php endif; ?>
