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
        require_once ROOT_PATH . '/src/Model/Turma.php';
        require_once ROOT_PATH . '/src/Model/Passivo.php';
        $statusService = new DvaStatus();
        $painel = new Painel();
        $alunos = new Aluno();
        $searchFilters = [
            'q' => mb_substr(input_string($_GET['q'] ?? ''), 0, 100, 'UTF-8'),
            'ativo' => input_string($_GET['ativo'] ?? ''),
            'turma' => input_string($_GET['turma'] ?? ''),
            'dva' => input_string($_GET['dva'] ?? ''),
        ];
        $searchFilters['ativo'] = in_array($searchFilters['ativo'], ['0', '1'], true) ? $searchFilters['ativo'] : '';
        $classId = filter_var($searchFilters['turma'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $searchFilters['turma'] = $classId === false ? '' : (string) $classId;
        $searchFilters['dva'] = in_array($searchFilters['dva'], DvaStatus::ALL, true) ? $searchFilters['dva'] : '';
        $searchRequested = array_intersect(['q', 'ativo', 'turma', 'dva'], array_keys($_GET)) !== [];
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        require_once ROOT_PATH . '/src/Model/Certidao.php';
        $this->view('dashboard/index', [
            'title' => 'Painel de Controle',
            'certidoes' => (new Certidao())->summary(),
            'moduloDois' => $painel->resumo($statusService),
            'alunosPorStatus' => $painel->alunosPorStatus($statusService),
            'aniversariantesMes' => $alunos->aniversariantesDoMesCompleto(),
            'mesAtual' => (int) date('n', strtotime($statusService->today())),
            'searchFilters' => $searchFilters,
            'searchResult' => $searchRequested ? $painel->buscarAlunos($searchFilters, (int) $page, 15, $statusService) : null,
            'turmasBusca' => (new Turma())->listar(),
            'passivoResumo' => (new Passivo())->resumo(),
            'estoquePendencias' => $painel->pendenciasEstoque(),
        ]);
    }
}
