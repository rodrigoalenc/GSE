<div class="usuario-form-card usuario-profile-card">
    <?php if ($erros): ?>
        <div class="error-message" role="alert">
            <strong>Revise os dados informados:</strong>
            <ul><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <section class="relatorio">
        <form class="usuario-form" method="post" action="<?= e(url('usuario/perfil')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <h3>Dados Pessoais</h3>
            <div class="usuario-form-grid">
                <div>
                    <label for="perfil-nome">Nome:</label>
                    <input id="perfil-nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="150" autocomplete="name" required>
                </div>
                <div>
                    <label for="perfil-email">E-mail:</label>
                    <input type="email" id="perfil-email" name="email" value="<?= e($dados['email']) ?>" maxlength="254" autocomplete="username" required aria-describedby="perfil-email-help">
                </div>
            </div>
            <p id="perfil-email-help" class="form-help">Ao alterar o e-mail, suas sessões serão encerradas. Entre novamente com o novo endereço.</p>
            <div class="usuario-profile-confirmation">
                <label for="perfil-senha-atual">Para salvar qualquer alteração, digite sua <strong>senha atual</strong>:</label>
                <input type="password" id="perfil-senha-atual" name="senha_atual" maxlength="128" autocomplete="current-password" required placeholder="Sua senha usada para entrar no sistema">
            </div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Salvar alterações</button>
                <a class="cancelar" href="<?= e(url('dashboard')) ?>">Cancelar</a>
                <a href="<?= e(url('senha/alterar')) ?>">Alterar senha</a>
            </div>
        </form>
    </section>
</div>
