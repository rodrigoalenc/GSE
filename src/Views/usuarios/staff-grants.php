<?php if ($edicao): ?>
    <input type="hidden" name="grants_revision" value="<?= e($grantsRevision) ?>">
<?php else: ?>
    <input type="hidden" name="grants_present" value="1">
<?php endif; ?>
<fieldset class="usuario-grants" data-staff-grants aria-describedby="grants-help">
    <legend>Permissões adicionais do funcionário</legend>
    <p id="grants-help">Para cada permissão concedida, informe a referência da aprovação e a data de validade. Desmarque para revogar.</p>
    <?php
    $permissionHelp = [
        'student.manage' => 'Inclui a consulta aos telefones do aluno e do responsável.',
        'student.export' => 'Inclui dados de DVA e exportação do Arquivo Passivo.',
        'orders.access' => 'Permite consultar e gerenciar pedidos, produtos e movimentos de estoque conforme o perfil.',
        'certificates.access' => 'Permite consultar e gerenciar certidões e acessar seus PDFs conforme o perfil.',
    ];
    foreach (AccessPolicy::STAFF_PERMISSIONS as $permission => $label):
        $fieldId = 'grant-' . (str_starts_with($permission, 'student.') ? substr($permission, strlen('student.')) : str_replace('.', '-', $permission));
        $fieldName = 'grants[' . $permission . ']';
        $grant = $dados['grants'][$permission] ?? null;
    ?>
        <div class="usuario-grant" data-staff-grant>
            <label for="<?= e($fieldId) ?>">
                <input type="checkbox" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>[enabled]" value="1"
                    aria-describedby="<?= e($fieldId) ?>-help" <?= $grant !== null ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
            <small id="<?= e($fieldId) ?>-help"><?= e($permissionHelp[$permission]) ?></small>
            <div class="usuario-grant-fields" data-staff-grant-fields>
                <div>
                    <label for="<?= e($fieldId) ?>-reference">Referência da aprovação</label>
                    <input type="text" id="<?= e($fieldId) ?>-reference" name="<?= e($fieldName) ?>[reference]"
                        maxlength="200" value="<?= e($grant['reference'] ?? '') ?>">
                </div>
                <div>
                    <label for="<?= e($fieldId) ?>-until">Válida até</label>
                    <input type="date" id="<?= e($fieldId) ?>-until" name="<?= e($fieldName) ?>[until]"
                        value="<?= e($grant['until'] ?? '') ?>">
                </div>
                <?php if ($grant !== null && $grant['until'] !== '' && $grant['until'] < gmdate('Y-m-d')): ?>
                    <small class="usuario-grant-expired">Validade expirada</small>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</fieldset>
