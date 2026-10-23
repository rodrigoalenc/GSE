<?php $formErrors = $errors; $classField = $editing && ($classChangeLocked ?? false) ? 'turma_atual' : 'id_turma'; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['nome_completo' => ['nome com', 'nome completo'], 'data_nascimento' => ['nascimento'], $classField => ['turma'], 'data_vencimento' => ['vencimento', 'DVA inicial'], 'telefone_aluno' => ['telefone do aluno', 'Os telefones'], 'telefone_responsavel' => ['telefone do responsável', 'Os telefones']]); require __DIR__ . '/../partials/form-errors.php'; ?>
<?php $action = $editing ? 'aluno/editar/' . (int) $studentId : 'aluno/criar'; ?>


<?php if ($editing): ?>
    <section class="page-intro">
        <h2>Atualize dados pessoais e contatos</h2>
        <p>Revise os campos abaixo com atenção. Para alterar o vencimento da DVA, use “Acessar renovação de DVA” ao final do formulário.</p>
    </section>
<?php endif; ?>

<form class="student-form form-shell relatorio form-container" method="post" action="<?= e(url($action)) ?>">
    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($editing): ?><input type="hidden" name="revisao" value="<?= e((string) $data['revisao']) ?>"><?php endif; ?>
    <input type="hidden" name="_request_token" value="<?= e(bin2hex(random_bytes(16))) ?>">

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Dados Pessoais</h2></div>
            <?php if (!$editing): ?>
                <details class="dva-observation"<?= $data['observacao'] !== '' ? ' open' : '' ?>><summary>Observações DVA (opcional)</summary>
                    <label for="observacao">Observações DVA (opcional)</label>
                    <textarea id="observacao" name="observacao" maxlength="1000" rows="3"><?= e($data['observacao']) ?></textarea>
                    <p class="form-help">Deixe a data vazia para cadastrar o aluno sem DVA.</p>
                </details>
            <?php endif; ?>
        </div>
        <div class="form-grid identity-grid<?= !$editing ? ' has-initial-dva' : '' ?>">
            <div class="field-wide">
                <label for="nome_completo">Nome Completo:</label>
                <input id="nome_completo" name="nome_completo" value="<?= e($data['nome_completo']) ?>" maxlength="150" autocomplete="name" placeholder="Ex: Maria da Silva" required <?= UiPresentation::fieldAttributes($fieldErrors, 'nome_completo') ?>><?php if (isset($fieldErrors['nome_completo'])): ?><small class="field-error" id="nome_completo-error"><?= e(implode(' ', $fieldErrors['nome_completo'])) ?></small><?php endif; ?>
            </div>
            <div>
                <label for="data_nascimento">Data de Nascimento:</label>
                <input type="date" id="data_nascimento" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>" max="<?= e((new DateTimeImmutable('now', new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba'))))->format('Y-m-d')) ?>" required <?= UiPresentation::fieldAttributes($fieldErrors, 'data_nascimento') ?>><?php if (isset($fieldErrors['data_nascimento'])): ?><small class="field-error" id="data_nascimento-error"><?= e(implode(' ', $fieldErrors['data_nascimento'])) ?></small><?php endif; ?>
            </div>
            <div>
                <?php if ($editing && ($classChangeLocked ?? false)): ?>
                <label for="turma_atual">Turma atual:</label>
                <input id="turma_atual" value="<?= e((string) $currentClass['nome_turma']) ?><?= $currentClass['ano_letivo'] ? ' — ' . e((string) $currentClass['ano_letivo']) : '' ?><?= (int) $currentClass['turma_ativa'] === 0 ? ' (inativa)' : '' ?>" readonly aria-describedby="turma-movement-help<?= isset($fieldErrors['turma_atual']) ? ' turma_atual-error' : '' ?>"<?= isset($fieldErrors['turma_atual']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (isset($fieldErrors['turma_atual'])): ?><small class="field-error" id="turma_atual-error"><?= e(implode(' ', $fieldErrors['turma_atual'])) ?></small><?php endif; ?>
                <input type="hidden" name="id_turma" value="<?= e((string) $currentClass['id_turma']) ?>">
                <p class="form-help" id="turma-movement-help">Este aluno possui histórico de movimentação escolar. <?php if ($canManageMovement ?? false): ?>Para alterar a turma, use <a href="<?= e(url('aluno/movimentar')) ?>">Movimentar alunos</a> e confira a progressão ou regressão adequada.<?php else: ?>Para alterar a turma, solicite a um administrador a movimentação adequada.<?php endif; ?></p>
                <?php else: ?>
                <label for="id_turma">Turma:</label>
                <select id="id_turma" name="id_turma" required <?= UiPresentation::fieldAttributes($fieldErrors, 'id_turma') ?>>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $class): ?>
                        <option value="<?= e((string) $class['id']) ?>" <?= (string) $class['id'] === $data['id_turma'] ? 'selected' : '' ?>>
                            <?= e((string) $class['nome_turma']) ?><?= $class['ano_letivo'] ? ' — ' . e((string) $class['ano_letivo']) : '' ?><?= (int) $class['ativo'] === 0 ? ' (inativa; selecione outra)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select><?php if (isset($fieldErrors['id_turma'])): ?><small class="field-error" id="id_turma-error"><?= e(implode(' ', $fieldErrors['id_turma'])) ?></small><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!$editing): ?>
                <div class="box-dva"><label for="data_vencimento">📅 Vencimento DVA:</label><input type="date" id="data_vencimento" name="data_vencimento" value="<?= e($data['data_vencimento']) ?>" <?= UiPresentation::fieldAttributes($fieldErrors, 'data_vencimento') ?>><?php if (isset($fieldErrors['data_vencimento'])): ?><small class="field-error" id="data_vencimento-error"><?= e(implode(' ', $fieldErrors['data_vencimento'])) ?></small><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Contatos</h2></div></div>
        <div class="form-grid contact-grid">
            <div><label for="telefone_aluno">📱 WhatsApp / Celular do Aluno:</label><input id="telefone_aluno" name="telefone_aluno" value="<?= e($data['telefone_aluno']) ?>" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="(00) 00000-0000" <?= UiPresentation::fieldAttributes($fieldErrors, 'telefone_aluno') ?>><?php if (isset($fieldErrors['telefone_aluno'])): ?><small class="field-error" id="telefone_aluno-error"><?= e(implode(' ', $fieldErrors['telefone_aluno'])) ?></small><?php endif; ?></div>
            <div><label for="telefone_responsavel">👨‍👩‍👦 Telefone do Responsável:</label><input id="telefone_responsavel" name="telefone_responsavel" value="<?= e($data['telefone_responsavel']) ?>" maxlength="30" inputmode="tel" placeholder="(00) 00000-0000" <?= UiPresentation::fieldAttributes($fieldErrors, 'telefone_responsavel') ?>><?php if (isset($fieldErrors['telefone_responsavel'])): ?><small class="field-error" id="telefone_responsavel-error"><?= e(implode(' ', $fieldErrors['telefone_responsavel'])) ?></small><?php endif; ?></div>
        </div>
    </section>

    <?php if ($possibleDuplicate): ?>
        <section class="duplicate-confirmation" role="alert">
            <label for="confirmar_duplicidade">
                <input type="checkbox" id="confirmar_duplicidade" name="confirmar_duplicidade" value="1" required>
                Confirmo que este cadastro representa uma pessoa diferente, apesar dos dados coincidentes.
            </label>
        </section>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn-primary" type="submit"><?= $editing ? 'Salvar Alterações' : 'Salvar Cadastro' ?></button>
        <?php if ($editing): ?>
            <a class="btn-secondary" href="<?= e(url('aluno/dva/' . (int) $studentId)) ?>">Acessar renovação de DVA</a>
        <?php endif; ?>
        <a class="btn-secondary" href="<?= e(url($editing ? 'aluno/perfil/' . (int) $studentId : 'aluno')) ?>"><?= $editing ? 'Cancelar' : 'Cancelar e Voltar' ?></a>
    </div>
</form>
