<?php if ($errors !== []): ?><div class="error-message" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="relatorio dva-form-card">
    <h2><?= e((string) $student['nome_completo']) ?></h2>
    <?php if ($current): ?>
        <p>A DVA atual vence em <strong><?= e(date('d/m/Y', strtotime((string) $current['data_vencimento']))) ?></strong>. Ao renovar, ela será arquivada e permanecerá no histórico.</p>
    <?php else: ?><p>Este aluno ainda não possui DVA.</p><?php endif; ?>
    <?php if ((int) $student['ativo'] !== 1): ?><div class="warning-message" role="alert">O aluno está inativo. Reative-o antes de registrar uma DVA.</div><?php endif; ?>

    <form class="student-form" method="post" action="<?= e(url('aluno/dva/' . (int) $student['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="data_vencimento">Nova data de vencimento</label>
        <input type="date" id="data_vencimento" name="data_vencimento" value="<?= e($data['data_vencimento']) ?>" required>
        <p class="form-help">Datas já vencidas são aceitas para correção histórica e aparecerão como vencidas.</p>
        <label for="observacao">Observação opcional</label>
        <textarea id="observacao" name="observacao" maxlength="1000" rows="4"><?= e($data['observacao']) ?></textarea>
        <div class="form-actions"><button class="btn-primary" type="submit" <?= (int) $student['ativo'] !== 1 ? 'disabled' : '' ?>><?= $current ? 'Confirmar renovação' : 'Registrar DVA' ?></button><a class="btn-secondary" href="<?= e(url('aluno/perfil/' . (int) $student['id'])) ?>">Cancelar</a></div>
    </form>
</section>
