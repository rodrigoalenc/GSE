<?php $formErrors = $errors; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['nome_turma' => ['nome da turma', 'nome com', 'nome e ano'], 'ano_letivo' => ['ano letivo', 'nome e ano']]); require __DIR__ . '/../partials/form-errors.php'; ?>


<section class="page-intro">
    <p class="hero-kicker"><?= $editing ? 'Atualização de turma' : 'Nova turma' ?></p>
    <h2><?= $editing ? 'Edite a identificação da turma' : 'Cadastre uma turma' ?></h2>
    <p>Use um nome para cada turma no mesmo ano letivo. Letras maiúsculas e minúsculas são consideradas iguais; mantenha os acentos.</p>
</section>

<section class="relatorio form-shell">
    <form class="student-form class-form" method="post" action="<?= e(url($editing ? 'turma/editar/' . (int) $classId : 'turma/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($editing): ?><input type="hidden" name="revisao" value="<?= e((string) $data['revisao']) ?>"><?php endif; ?>
        <div class="form-grid">
            <div><label for="nome_turma">Nome da turma</label><input id="nome_turma" name="nome_turma" value="<?= e($data['nome_turma']) ?>" maxlength="100" required <?= UiPresentation::fieldAttributes($fieldErrors, 'nome_turma') ?>><?php if (isset($fieldErrors['nome_turma'])): ?><small class="field-error" id="nome_turma-error"><?= e(implode(' ', $fieldErrors['nome_turma'])) ?></small><?php endif; ?></div>
            <div><label for="ano_letivo">Ano letivo</label><input type="number" id="ano_letivo" name="ano_letivo" value="<?= e($data['ano_letivo']) ?>" min="2000" max="<?= e((string) ((int) UiPresentation::localNow()->format('Y') + 5)) ?>" required <?= UiPresentation::fieldAttributes($fieldErrors, 'ano_letivo') ?>><?php if (isset($fieldErrors['ano_letivo'])): ?><small class="field-error" id="ano_letivo-error"><?= e(implode(' ', $fieldErrors['ano_letivo'])) ?></small><?php endif; ?></div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar turma' ?></button><a class="btn-secondary" href="<?= e(url('turma')) ?>">Cancelar</a></div>
    </form>
</section>
