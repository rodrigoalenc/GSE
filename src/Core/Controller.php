<?php

declare(strict_types=1);

abstract class Controller
{
    /** @param array<string,mixed> $viewData */
    protected function view(string $view, array $viewData = [], bool $useLayout = true): void
    {
        require_once ROOT_PATH . '/src/Core/NavigationContext.php';
        $listPage = NavigationContext::route((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        if ($listPage !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { NavigationContext::remember($listPage, $_GET); }
        $viewFile = ROOT_PATH . '/src/Views/' . trim($view, '/') . '.php';

        if (!is_file($viewFile)) {
            throw new RuntimeException("View nao encontrada: {$view}");
        }

        extract($viewData, EXTR_SKIP);

        if (!$useLayout) {
            require $viewFile;

            return;
        }

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        $flash = consumir_flash();

        require ROOT_PATH . '/src/Views/layouts/app.php';
    }

    protected function requireAdmin(): void
    {
        if (Auth::isAdmin()) {
            return;
        }

        $userId = (int) ($_SESSION['usuario_id'] ?? 0);
        AuditLogger::record(
            'authorization.admin_denied',
            AuditLogger::BLOCKED,
            $userId ?: null,
            $userId ?: null,
            'Acesso a recurso administrativo negado.'
        );
        render_http_error(403, 'Acesso não autorizado', 'Somente administradores podem acessar este recurso.', 'dashboard');
    }

    protected function redirectWithFlash(string $path, string $type, string $message): never
    {
        if ($type === 'success' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            require_once ROOT_PATH . '/src/Core/FormDraft.php';
            $target = FormDraft::target((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
            if ($target !== null) { FormDraft::close((int) ($_SESSION['usuario_id'] ?? 0), $target); }
        }
        definir_flash($type, $message);
        redirect($path);
    }
}
