<?php
require_once ROOT_PATH . '/src/Core/NavigationContext.php';
require_once ROOT_PATH . '/src/Core/AccessPolicy.php';
$title = $title ?? 'GSE';
$isAdmin = Auth::isAdmin();
$currentPath = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
$dashboardActive = in_array($currentPath, ['dashboard', 'painel', ''], true);
$profileActive = $currentPath === 'usuario/perfil';
$usersActive = str_starts_with($currentPath, 'usuario') && !$profileActive;
$auditActive = str_starts_with($currentPath, 'auditoria');
$backupActive = str_starts_with($currentPath, 'backup');
$passwordActive = str_starts_with($currentPath, 'senha/alterar');
$passiveActive = str_starts_with($currentPath, 'passivo') || str_starts_with($currentPath, 'aluno/arquivar');
$studentsActive = str_starts_with($currentPath, 'aluno') && !$passiveActive;
$dvaActive = $currentPath === 'dva';
$certActive = str_starts_with($currentPath, 'certidao');
$certHeaderClass = $certActive ? match (true) {
    str_starts_with($currentPath, 'certidao/configurar') => 'cert-page-config',
    str_starts_with($currentPath, 'certidao/cadastrar'), str_starts_with($currentPath, 'certidao/editar'), str_starts_with($currentPath, 'certidao/renovar') => 'cert-page-form',
    default => 'cert-page-matrix',
} : '';
$contractActive = str_starts_with($currentPath, 'contrato');
$stockActive = str_starts_with($currentPath, 'estoque');
$reportActive = str_starts_with($currentPath, 'relatorio');
$classesActive = str_starts_with($currentPath, 'turma');
$mustChangePassword = (bool) ($_SESSION['must_change_password'] ?? false);
$flashClass = [
    'success' => 'success',
    'danger' => 'error',
    'warning' => 'warning',
    'info' => 'info',
];
$navigation = [
    ['dashboard', 'Painel geral', 'layout', $dashboardActive, true],
    ['aluno', 'Alunos', 'users', $studentsActive, true],
    ['dva', 'DVAs', 'clipboard', $dvaActive, AccessPolicy::allows('student.health')],
    ['passivo', 'Arquivo passivo', 'archive', $passiveActive, true],
    ['certidao', 'Certidões', 'certificate', $certActive, true],
    ['contrato', 'Pedidos', 'orders', $contractActive, true],
    ['estoque', 'Estoque', 'box', $stockActive, true],
    ['relatorio', 'Relatórios', 'chart', $reportActive, AccessPolicy::allows('student.export')],
];
$adminNavigation = [
    ['usuario', 'Usuários', 'users', $usersActive],
    ['turma', 'Turmas', 'school', $classesActive],
    ['auditoria', 'Auditoria', 'shield', $auditActive],
    ['backup', 'Backups', 'database', $backupActive],
];
$pageIcon = match (true) {
    $dashboardActive => 'layout', $studentsActive => 'users', $dvaActive => 'clipboard',
    $passiveActive => 'archive', $certActive => 'certificate', $contractActive => 'orders',
    $stockActive => 'box', $reportActive => 'chart', $classesActive => 'school',
    $auditActive => 'shield', $backupActive => 'database', $passwordActive => 'key', default => 'user',
};
$pageHeading = match ($currentPath) {
    'dashboard', 'painel', '' => 'Painel geral', 'aluno' => 'Alunos', 'aluno/criar' => 'Novo aluno',
    'dva' => 'Declarações de vacina', 'usuario' => 'Usuários', 'usuario/criar' => 'Novo usuário',
    'certidao' => 'Certidões', 'relatorio' => 'Relatórios', default => $title,
};
$userName = (string) ($_SESSION['usuario_nome'] ?? 'Usuário');
$nameParts = preg_split('/\s+/u', trim($userName), -1, PREG_SPLIT_NO_EMPTY) ?: ['U'];
$userInitials = mb_strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Sistema</title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/image/logo_escola.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/painel.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/usabilidade.css')) ?>">
    <?php if ($contractActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/pedidos-usabilidade.css')) ?>"><?php endif; ?>
    <?php if ($contractActive || $stockActive || $reportActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/modulo5.css')) ?>"><?php endif; ?>
    <?php if ($stockActive || str_starts_with($currentPath,'contrato/historico/') || str_starts_with($currentPath,'contrato/recebimento/')): ?><link rel="stylesheet" href="<?= e(url('assets/css/estoque.css?v='.substr((string)hash_file('sha256',ROOT_PATH.'/public/assets/css/estoque.css'),0,12))) ?>"><?php endif; ?>
    <?php if ($contractActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/contratos.css')) ?>"><?php endif; ?>
    <?php if ($reportActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/relatorios.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/relatorios.css'), 0, 12))) ?>"><?php endif; ?>
    <?php if ($certActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/certidoes.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/certidoes.css'), 0, 12))) ?>">
    <?php endif; ?>
    <?php if ($usersActive || $profileActive || $auditActive || $backupActive || $passwordActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/usuarios.css')) ?>">
    <?php endif; ?>
    <?php if ($studentsActive || $dvaActive || $classesActive || $dashboardActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/alunos.css')) ?>">
    <?php endif; ?>
    <?php if ($dashboardActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/dashboard.css')) ?>"><?php endif; ?>
    <?php if ($passiveActive): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/passivo.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/passivo.css'), 0, 12))) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/institucional.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/institucional.css'), 0, 12))) ?>">
    <?php if ($currentPath === 'aluno/criar' || str_starts_with($currentPath, 'aluno/editar/')): ?><link rel="stylesheet" href="<?= e(url('assets/css/alunos-form.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/alunos-form.css'), 0, 12))) ?>"><?php endif; ?>
    <?php if ($dvaActive): ?><link rel="stylesheet" href="<?= e(url('assets/css/dva.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/dva.css'), 0, 12))) ?>"><?php endif; ?>
    <?php if ($currentPath === 'aluno'): ?><link rel="stylesheet" href="<?= e(url('assets/css/alunos-preview.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/alunos-preview.css'), 0, 12))) ?>"><?php endif; ?>
</head>
<body data-draft-endpoint="<?= e(url('formulario/rascunho')) ?>" data-list-context="<?= e(json_encode(NavigationContext::links(), JSON_THROW_ON_ERROR)) ?>" data-list-page="<?= e($listPage ?? '') ?>" data-list-state-token="<?= e($listStateToken ?? '') ?>" data-list-state-expired="<?= !empty($listStateExpired) ? '1' : '0' ?>">
<a class="skip-link" href="#conteudo-principal">Pular para o conteúdo</a>
<div class="layout-container">
    <aside class="sidebar" aria-label="Menu principal">
        <div class="sidebar-header">
            <a class="sidebar-brand" href="<?= e(url($mustChangePassword ? 'senha/alterar' : 'dashboard')) ?>" aria-label="GSE — E.E. São José">
                <img class="sidebar-brand-icon" src="<?= e(url('assets/image/logo_escola.png')) ?>" alt="Logotipo da E.E. São José">
                <span class="sidebar-brand-text"><strong>GSE</strong><small>Gestor de<br>Secretaria Escolar</small></span>
            </a>
            <span class="sidebar-school">E.E. São José</span>
        </div>

        <button type="button" class="sidebar-mobile-toggle" data-sidebar-toggle aria-expanded="false" aria-controls="gse-main-navigation" hidden><?= gse_icon('menu') ?> Menu de funções <?= gse_icon('chevron-down') ?></button>
        <nav id="gse-main-navigation">
            <?php if (!$mustChangePassword): ?>
                <?php foreach ($navigation as [$path, $label, $icon, $active, $allowed]): if (!$allowed) { continue; } ?>
                <a href="<?= e(url($path)) ?>" class="sidebar-link <?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                    <span class="sidebar-icon"><?= gse_icon($icon) ?></span><span class="sidebar-label"><?= e($label) ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($isAdmin && !$mustChangePassword): ?>
                <div class="sidebar-divider"></div>
                <small class="sidebar-section-label">Administração</small>
                <?php foreach ($adminNavigation as [$path, $label, $icon, $active]): ?>
                <a href="<?= e(url($path)) ?>" class="sidebar-link <?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                    <span class="sidebar-icon"><?= gse_icon($icon) ?></span><span class="sidebar-label"><?= e($label) ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <a href="<?= e(url('senha/alterar')) ?>" class="sidebar-link <?= $passwordActive ? 'active' : '' ?>" <?= $passwordActive ? 'aria-current="page"' : '' ?>>
                <span class="sidebar-icon"><?= gse_icon('key') ?></span>
                <span class="sidebar-label">Alterar senha</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <span class="sidebar-avatar" aria-hidden="true"><?= e($userInitials) ?></span>
                <div class="sidebar-footer-label"><strong><?php if ($mustChangePassword): ?><?= e($userName) ?><?php else: ?><a class="sidebar-profile-link" href="<?= e(url('usuario/perfil')) ?>" <?= $profileActive ? 'aria-current="page"' : '' ?> title="Editar meu perfil"><?= e($userName) ?></a><?php endif; ?></strong>
                    <small class="sidebar-user-role"><?= e(nome_perfil((string) ($_SESSION['usuario_tipo'] ?? ''))) ?></small>
                </div>
            </div>
            <form class="logout-form" method="post" action="<?= e(url('login/sair')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn-sair" type="submit"><?= gse_icon('logout') ?><span>Sair do sistema</span></button>
            </form>
        </div>
    </aside>

    <div class="main-content-wrapper">
        <div class="app-topbar"><span>E.E. São José <span class="topbar-divider">/</span> Secretaria escolar</span><span class="topbar-account"><?= gse_icon('user') ?> <?= e($userName) ?></span></div>
        <header class="page-header <?= e($certHeaderClass) ?>">
            <div class="page-heading"><p class="page-breadcrumb"><?= $usersActive || $auditActive || $backupActive || $classesActive ? 'Administração' : 'Secretaria' ?><span>/</span><?php if ($usersActive && $currentPath !== 'usuario'): ?><a href="<?= e(url('usuario')) ?>">Usuários</a><span>/</span><?php elseif ($studentsActive && $currentPath !== 'aluno'): ?><a href="<?= e(url('aluno')) ?>">Alunos</a><span>/</span><?php endif; ?><?= e($pageHeading) ?></p><h1><?= gse_icon($pageIcon) ?><?= e($pageHeading) ?></h1></div>
            <?php $contextList = $studentsActive ? 'aluno' : ($passiveActive ? 'passivo' : ($contractActive ? 'contrato' : ($stockActive ? 'estoque' : ($certActive ? 'certidao' : ($usersActive ? 'usuario' : ($classesActive ? 'turma' : null)))))); ?>
            <?php if ($contextList !== null && $currentPath !== $contextList): ?><a class="context-return" href="<?= e(url(NavigationContext::back($contextList))) ?>">Voltar à lista com os filtros</a><?php endif; ?>
            <?php if (isset($headerMeta) || $dashboardActive || $currentPath === 'passivo'): ?>
            <div class="header-meta">
                <?= e((string) ($headerMeta ?? ($currentPath === 'passivo' ? '' : 'Data: ') . UiPresentation::localNow()->format('d/m/Y'))) ?>
            </div>
            <?php endif; ?>
        </header>

        <main id="conteudo-principal" tabindex="-1">
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

<script src="<?= e(url('assets/js/pedidos-usabilidade.js?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/js/pedidos-usabilidade.js'), 0, 12))) ?>"></script>
<script src="<?= e(url('assets/js/app.js?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/js/app.js'), 0, 12))) ?>"></script>
<script src="<?= e(url('assets/js/usabilidade.js?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/js/usabilidade.js'), 0, 12))) ?>"></script>
<?php if ($currentPath === 'aluno'): ?><script src="<?= e(url('assets/js/alunos-preview.js?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/js/alunos-preview.js'), 0, 12))) ?>"></script><?php endif; ?>
<script src="<?= e(url('assets/js/list-state.js?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/js/list-state.js'), 0, 12))) ?>"></script>
</body>
</html>
