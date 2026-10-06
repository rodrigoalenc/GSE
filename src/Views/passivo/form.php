<?php if ($errors !== []): ?><div class="error-message" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($editing): ?><section class="page-intro passivo-edit-intro"><h2>Atualize os dados do registro com segurança</h2><p>Revise a localização física antes de alterar caixa ou posição. A numeração existente é preservada pela enumeração.</p></section><?php endif; ?>
<?php if (!empty($locationPending)): ?><div class="warning-message" role="status">Localização pendente. Você pode corrigir os demais dados e manter os campos históricos vazios. Informe caixa e posição para concluir a localização.</div><?php endif; ?>

<section class="relatorio passivo-form-shell<?= $editing ? ' passivo-edit-shell' : '' ?>">
    <form class="passivo-form" method="post" action="<?= e(url($editing ? 'passivo/editar/' . $recordId : 'passivo/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if (!$editing): ?><div class="passivo-form-tip">ℹ️ <strong>Dica:</strong> Use esta tela para adicionar um aluno avulso. Para muitos alunos, use a Importação CSV.</div><?php endif; ?>
        <?php if ($editing): ?><div class="passivo-edit-warning"><strong>Atenção:</strong> A ferramenta "Enumerar" preenche somente posições vazias, depois do maior número já usado na caixa, preservando o histórico.</div><?php endif; ?>
        <div class="passivo-form-grid">
            <div class="field-wide"><label for="nome-completo"><?= $editing ? 'Nome Completo:' : 'Nome Completo do Ex-Aluno:' ?></label><input id="nome-completo" name="nome_completo" maxlength="<?= e((string) Passivo::NAME_MAX_LENGTH) ?>" required value="<?= e($data['nome_completo']) ?>" autocomplete="name" placeholder="Nome completo sem abreviações"></div>
            <div><label for="data-nascimento"><?= $editing ? 'Data Nasc:' : 'Data de Nascimento:' ?></label><input id="data-nascimento" type="date" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>"></div>
            <div><label for="numero"><?= $editing ? 'Número:' : 'Número (Pasta):' ?></label><input id="numero" name="numero" maxlength="<?= e((string) Passivo::NUMBER_MAX_LENGTH) ?>" pattern="[\p{L}\p{N}][\p{L}\p{N} ._\/\-]*" <?= empty($locationPending) || trim((string) ($existingLocation['numero'] ?? '')) !== '' ? 'required' : '' ?> value="<?= e($data['numero']) ?>" placeholder="Ex: 123/2015"></div>
            <div><label class="passivo-box-label-field" for="caixa"><?= $editing ? 'Caixa:' : 'Caixa (Localização):' ?></label><input id="caixa" class="passivo-box-input" name="caixa" maxlength="<?= e((string) Passivo::BOX_MAX_LENGTH) ?>" pattern="[\p{L}\p{N}][\p{L}\p{N} ._\/\-]*" <?= empty($locationPending) || trim((string) ($existingLocation['caixa'] ?? '')) !== '' ? 'required' : '' ?> value="<?= e($data['caixa']) ?>" placeholder="Ex: CX-05"></div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Salvar Registro' ?></button><a class="cancelar" href="<?= e(url('passivo')) ?>">Cancelar</a></div>
    </form>
</section>
