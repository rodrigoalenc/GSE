<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Model/Dva.php';
require_once ROOT_PATH . '/src/Model/Turma.php';

final class AlunoController extends Controller
{
    public function index(): void
    {
        $filters = $this->filters();
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $result = (new Aluno())->paginate($filters, (int) $page);

        $this->view('alunos/index', [
            'title' => 'Gestão de Alunos',
            'filters' => $filters,
            'result' => $result,
            'turmas' => (new Turma())->listar(),
        ]);
    }

    public function criar(): void
    {
        $data = $this->formData();
        $errors = [];
        $possibleDuplicate = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Aluno();
            $studentId = $model->cadastrar(
                $data,
                (int) $_SESSION['usuario_id'],
                ['data_vencimento' => $data['data_vencimento'], 'observacao' => $data['observacao']],
                isset($_POST['confirmar_duplicidade'])
            );

            if ($studentId !== false) {
                AuditLogger::record(
                    'student.created', AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
                    'Cadastro de aluno concluído.', 'student', $studentId
                );

                if ($data['data_vencimento'] !== '') {
                    $dva = (new Dva())->atualDoAluno($studentId);
                    AuditLogger::record(
                        'dva.created', AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
                        'DVA inicial registrada.', 'dva', is_array($dva) ? (int) $dva['id'] : null
                    );
                }

                $this->redirectWithFlash('aluno/perfil/' . $studentId, 'success', 'Aluno cadastrado com sucesso.');
            }

            $possibleDuplicate = $model->lastErrorCode() === 'possible_duplicate';
            $errors[] = $this->studentError($model->lastErrorCode());
            http_response_code(422);
        }

        $this->view('alunos/form', [
            'title' => 'Cadastrar Aluno',
            'data' => $data,
            'errors' => $errors,
            'editing' => false,
            'possibleDuplicate' => $possibleDuplicate,
            'turmas' => (new Turma())->ativas(),
        ]);
    }

    public function editar(string $id): void
    {
        $studentId = $this->validId($id);
        $model = new Aluno();
        $student = $model->buscarPorId($studentId);

        if (!$student) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $data = [
            'nome_completo' => (string) $student['nome_completo'],
            'data_nascimento' => (string) $student['data_nascimento'],
            'id_turma' => (string) $student['id_turma'],
            'telefone_aluno' => (string) $student['telefone_aluno'],
            'telefone_responsavel' => (string) $student['telefone_responsavel'],
            'data_vencimento' => '',
            'observacao' => '',
        ];
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();

            if ($model->atualizar($studentId, $data)) {
                AuditLogger::record(
                    'student.updated', AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
                    'Dados cadastrais do aluno atualizados.', 'student', $studentId
                );
                $this->redirectWithFlash('aluno/perfil/' . $studentId, 'success', 'Aluno atualizado com sucesso.');
            }

            $errors[] = $this->studentError($model->lastErrorCode());
            http_response_code(422);
        }

        $turmas = (new Turma())->ativas();

        if ((int) ($student['turma_ativa'] ?? 0) !== 1 && (int) $student['id_turma'] > 0) {
            $turmas[] = [
                'id' => $student['id_turma'],
                'nome_turma' => $student['nome_turma'],
                'ano_letivo' => $student['ano_letivo'],
                'ativo' => 0,
            ];
        }

        $this->view('alunos/form', [
            'title' => 'Editar Aluno',
            'data' => $data,
            'errors' => $errors,
            'editing' => true,
            'possibleDuplicate' => false,
            'studentId' => $studentId,
            'turmas' => $turmas,
        ]);
    }

    public function perfil(string $id): void
    {
        $studentId = $this->validId($id);
        $profile = (new Aluno())->perfil($studentId);

        if (!$profile) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $this->view('alunos/perfil', [
            'title' => 'Perfil do Aluno',
            'student' => $profile['student'],
            'history' => $profile['dva_history'],
        ]);
    }

    public function status(string $id): void
    {
        $studentId = $this->validId($id);
        $active = filter_var($_POST['ativo'] ?? null, FILTER_VALIDATE_INT);

        if (!in_array($active, [0, 1], true)) {
            render_http_error(422, 'Solicitação inválida', 'A situação informada não é válida.', 'aluno');
        }

        $model = new Aluno();

        if (!$model->definirAtivo($studentId, $active === 1, (int) $_SESSION['usuario_id'])) {
            AuditLogger::record(
                'student.status_failed', AuditLogger::FAILURE, (int) $_SESSION['usuario_id'], null,
                'Alteração de situação não concluída.', 'student', $studentId
            );
            $this->redirectWithFlash('aluno', 'danger', $this->studentError($model->lastErrorCode()));
        }

        $action = $active === 1 ? 'student.reactivated' : 'student.deactivated';
        AuditLogger::record(
            $action, AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
            $active === 1 ? 'Aluno reativado.' : 'Aluno inativado.', 'student', $studentId
        );
        $this->redirectWithFlash(
            'aluno/perfil/' . $studentId,
            'success',
            $active === 1 ? 'Aluno reativado com sucesso.' : 'Aluno inativado com sucesso.'
        );
    }

    public function dva(string $id): void
    {
        $studentId = $this->validId($id);
        $student = (new Aluno())->buscarPorId($studentId);

        if (!$student) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $data = [
            'data_vencimento' => mb_substr(trim((string) ($_POST['data_vencimento'] ?? '')), 0, 10, 'UTF-8'),
            'observacao' => mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, Dva::OBSERVATION_MAX_LENGTH + 1, 'UTF-8'),
        ];
        $errors = [];
        $current = (new Dva())->atualDoAluno($studentId);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Dva();
            $result = $model->registrar(
                $studentId,
                $data['data_vencimento'],
                $data['observacao'],
                (int) $_SESSION['usuario_id']
            );

            if ($result !== false) {
                AuditLogger::record(
                    $result['renewed'] ? 'dva.renewed' : 'dva.created',
                    AuditLogger::SUCCESS,
                    (int) $_SESSION['usuario_id'],
                    null,
                    $result['renewed'] ? 'DVA renovada e versão anterior arquivada.' : 'DVA inicial registrada.',
                    'dva',
                    $result['id']
                );
                $this->redirectWithFlash('aluno/perfil/' . $studentId, 'success', $result['renewed'] ? 'DVA renovada com sucesso.' : 'DVA registrada com sucesso.');
            }

            $errors[] = match ($model->lastErrorCode()) {
                'inactive_student' => 'Reative o aluno antes de registrar uma nova DVA.',
                'student_not_found' => 'Aluno não encontrado.',
                default => 'Informe uma data válida e uma observação com até 1.000 caracteres.',
            };
            http_response_code(422);
        }

        $this->view('alunos/dva', [
            'title' => $current ? 'Renovar DVA' : 'Registrar DVA',
            'student' => $student,
            'current' => $current,
            'data' => $data,
            'errors' => $errors,
        ]);
    }

    public function painelDva(): void
    {
        $filters = $this->filters();
        $filters['ativo'] = '1';
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;

        $this->view('alunos/dva-index', [
            'title' => 'Painel de DVAs',
            'filters' => $filters,
            'result' => (new Aluno())->paginate($filters, (int) $page),
        ]);
    }

    /** @return array<string,string> */
    private function filters(): array
    {
        $active = (string) ($_GET['ativo'] ?? '1');

        return [
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8'),
            'turma' => mb_substr(trim((string) ($_GET['turma'] ?? '')), 0, 20, 'UTF-8'),
            'ativo' => in_array($active, ['0', '1', 'todos'], true) ? ($active === 'todos' ? '' : $active) : '1',
            'dva' => in_array((string) ($_GET['dva'] ?? ''), DvaStatus::ALL, true) ? (string) $_GET['dva'] : '',
        ];
    }

    /** @return array<string,string> */
    private function formData(): array
    {
        return [
            'nome_completo' => mb_substr((string) ($_POST['nome_completo'] ?? ''), 0, Aluno::NAME_MAX_LENGTH + 1, 'UTF-8'),
            'data_nascimento' => mb_substr(trim((string) ($_POST['data_nascimento'] ?? '')), 0, 10, 'UTF-8'),
            'id_turma' => mb_substr(trim((string) ($_POST['id_turma'] ?? '')), 0, 20, 'UTF-8'),
            'telefone_aluno' => mb_substr(trim((string) ($_POST['telefone_aluno'] ?? '')), 0, 30, 'UTF-8'),
            'telefone_responsavel' => mb_substr(trim((string) ($_POST['telefone_responsavel'] ?? '')), 0, 30, 'UTF-8'),
            'data_vencimento' => mb_substr(trim((string) ($_POST['data_vencimento'] ?? '')), 0, 10, 'UTF-8'),
            'observacao' => mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, Dva::OBSERVATION_MAX_LENGTH + 1, 'UTF-8'),
        ];
    }

    private function studentError(?string $code): string
    {
        return match ($code) {
            'possible_duplicate' => 'Já existe um aluno com o mesmo nome e nascimento. Confirme a duplicidade somente se forem pessoas diferentes.',
            'invalid_name' => 'Informe um nome com até 150 caracteres.',
            'invalid_birth_date' => 'Informe uma data de nascimento válida.',
            'future_birth_date' => 'A data de nascimento não pode estar no futuro.',
            'invalid_phone' => 'Os telefones devem ter 10 ou 11 dígitos.',
            'invalid_class' => 'Selecione uma turma ativa válida.',
            'invalid_dva' => 'Revise a data e a observação da DVA inicial.',
            'not_found' => 'Aluno não encontrado.',
            default => 'Não foi possível concluir a operação. Tente novamente.',
        };
    }

    private function validId(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);

        if ($value === false || (int) $value < 1) {
            render_http_error(404, 'Registro não encontrado', 'O identificador informado não é válido.');
        }

        return (int) $value;
    }
}
