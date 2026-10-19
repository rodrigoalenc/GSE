<div class="usuario-form-card">
    <section class="page-intro user-page-intro">
        <p class="hero-kicker">Segurança da conta</p>
        <h2><?= $obrigatoria ? 'Substitua sua senha temporária' : 'Defina uma nova senha' ?></h2>
        <p>A alteração encerra as sessões anteriores e exige uma nova autenticação.</p>
    </section>
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
            <h3>Alterar senha</h3>

            <label for="senha_atual">Senha atual:</label>
            <input type="password" id="senha_atual" name="senha_atual" data-password-max="128"
                autocomplete="current-password" required autofocus>

            <label for="nova_senha">Nova senha:</label>
            <div class="input-with-toggle">
                <input type="password" id="nova_senha" name="nova_senha" data-password-max="128" data-password-min="15"
                    autocomplete="new-password" required>
                <button class="toggle-password" type="button" data-password-toggle="nova_senha"
                    aria-label="Mostrar senha" title="Mostrar senha">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4" />
                    </svg>
                </button>
            </div>

            <label for="confirmar_senha">Confirmar nova senha:</label>
            <div class="input-with-toggle">
                <input type="password" id="confirmar_senha" name="confirmar_senha" data-password-max="128"
                    autocomplete="new-password" required>
                <button class="toggle-password" type="button" data-password-toggle="confirmar_senha"
                    aria-label="Mostrar senha" title="Mostrar senha">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4" />
                    </svg>
                </button>
            </div>

            <small class="form-help password-guidance">Use de 15 a 128 caracteres. Frases-senha, espaços e caracteres
                Unicode são aceitos. Evite senhas comuns e dados do seu nome ou e-mail.</small>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Alterar senha</button>
            </div>
        </form>
    </section>
</div>