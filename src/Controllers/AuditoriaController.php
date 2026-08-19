<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Auditoria.php';

final class AuditoriaController extends Controller
{
    public function index(): void
    {
        $filters = [
            'action' => mb_substr(trim((string) ($_GET['action'] ?? '')), 0, 80, 'UTF-8'),
            'result' => mb_substr(trim((string) ($_GET['result'] ?? '')), 0, 20, 'UTF-8'),
            'from' => mb_substr(trim((string) ($_GET['from'] ?? '')), 0, 10, 'UTF-8'),
            'to' => mb_substr(trim((string) ($_GET['to'] ?? '')), 0, 10, 'UTF-8'),
        ];
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $result = (new Auditoria())->paginate($filters, (int) $page);

        $this->view('auditoria/index', [
            'title' => 'Auditoria de Segurança',
            'filters' => $filters,
            'result' => $result,
        ]);
    }
}
