<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Auditoria.php';

final class AuditoriaController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();
        $this->view('auditoria/index', [
            'title' => 'Logs de Auditoria',
            'logs' => (new Auditoria())->latest(),
            'retentionDays' => Auditoria::manualRetentionDays(),
        ]);
    }

    public function limpar(): void
    {
        $this->requireAdmin();
        if (!csrf_valido($_POST['_csrf_token'] ?? null)) {
            render_http_error(419, 'Sessão expirada', 'Atualize a página e tente novamente.', 'auditoria');
        }
        if (input_string($_POST['acao'] ?? '') !== 'limpar') {
            render_http_error(400, 'Requisição inválida', 'Selecione uma ação válida.', 'auditoria');
        }

        try {
            $deleted = (new Auditoria())->clearOld(
                (int) ($_SESSION['usuario_id'] ?? 0), null, (int) ($_SESSION['auth_version'] ?? 0)
            );
        } catch (Throwable $exception) {
            TechnicalLogger::error('audit_cleanup_failed', ['exception' => $exception::class]);
            $this->redirectWithFlash('auditoria', 'danger', 'Não foi possível limpar os logs. Tente novamente em alguns instantes.');
        }
        $this->redirectWithFlash('auditoria', 'success', 'Logs limpos. Foram removidos ' . $deleted . ' registros antigos.');
    }
}
