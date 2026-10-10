<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Sistema.php';

final class BackupController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();
        $this->renderPage();
    }

    public function criar(): void
    {
        $this->requireAdmin();
        if (!csrf_valido($_POST['_csrf_token'] ?? null)) {
            render_http_error(419, 'Sessão expirada', 'Atualize a página e tente novamente.', 'backup');
        }
        $senha = is_string($_POST['senha_atual'] ?? null) ? $_POST['senha_atual'] : '';
        $nome = (new Sistema())->criarBackupAdministrativo(
            (int) ($_SESSION['usuario_id'] ?? 0), (int) ($_SESSION['auth_version'] ?? 0), $senha
        );
        if ($nome !== false) {
            $this->redirectWithFlash('backup', 'success', 'Backup do banco criado com sucesso.');
        }
        http_response_code(422);
        $this->renderPage(['Não foi possível criar o backup. Verifique a senha atual e tente novamente.']);
    }

    public function baixar(string $nome): void
    {
        $this->requireAdmin();
        try {
            $backup = (new Sistema())->abrirBackupAdministrativo(
                $nome, (int) ($_SESSION['usuario_id'] ?? 0), (int) ($_SESSION['auth_version'] ?? 0)
            );
        } catch (DomainException) {
            render_http_error(404, 'Backup indisponível', 'O backup solicitado não está disponível.', 'backup');
        } catch (Throwable $exception) {
            TechnicalLogger::error('administrative_backup_download_failed', ['exception' => $exception::class]);
            render_http_error(503, 'Download indisponível', 'Não foi possível autorizar o download. Tente novamente.', 'backup');
        }
        // The validated handle is opened and the mandatory audit committed before any bytes are sent.
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup['nome'] . '"');
        header('Content-Length: ' . (string) $backup['bytes']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            fpassthru($backup['handle']);
        } finally {
            fclose($backup['handle']);
        }
    }

    /** @param list<string> $erros */
    private function renderPage(array $erros = []): void
    {
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        try {
            $result = (new Sistema())->listarBackupsAdministrativos(
                (int) ($_SESSION['usuario_id'] ?? 0), (int) ($_SESSION['auth_version'] ?? 0), $page === false ? 1 : $page
            );
        } catch (Throwable $exception) {
            TechnicalLogger::error('administrative_backup_list_failed', ['exception' => $exception::class]);
            http_response_code(422);
            $result = ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
            $erros[] = 'Não foi possível consultar os backups. Tente novamente.';
        }
        $this->view('backups/index', ['title' => 'Backups', 'result' => $result, 'erros' => $erros]);
    }
}
