<?php $formErrors = $erros; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['perfil-nome' => ['Informe um nome'], 'perfil-email' => ['e-mail válido', 'e-mail informado'], 'perfil-senha-atual' => ['senha atual não confere']]); require __DIR__ . '/../partials/form-errors.php'; ?>
<div class="usuario-form-card usuario-profile-card">

    <section class="relatorio">
        <form class="usuario-form" method="post" action="<?= e(url('usuario/perfil')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <h3>Dados Pessoais</h3>
            <div class="usuario-form-grid">
                <div>
                    <label for="perfil-nome">Nome:</label>
                    <input id="perfil-nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="150" autocomplete="name" required <?= UiPresentation::fieldAttributes($fieldErrors, 'perfil-nome') ?>><?php if (isset($fieldErrors['perfil-nome'])): ?><small class="field-error" id="perfil-nome-error"><?= e(implode(' ', $fieldErrors['perfil-nome'])) ?></small><?php endif; ?>
                </div>
                <div>
                    <label for="perfil-email">E-mail:</label>
                    <input type="email" id="perfil-email" name="email" value="<?= e($dados['email']) ?>" maxlength="254" autocomplete="username" required aria-describedby="perfil-email-help<?= isset($fieldErrors['perfil-email']) ? ' perfil-email-error' : '' ?>"<?= isset($fieldErrors['perfil-email']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($fieldErrors['perfil-email'])): ?><small class="field-error" id="perfil-email-error"><?= e(implode(' ', $fieldErrors['perfil-email'])) ?></small><?php endif; ?>
                </div>
            </div>
            <p id="perfil-email-help" class="form-help">Ao alterar o e-mail, suas sessões serão encerradas. Entre novamente com o novo endereço.</p>
            <div class="usuario-profile-confirmation">
                <label for="perfil-senha-atual">Para salvar qualquer alteração, digite sua <strong>senha atual</strong>:</label>
                <input type="password" id="perfil-senha-atual" <?= UiPresentation::fieldAttributes($fieldErrors, 'perfil-senha-atual') ?> name="senha_atual" data-password-max="128" autocomplete="current-password" required placeholder="Sua senha usada para entrar no sistema"><?php if (isset($fieldErrors['perfil-senha-atual'])): ?><small class="field-error" id="perfil-senha-atual-error"><?= e(implode(' ', $fieldErrors['perfil-senha-atual'])) ?></small><?php endif; ?>
            </div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Salvar alterações</button>
                <a class="cancelar" href="<?= e(url('dashboard')) ?>">Cancelar</a>
                <a href="<?= e(url('senha/alterar')) ?>">Alterar senha</a>
            </div>
        </form>
    </section>
</div>
