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
    <title>Login - Sistema Escolar</title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/login.css')) ?>">
</head>
<body class="login-container">
    <form action="<?= e(url('login/entrar')) ?>" method="post" class="login" aria-labelledby="login-title">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

        <h2 id="login-title">Login</h2>

        <?php if ($flash): ?>
            <div class="<?= $flashClass === 'success' ? 'login-message' : 'error-message' ?>" role="alert">
                <?= e((string) ($flash['mensagem'] ?? '')) ?>
            </div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="error-message" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label for="email">E-mail:</label>
            <input type="email" id="email" name="email" value="<?= e($email) ?>"
                   autocomplete="username" maxlength="254" placeholder="seu@email.com" required autofocus>
        </div>

        <div class="password-wrapper">
            <label for="senha">Senha:</label>
            <div class="input-with-toggle">
                <input type="password" id="senha" name="senha" autocomplete="current-password"
                       maxlength="128" placeholder="Sua senha" required>
                <button class="toggle-password" type="button" data-password-toggle="senha" aria-label="Mostrar senha" title="Mostrar senha">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4" />
                    </svg>
                </button>
            </div>
        </div>

        <button class="btn-login" type="submit">Entrar</button>
    </form>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
