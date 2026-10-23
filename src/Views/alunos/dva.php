<?php $formErrors = $errors; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['data_vencimento' => ['vencimento', 'data inválida', 'data válida'], 'observacao' => ['observação']]); require __DIR__ . '/../partials/form-errors.php'; ?>
<?php
$currentDate = $current ? UiPresentation::civilDate($current['data_vencimento']) : null;
$currentTiming = match ($currentStatus) {
    DvaStatus::VENCIDA => 'Venceu há ' . abs((int) $currentDays) . ' dia(s) (' . $currentDate . ').',
    DvaStatus::VENCE_HOJE => 'Vence hoje (' . $currentDate . ').',
    DvaStatus::A_VENCER => 'Vence em ' . (int) $currentDays . ' dia(s) (' . $currentDate . ').',
    DvaStatus::VIGENTE => 'Vence em ' . $currentDate . '.',
    default => 'O primeiro registro iniciará o histórico.',
};
?>


<section class="page-intro">
    <p class="hero-kicker">Histórico documental</p>
    <h2><?= e((string) $student['nome_completo']) ?></h2>
    <p><?= $current ? 'A nova DVA substituirá a vigente sem apagar a versão anterior.' : 'Registre a primeira DVA deste aluno.' ?></p>
</section>

<section class="relatorio dva-form-card form-shell">
    <div class="status-box dva-status-large dva-<?= e((string) $currentStatus) ?>">
        <span class="dva-badge dva-<?= e((string) $currentStatus) ?>"><?= e(DvaStatus::label((string) $currentStatus)) ?></span>
        <?php if ($current): ?>
            <h2>DVA atual: <?= e(DvaStatus::label((string) $currentStatus)) ?></h2>
            <p><?= e($currentTiming) ?></p>
        <?php else: ?>
            <h2>Sem DVA atual</h2><p>O primeiro registro iniciará o histórico.</p>
        <?php endif; ?>
    </div>

    <?php if ((int) $student['ativo'] !== 1): ?>
        <div class="warning-message" role="alert">O aluno está inativo. Reative-o antes de registrar uma DVA.</div>
    <?php endif; ?>

    <form class="student-form" method="post" action="<?= e(url('aluno/dva/' . (int) $student['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-section-inner">
            <label for="data_vencimento">Nova data de vencimento</label>
            <input type="date" id="data_vencimento" name="data_vencimento" value="<?= e($data['data_vencimento']) ?>" required <?= UiPresentation::fieldAttributes($fieldErrors, 'data_vencimento') ?>><?php if (isset($fieldErrors['data_vencimento'])): ?><small class="field-error" id="data_vencimento-error"><?= e(implode(' ', $fieldErrors['data_vencimento'])) ?></small><?php endif; ?>
            <p class="form-help">Datas passadas são aceitas para correção histórica e serão identificadas como vencidas.</p>
        </div>
        <div class="form-section-inner">
            <label for="observacao">Observação opcional</label>
            <textarea id="observacao" name="observacao" maxlength="1000" rows="5" <?= UiPresentation::fieldAttributes($fieldErrors, 'observacao') ?>><?= e($data['observacao']) ?></textarea><?php if (isset($fieldErrors['observacao'])): ?><small class="field-error" id="observacao-error"><?= e(implode(' ', $fieldErrors['observacao'])) ?></small><?php endif; ?>
        </div>
        <div class="form-actions">
            <button class="btn-primary" type="submit" <?= (int) $student['ativo'] !== 1 ? 'disabled' : '' ?>><?= $current ? 'Confirmar renovação' : 'Registrar DVA' ?></button>
            <a class="btn-secondary" href="<?= e(url('aluno/perfil/' . (int) $student['id'])) ?>">Cancelar</a>
        </div>
    </form>
</section>
