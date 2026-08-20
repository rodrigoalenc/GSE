<?php
$title = $title ?? 'GSE';
$isAdmin = Auth::isAdmin();
$currentPath = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
$dashboardActive = in_array($currentPath, ['dashboard', 'painel', ''], true);
$usersActive = str_starts_with($currentPath, 'usuario');
$auditActive = str_starts_with($currentPath, 'auditoria');
$passwordActive = str_starts_with($currentPath, 'senha/alterar');
$studentsActive = str_starts_with($currentPath, 'aluno');
$dvaActive = $currentPath === 'dva';
$classesActive = str_starts_with($currentPath, 'turma');
$mustChangePassword = (bool) ($_SESSION['must_change_password'] ?? false);
$flashClass = [
    'success' => 'success',
    'danger' => 'error',
    'warning' => 'warning',
    'info' => 'info',
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Sistema</title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/painel.css')) ?>">
    <?php if ($usersActive || $auditActive || $passwordActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/usuarios.css')) ?>">
    <?php endif; ?>
    <?php if ($studentsActive || $dvaActive || $classesActive || $dashboardActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/alunos.css')) ?>">
    <?php endif; ?>
</head>
<body>
<div class="layout-container">
    <aside class="sidebar" aria-label="Menu principal">
        <div class="sidebar-header">
            <h2>GSE</h2>
        </div>

        <nav>
            <?php if (!$mustChangePassword): ?>
                <a href="<?= e(url('dashboard')) ?>" class="sidebar-link <?= $dashboardActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">🏠</span>
                    <span>Painel Geral</span>
                </a>
                <a href="<?= e(url('aluno')) ?>" class="sidebar-link <?= $studentsActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">A</span>
                    <span>Alunos</span>
                </a>
                <a href="<?= e(url('dva')) ?>" class="sidebar-link <?= $dvaActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">D</span>
                    <span>DVAs</span>
                </a>
            <?php endif; ?>

            <?php if ($isAdmin && !$mustChangePassword): ?>
                <div class="sidebar-divider"></div>
                <small class="sidebar-section-label">Admin</small>
                <a href="<?= e(url('usuario')) ?>" class="sidebar-link <?= $usersActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">👥</span>
                    <span>Usuários</span>
                </a>
                <a href="<?= e(url('turma')) ?>" class="sidebar-link <?= $classesActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">T</span>
                    <span>Turmas</span>
                </a>
                <a href="<?= e(url('auditoria')) ?>" class="sidebar-link <?= $auditActive ? 'active' : '' ?>">
                    <span class="sidebar-icon" aria-hidden="true">🛡️</span>
                    <span>Auditoria</span>
                </a>
            <?php endif; ?>

            <a href="<?= e(url('senha/alterar')) ?>" class="sidebar-link <?= $passwordActive ? 'active' : '' ?>">
                <span class="sidebar-icon" aria-hidden="true">🔑</span>
                <span>Alterar senha</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div>Olá, <strong><?= e((string) ($_SESSION['usuario_nome'] ?? 'Usuário')) ?></strong></div>
                <small class="sidebar-user-role"><?= e(nome_perfil((string) ($_SESSION['usuario_tipo'] ?? ''))) ?></small>
            </div>
            <form class="logout-form" method="post" action="<?= e(url('login/sair')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn-sair" type="submit">Sair do Sistema</button>
            </form>
        </div>
    </aside>

    <div class="main-content-wrapper">
        <header>
            <h1><?= e($title) ?></h1>
            <div class="header-meta">
                Data: <?= e(date('d/m/Y')) ?>
            </div>
        </header>

        <main>
            <?php if ($flash): ?>
                <?php $class = $flashClass[$flash['tipo'] ?? 'info'] ?? 'info'; ?>
                <div class="<?= e($class) ?>-message" role="alert">
                    <?= e((string) ($flash['mensagem'] ?? '')) ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
