<?php
$title = $title ?? 'Login';
$email = $email ?? '';
$erro = $erro ?? null;
$flash = $flash ?? null;
$flashClass = ($flash['tipo'] ?? '') === 'success' ? 'success' : 'warning';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acessar secretaria - GSE</title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/image/logo_escola.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/login.css')) ?>">
</head>
<body class="login-container">
<main class="login-shell">
    <section class="login-branding" aria-labelledby="brand-heading">
        <div class="login-brand">
            <img src="<?= e(url('assets/image/logo_escola.png')) ?>" alt="Brasão da E.E. São José" width="96" height="96">
            <p class="login-brand-name">GSE</p>
            <p class="login-brand-description">Gestor de Secretaria Escolar</p>
        </div>
        <div class="login-brand-message">
            <span class="login-brand-rule" aria-hidden="true"></span>
            <h1 id="brand-heading">A secretaria,<br> mais organizada.</h1>
            <p>Sistema de gestão da secretaria da<br>E.E. São José.</p>
        </div>
        <p class="login-brand-footer">Organização e cuidado com a vida escolar.</p>
    </section>
    <section class="login-form-panel" aria-labelledby="login-title">
    <form action="<?= e(url('login/entrar')) ?>" method="post" class="login" aria-labelledby="login-title">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

        <p class="login-school">E.E. São José</p>
        <h2 id="login-title">Acesse sua secretaria</h2>
        <p class="login-intro">Entre com seus dados de acesso.</p>

        <?php if ($flash): ?>
            <div class="<?= $flashClass === 'success' ? 'login-message' : 'error-message' ?>" role="alert">
                <?= e((string) ($flash['mensagem'] ?? '')) ?>
            </div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="error-message" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label for="email">E-mail</label>
            <div class="login-input-icon">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
                <input type="email" id="email" name="email" value="<?= e($email) ?>"
                       autocomplete="username" maxlength="254" placeholder="Digite seu e-mail" required autofocus>
            </div>
        </div>

        <div class="password-wrapper">
            <label for="senha">Senha</label>
            <div class="input-with-toggle login-input-icon">
                <svg class="login-lock-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
                <input type="password" id="senha" name="senha" autocomplete="current-password"
                       data-password-max="128" placeholder="Digite sua senha" required>
                <button class="toggle-password" type="button" data-password-toggle="senha" aria-label="Mostrar senha" title="Mostrar senha">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4" />
                    </svg>
                </button>
            </div>
        </div>

        <button class="btn-login" type="submit">Entrar</button>
        <div class="login-help">
            <p class="login-help-title">Precisa de ajuda?</p>
            <p>Solicite acesso ou redefinição de senha ao administrador.</p>
        </div>
    </form>
    </section>
</main>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
