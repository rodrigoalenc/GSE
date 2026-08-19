<div class="usuario-form-card">
    <?php if ($obrigatoria): ?>
        <div class="warning-message" role="status">
            Esta é uma senha temporária. Altere-a antes de acessar as demais áreas.
        </div>
    <?php endif; ?>

    <?php if ($erros): ?>
        <div class="error-message" role="alert">
            <strong>Não foi possível alterar a senha:</strong>
            <ul>
                <?php foreach ($erros as $erro): ?>
                    <li><?= e($erro) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="relatorio">
        <form class="usuario-form" method="post" action="<?= e(url('senha/alterar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

            <label for="senha_atual">Senha atual:</label>
            <input type="password" id="senha_atual" name="senha_atual" maxlength="128" autocomplete="current-password" required autofocus>

            <label for="nova_senha">Nova senha:</label>
            <input type="password" id="nova_senha" name="nova_senha" maxlength="128" autocomplete="new-password" required>

            <label for="confirmar_senha">Confirmar nova senha:</label>
            <input type="password" id="confirmar_senha" name="confirmar_senha" maxlength="128" autocomplete="new-password" required>

            <small class="form-help password-guidance">Use de 12 a 128 caracteres. Frases-senha, espaços e caracteres Unicode são aceitos. Evite senhas comuns e dados do seu nome ou e-mail.</small>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Alterar senha</button>
            </div>
        </form>
    </section>
</div>
