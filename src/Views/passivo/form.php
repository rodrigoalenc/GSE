<?php $formErrors = $errors; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['nome-completo' => ['nome com'], 'data-nascimento' => ['data real', 'nascimento'], 'numero' => ['posição'], 'caixa' => ['caixa']]); require __DIR__ . '/../partials/form-errors.php'; ?>

<?php if ($editing): ?><section class="page-intro passivo-edit-intro"><h2>Atualize os dados do registro com segurança</h2><p>Revise a localização física antes de alterar caixa ou posição. Os dados devem corresponder à pasta guardada na escola.</p></section><?php endif; ?>
<?php if (!empty($locationPending)): ?><div class="warning-message" role="status">Localização pendente. Você pode corrigir os demais dados e manter os campos históricos vazios. Informe caixa e posição para concluir a localização.</div><?php endif; ?>

<section class="relatorio passivo-form-shell<?= $editing ? ' passivo-edit-shell' : '' ?>">
    <form class="passivo-form" method="post" action="<?= e(url($editing ? 'passivo/editar/' . $recordId : 'passivo/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($editing): ?><input type="hidden" name="revisao" value="<?= e((string) $data['revisao']) ?>"><?php endif; ?>
    <input type="hidden" name="_request_token" value="<?= e(bin2hex(random_bytes(16))) ?>">
        <?php if (!$editing): ?><div class="passivo-form-tip">ℹ️ <strong>Dica:</strong> Use esta tela para adicionar um aluno avulso. Para muitos alunos, use a Importação CSV.</div><?php endif; ?>

        <div class="passivo-form-grid">
            <div class="field-wide"><label for="nome-completo"><?= $editing ? 'Nome Completo:' : 'Nome Completo do Ex-Aluno:' ?></label><input id="nome-completo" name="nome_completo" maxlength="<?= e((string) Passivo::NAME_MAX_LENGTH) ?>" required value="<?= e($data['nome_completo']) ?>" autocomplete="name" placeholder="Nome completo sem abreviações" <?= UiPresentation::fieldAttributes($fieldErrors, 'nome-completo') ?>><?php if (isset($fieldErrors['nome-completo'])): ?><small class="field-error" id="nome-completo-error"><?= e(implode(' ', $fieldErrors['nome-completo'])) ?></small><?php endif; ?></div>
            <div><label for="data-nascimento"><?= $editing ? 'Data Nasc:' : 'Data de Nascimento:' ?></label><input id="data-nascimento" type="date" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>" <?= UiPresentation::fieldAttributes($fieldErrors, 'data-nascimento') ?>><?php if (isset($fieldErrors['data-nascimento'])): ?><small class="field-error" id="data-nascimento-error"><?= e(implode(' ', $fieldErrors['data-nascimento'])) ?></small><?php endif; ?></div>
            <div><label for="numero"><?= $editing ? 'Número:' : 'Número (Pasta):' ?></label><input id="numero" name="numero" maxlength="<?= e((string) Passivo::NUMBER_MAX_LENGTH) ?>" pattern="[\p{L}\p{N}][\p{L}\p{N} ._\/\-]*" <?= empty($locationPending) || trim((string) ($existingLocation['numero'] ?? '')) !== '' ? 'required' : '' ?> value="<?= e($data['numero']) ?>" placeholder="Ex: 123/2015" <?= UiPresentation::fieldAttributes($fieldErrors, 'numero') ?>><?php if (isset($fieldErrors['numero'])): ?><small class="field-error" id="numero-error"><?= e(implode(' ', $fieldErrors['numero'])) ?></small><?php endif; ?></div>
            <div><label class="passivo-box-label-field" for="caixa"><?= $editing ? 'Caixa:' : 'Caixa (Localização):' ?></label><input id="caixa" class="passivo-box-input" name="caixa" maxlength="<?= e((string) Passivo::BOX_MAX_LENGTH) ?>" pattern="[\p{L}\p{N}][\p{L}\p{N} ._\/\-]*" <?= empty($locationPending) || trim((string) ($existingLocation['caixa'] ?? '')) !== '' ? 'required' : '' ?> value="<?= e($data['caixa']) ?>" placeholder="Ex: CX-05" <?= UiPresentation::fieldAttributes($fieldErrors, 'caixa') ?>><?php if (isset($fieldErrors['caixa'])): ?><small class="field-error" id="caixa-error"><?= e(implode(' ', $fieldErrors['caixa'])) ?></small><?php endif; ?></div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Salvar Registro' ?></button><a class="cancelar" href="<?= e(url('passivo')) ?>">Cancelar</a></div>
    </form>
</section>
