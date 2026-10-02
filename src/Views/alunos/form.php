<?php $action = $editing ? 'aluno/editar/' . (int) $studentId : 'aluno/criar'; ?>
<?php if ($errors !== []): ?>
    <div class="error-message" role="alert">
        <strong>Revise os dados informados:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($editing): ?>
    <section class="page-intro">
        <h2>Atualize dados pessoais, contatos e vencimento da DVA</h2>
        <p>Revise os campos abaixo com atenção. O histórico documental do aluno permanece preservado.</p>
    </section>
<?php endif; ?>

<form class="student-form form-shell relatorio form-container" method="post" action="<?= e(url($action)) ?>">
    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Dados Pessoais</h2></div></div>
        <div class="form-grid identity-grid<?= !$editing ? ' has-initial-dva' : '' ?>">
            <div class="field-wide">
                <label for="nome_completo">Nome Completo:</label>
                <input id="nome_completo" name="nome_completo" value="<?= e($data['nome_completo']) ?>" maxlength="150" autocomplete="name" placeholder="Ex: Maria da Silva" required>
            </div>
            <div>
                <label for="data_nascimento">Data de Nascimento:</label>
                <input type="date" id="data_nascimento" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>" max="<?= e(gmdate('Y-m-d')) ?>" required>
            </div>
            <div>
                <label for="id_turma">Turma:</label>
                <select id="id_turma" name="id_turma" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $class): ?>
                        <option value="<?= e((string) $class['id']) ?>" <?= (string) $class['id'] === $data['id_turma'] ? 'selected' : '' ?>>
                            <?= e((string) $class['nome_turma']) ?><?= $class['ano_letivo'] ? ' — ' . e((string) $class['ano_letivo']) : '' ?><?= (int) $class['ativo'] === 0 ? ' (inativa; selecione outra)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$editing): ?>
                <div class="box-dva"><label for="data_vencimento">📅 Vencimento DVA:</label><input type="date" id="data_vencimento" name="data_vencimento" value="<?= e($data['data_vencimento']) ?>"></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$editing): ?>
        <details class="form-section form-block dva-observation"<?= $data['observacao'] !== '' ? ' open' : '' ?>><summary>Observações DVA (opcional)</summary>
            <label for="observacao">Observações DVA (opcional)</label>
            <textarea id="observacao" name="observacao" maxlength="1000" rows="3"><?= e($data['observacao']) ?></textarea>
            <p class="form-help">Deixe a data vazia para cadastrar o aluno sem DVA.</p>
        </details>
    <?php endif; ?>

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Contatos</h2></div></div>
        <div class="form-grid">
            <div><label for="telefone_aluno">📱 WhatsApp / Celular do Aluno:</label><input id="telefone_aluno" name="telefone_aluno" value="<?= e($data['telefone_aluno']) ?>" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="(00) 00000-0000"></div>
            <div><label for="telefone_responsavel">👨‍👩‍👦 Telefone do Responsável:</label><input id="telefone_responsavel" name="telefone_responsavel" value="<?= e($data['telefone_responsavel']) ?>" maxlength="30" inputmode="tel" placeholder="(00) 00000-0000"></div>
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
