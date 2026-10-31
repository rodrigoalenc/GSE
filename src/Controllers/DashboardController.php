<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/AccessPolicy.php';

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
        $statusService = new DvaStatus();
        $painel = new Painel();
        $alunos = new Aluno();
        $canViewHealth = AccessPolicy::allows('student.health');
        if (input_string($_GET['dva'] ?? '') !== '' && !$canViewHealth) { AccessPolicy::require('student.health'); }
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
        $birthdayTotal = $alunos->contarAniversariantesDoMes();
        $birthdayPages = max(1, (int) ceil($birthdayTotal / 20));
        $birthdayPage = max(1, min($birthdayPages, (int) (filter_var($_GET['aniversariantes_pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1)));
        require_once ROOT_PATH . '/src/Model/Certidao.php';
        $viewData = [
            'title' => 'Painel de Controle',
            'certidoes' => (new Certidao())->summary(),
            'moduloDois' => $canViewHealth ? $painel->resumo($statusService) : array_fill_keys(['sem_dva', 'vencidas', 'vence_hoje', 'a_vencer', 'vigentes'], 0) + ['alunos_ativos' => $alunos->contar(['ativo' => '1']), 'alunos_inativos' => $alunos->contar(['ativo' => '0'])],
            'alunosPorStatus' => $canViewHealth ? $painel->alunosPorStatus($statusService) : array_fill_keys(DvaStatus::ALL, []),
            'canViewHealth' => $canViewHealth,
            'aniversariantesMes' => $alunos->aniversariantesDoMesCompleto(null, 20, $birthdayPage),
            'aniversariantesHoje' => $alunos->aniversariantesDoDia(null, 10),
            'birthdayTotal' => $birthdayTotal,
            'birthdayPages' => $birthdayPages,
            'birthdayPage' => $birthdayPage,
            'mesAtual' => (int) date('n', strtotime($statusService->today())),
            'searchFilters' => $searchFilters,
            'searchResult' => $searchRequested ? $painel->buscarAlunos($searchFilters, (int) $page, 15, $statusService, $canViewHealth) : null,
            'turmasBusca' => (new Turma())->listar(),
        ];
        if ($canViewHealth) {
            $quantity = array_sum(array_map('count', $viewData['alunosPorStatus'])) + count($viewData['searchResult']['items'] ?? []);
            AuditLogger::recordRequired(Model::getConexao(), 'student.sensitive_read', AuditLogger::SUCCESS,
                (int) $_SESSION['usuario_id'], null, 'Contexto=painel; quantidade=' . $quantity, 'student', null);
        }
        $this->view('dashboard/index', $viewData);
    }
}
