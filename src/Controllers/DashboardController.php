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

        $estatisticas = (new Usuario())->estatisticas();
        require_once ROOT_PATH . '/src/Model/Painel.php';
        require_once ROOT_PATH . '/src/Model/Aluno.php';
        $statusService = new DvaStatus();
        $painel = new Painel();
        $alunos = new Aluno();

        $this->view('dashboard/index', [
            'title' => 'Painel de Controle',
            'usuario' => $usuario,
            'estatisticas' => $estatisticas,
            'moduloDois' => $painel->resumo($statusService),
            'pendencias' => $painel->pendenciasPrioritarias(8, $statusService),
            'aniversariantesHoje' => $alunos->aniversariantesDoDia(null, 8),
            'aniversariantesMes' => $alunos->aniversariantesDoMes(null, 8),
        ]);
    }
}
