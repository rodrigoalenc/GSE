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
        require_once ROOT_PATH . '/src/Model/Certidao.php';
        $pdo = Model::getConexao();
        $moduleFive = [
            'contratos' => (int)$pdo->query('SELECT COUNT(*) FROM pedidos WHERE excluido_em IS NULL')->fetchColumn(),
            'produtos' => (int)$pdo->query('SELECT COUNT(*) FROM pedido_produtos i JOIN pedidos p ON p.id=i.id_pedido WHERE p.excluido_em IS NULL AND i.excluido_em IS NULL')->fetchColumn(),
            'alertas' => (int)$pdo->query('SELECT COUNT(*) FROM pedido_produtos i JOIN pedidos p ON p.id=i.id_pedido WHERE p.excluido_em IS NULL AND i.excluido_em IS NULL AND i.estoque_inicializado=1 AND COALESCE((SELECT SUM(m.quantidade) FROM estoque_movimentos m WHERE m.produto_id=i.id),0)<=i.estoque_minimo')->fetchColumn(),
        ];

        $this->view('dashboard/index', [
            'title' => 'Painel de Controle',
            'certidoes' => (new Certidao())->summary(),
            'moduleFive' => $moduleFive,
            'usuario' => $usuario,
            'estatisticas' => $estatisticas,
            'moduloDois' => $painel->resumo($statusService),
            'pendencias' => $painel->pendenciasPrioritarias(8, $statusService),
            'aniversariantesHoje' => $alunos->aniversariantesDoDia(null, 8),
            'aniversariantesMes' => $alunos->aniversariantesDoMes(null, 8),
        ]);
    }
}
