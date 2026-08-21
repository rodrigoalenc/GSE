<?php $action = $editing ? 'aluno/editar/' . (int) $studentId : 'aluno/criar'; ?>
<?php if ($errors !== []): ?>
    <div class="error-message" role="alert">
        <strong>Revise os dados informados:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<section class="page-intro">
    <p class="hero-kicker"><?= $editing ? 'Atualização cadastral' : 'Novo cadastro' ?></p>
    <h2><?= $editing ? 'Revise os dados do aluno' : 'Cadastre um novo aluno' ?></h2>
    <p>Campos marcados como obrigatórios são validados no servidor. O histórico de DVA é mantido em fluxo separado.</p>
</section>

<form class="student-form form-shell relatorio form-container" method="post" action="<?= e(url($action)) ?>">
    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Dados pessoais</h2><p>Identificação, nascimento e vínculo com uma turma ativa.</p></div></div>
        <div class="form-grid">
            <div class="field-wide">
                <label for="nome_completo">Nome completo</label>
                <input id="nome_completo" name="nome_completo" value="<?= e($data['nome_completo']) ?>" maxlength="150" autocomplete="name" required>
            </div>
            <div>
                <label for="data_nascimento">Data de nascimento</label>
                <input type="date" id="data_nascimento" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>" max="<?= e(gmdate('Y-m-d')) ?>" required>
            </div>
            <div>
                <label for="id_turma">Turma ativa</label>
                <select id="id_turma" name="id_turma" required>
                    <option value="">Selecione</option>
                    <?php foreach ($turmas as $class): ?>
                        <option value="<?= e((string) $class['id']) ?>" <?= (string) $class['id'] === $data['id_turma'] ? 'selected' : '' ?>>
                            <?= e((string) $class['nome_turma']) ?><?= $class['ano_letivo'] ? ' — ' . e((string) $class['ano_letivo']) : '' ?><?= (int) $class['ativo'] === 0 ? ' (inativa; selecione outra)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="form-section form-block">
        <div class="section-head"><div><h2>Contatos</h2><p>Telefones opcionais usados somente nos fluxos autorizados.</p></div></div>
        <div class="form-grid">
            <div><label for="telefone_aluno">Telefone do aluno</label><input id="telefone_aluno" name="telefone_aluno" value="<?= e($data['telefone_aluno']) ?>" maxlength="30" inputmode="tel" autocomplete="tel"></div>
            <div><label for="telefone_responsavel">Telefone do responsável</label><input id="telefone_responsavel" name="telefone_responsavel" value="<?= e($data['telefone_responsavel']) ?>" maxlength="30" inputmode="tel"></div>
        </div>
        <p class="form-help">Informe DDD e número. Serão armazenados somente os 10 ou 11 dígitos.</p>
    </section>

    <?php if (!$editing): ?>
        <section class="form-section form-block dva-initial-block">
            <div class="section-head"><div><h2>DVA inicial</h2><p>Registro opcional para iniciar o acompanhamento documental.</p></div></div>
            <div class="form-grid">
                <div><label for="data_vencimento">Data de vencimento</label><input type="date" id="data_vencimento" name="data_vencimento" value="<?= e($data['data_vencimento']) ?>"></div>
                <div class="field-wide"><label for="observacao">Observação</label><textarea id="observacao" name="observacao" maxlength="1000" rows="3"><?= e($data['observacao']) ?></textarea></div>
            </div>
            <p class="form-help">Deixe a data vazia para cadastrar o aluno sem DVA.</p>
        </section>
    <?php endif; ?>

    <?php if ($possibleDuplicate): ?>
        <section class="duplicate-confirmation" role="alert">
            <label for="confirmar_duplicidade">
                <input type="checkbox" id="confirmar_duplicidade" name="confirmar_duplicidade" value="1" required>
                Confirmo que este cadastro representa uma pessoa diferente, apesar dos dados coincidentes.
            </label>
        </section>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar aluno' ?></button>
        <?php if ($editing): ?>
            <a class="btn-secondary" href="<?= e(url('aluno/dva/' . (int) $studentId)) ?>">Acessar renovação de DVA</a>
        <?php endif; ?>
        <a class="btn-secondary" href="<?= e(url($editing ? 'aluno/perfil/' . (int) $studentId : 'aluno')) ?>">Cancelar</a>
    </div>
</form>
