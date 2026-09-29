<?php

declare(strict_types=1);

final class DashboardController extends Controller
{
    public function index(): void
    {
        $usuario = Auth::user();

        if (!$usuario) {
            redirect('login');
        }

        require_once ROOT_PATH . '/src/Model/Painel.php';
        require_once ROOT_PATH . '/src/Model/Aluno.php';
        $statusService = new DvaStatus();
        $painel = new Painel();
        $alunos = new Aluno();
        require_once ROOT_PATH . '/src/Model/Certidao.php';
        $this->view('dashboard/index', [
            'title' => 'Painel de Controle',
            'certidoes' => (new Certidao())->summary(),
            'moduloDois' => $painel->resumo($statusService),
            'alunosPorStatus' => $painel->alunosPorStatus($statusService),
            'aniversariantesMes' => $alunos->aniversariantesDoMesCompleto(),
            'mesAtual' => (int) date('n', strtotime($statusService->today())),
        ]);
    }
}
