<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Model/Dva.php';
require_once ROOT_PATH . '/src/Model/Turma.php';
require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';
require_once ROOT_PATH . '/src/Services/VinculoEscolar.php';
require_once ROOT_PATH . '/src/Core/AccessPolicy.php';

final class AlunoController extends Controller
{
    public function index(): void
    {
        $filters = $this->filters();
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $canViewHealth = AccessPolicy::allows('student.health');
        $canViewContacts = AccessPolicy::allows('student.contacts');
        $result = (new Aluno())->paginate($filters, (int) $page, 15, null, $canViewHealth, $canViewContacts);
        if ($canViewHealth || $canViewContacts) { $this->recordSensitiveRead('lista_alunos', count($result['items'])); }

        $this->view('alunos/index', [
            'title' => 'Gerenciar Alunos',
            'filters' => $filters,
            'result' => $result,
            'turmas' => (new Turma())->listar(),
            'canViewHealth' => $canViewHealth,
            'canManageStudent' => AccessPolicy::allows('student.manage'),
        ]);
    }

    public function criar(): void
    {
        AccessPolicy::require('student.manage');
        $canViewHealth = AccessPolicy::allows('student.health');
        $data = $this->formData();
        $errors = [];
        $possibleDuplicate = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Aluno();
            $studentId = $model->cadastrar(
                $data,
                (int) $_SESSION['usuario_id'],
                $canViewHealth ? ['data_vencimento' => $data['data_vencimento'], 'observacao' => $data['observacao']] : null,
                isset($_POST['confirmar_duplicidade']),
                input_string($_POST['_request_token'] ?? '')
            );

            if ($studentId !== false) {
                $this->redirectWithFlash('aluno/perfil/' . $studentId, 'success', 'Aluno cadastrado com sucesso.');
            }

            $possibleDuplicate = $model->lastErrorCode() === 'possible_duplicate';
            $errors[] = $this->studentError($model->lastErrorCode());
            http_response_code(422);
        }

        $this->view('alunos/form', [
            'title' => 'Cadastrar Novo Aluno',
            'data' => $data,
            'errors' => $errors,
            'editing' => false,
            'possibleDuplicate' => $possibleDuplicate,
            'turmas' => (new Turma())->ativas(),
            'canViewHealth' => $canViewHealth,
        ]);
    }

    public function editar(string $id): void
    {
        AccessPolicy::require('student.manage');
        $studentId = $this->validId($id);
        $model = new Aluno();
        $student = $model->buscarPorId($studentId, AccessPolicy::allows('student.health'), true);

        if (!$student) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $data = [
            'revisao' => (string) $student['revisao'],
            'nome_completo' => (string) $student['nome_completo'],
            'data_nascimento' => (string) $student['data_nascimento'],
            'id_turma' => (string) $student['id_turma'],
            'telefone_aluno' => (string) $student['telefone_aluno'],
            'telefone_responsavel' => (string) $student['telefone_responsavel'],
            'data_vencimento' => '',
            'observacao' => '',
        ];
        $errors = [];
        $possibleDuplicate = false;
        $conflictReview = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();

            $revision = filter_var($data['revisao'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $reviewToken = input_string($_POST['_conflict_token'] ?? '');
            $draftSourceRevision = input_string($_POST['_draft_source_revision'] ?? '');
            $staleDraft = $reviewToken === '' && preg_match('/^[1-9][0-9]{0,10}$/D', $draftSourceRevision) === 1
                && $draftSourceRevision !== (string) $student['revisao'];
            $pendingReview = $_SESSION['student_edit_reviews'][$studentId] ?? null;
            $reviewRequired = $reviewToken !== '' && (!is_array($pendingReview)
                || !is_string($pendingReview['token'] ?? null) || !hash_equals($pendingReview['token'], $reviewToken)
                || (int) ($pendingReview['expires'] ?? 0) < time() || input_string($_POST['confirmar_revisao'] ?? '') !== '1');
            if (!$reviewRequired && !$staleDraft && $revision !== false && $model->atualizar($studentId, $data, isset($_POST['confirmar_duplicidade']), (int) $_SESSION['usuario_id'], $revision)) {
                unset($_SESSION['student_edit_reviews'][$studentId]);
                $this->redirectWithFlash('aluno/perfil/' . $studentId, 'success', 'Aluno atualizado com sucesso.');
            }

            $possibleDuplicate = $model->lastErrorCode() === 'possible_duplicate';
            $errorCode = $reviewRequired ? 'review_required' : ($staleDraft ? 'stale_revision' : ($revision === false ? 'invalid_revision' : $model->lastErrorCode()));
            if (in_array($errorCode, ['stale_revision', 'review_required'], true)) {
                $student = $model->buscarPorId($studentId, AccessPolicy::allows('student.health'), true);
                if (!$student) { render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.'); }
                $token = bin2hex(random_bytes(16));
                foreach ($_SESSION['student_edit_reviews'] ?? [] as $reviewId => $entry) {
                    if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < time()) { unset($_SESSION['student_edit_reviews'][$reviewId]); }
                }
                $_SESSION['student_edit_reviews'][$studentId] = ['token' => $token, 'expires' => time() + 1800];
                if (count($_SESSION['student_edit_reviews']) > 10) { unset($_SESSION['student_edit_reviews'][array_key_first($_SESSION['student_edit_reviews'])]); }
                $conflictReview = ['current' => $student, 'attempt' => $data, 'token' => $token, 'sourceRevision' => $staleDraft ? $draftSourceRevision : $data['revisao']];
                $data['revisao'] = (string) $student['revisao'];
                if ((int) $student['possui_movimentacao'] === 1) { $data['id_turma'] = (string) $student['id_turma']; }
            }
            $errors[] = $this->studentError($errorCode);
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
            'possibleDuplicate' => $possibleDuplicate,
            'studentId' => $studentId,
            'turmas' => $turmas,
            'classChangeLocked' => (int) $student['possui_movimentacao'] === 1,
            'currentClass' => $student,
            'conflictReview' => $conflictReview,
            'canManageMovement' => Auth::isAdmin(),
            'canViewHealth' => AccessPolicy::allows('student.health'),
        ]);
    }

    public function perfil(string $id): void
    {
        $studentId = $this->validId($id);
        $canViewHealth = AccessPolicy::allows('student.health');
        $canViewContacts = AccessPolicy::allows('student.contacts');
        $model = new Aluno();
        $profile = $canViewHealth || $canViewContacts ? $model->perfil($studentId, null, $canViewHealth, $canViewContacts) : $model->perfilBasico($studentId);

        if (!$profile) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $student = $profile['student'];
        if (!$canViewHealth) {
            foreach (array_keys($student) as $key) {
                if (str_starts_with($key, 'dva_') || $key === 'data_vencimento') { unset($student[$key]); }
            }
        }
        if (!$canViewContacts) { unset($student['telefone_aluno'], $student['telefone_responsavel']); }
        AuditLogger::recordRequired(Model::getConexao(), 'student.profile_read', AuditLogger::SUCCESS,
            (int) $_SESSION['usuario_id'], null, 'Consulta de cadastro escolar autorizada.', 'student', $studentId);

        $this->view('alunos/perfil', [
            'title' => 'Perfil do Aluno',
            'student' => $student,
            'history' => $canViewHealth ? ($profile['dva_history'] ?? []) : [],
            'movements' => AccessPolicy::allows('student.manage') ? (new MovimentacaoAlunos())->historico($studentId) : [],
            'schoolLinks' => AccessPolicy::allows('student.manage') ? (new VinculoEscolar())->historico($studentId) : [],
            'canManageStudent' => Auth::isAdmin(),
            'canEditStudent' => AccessPolicy::allows('student.manage'),
            'canViewHealth' => $canViewHealth,
            'canViewContacts' => $canViewContacts,
            'canArchivePassive' => Auth::isAdmin() && (int) $profile['student']['ativo'] === 0,
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

        $this->redirectWithFlash(
            'aluno/perfil/' . $studentId,
            'success',
            $active === 1 ? 'Aluno reativado com sucesso.' : 'Aluno inativado com sucesso.'
        );
    }

    public function dva(string $id): void
    {
        AccessPolicy::require('student.health');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { AccessPolicy::require('student.manage'); }
        $studentId = $this->validId($id);
        $student = (new Aluno())->buscarPorId($studentId, true, false);

        if (!$student) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.');
        }

        $data = [
            'data_vencimento' => mb_substr(trim(input_string($_POST['data_vencimento'] ?? '')), 0, 10, 'UTF-8'),
            'observacao' => mb_substr(trim(input_string($_POST['observacao'] ?? '')), 0, Dva::OBSERVATION_MAX_LENGTH + 1, 'UTF-8'),
        ];
        $errors = [];
        $current = (new Dva())->atualDoAluno($studentId);
        $statusService = new DvaStatus();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { $this->recordSensitiveRead('dva_individual', 1, $studentId); }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Dva();
            $result = $model->registrar(
                $studentId,
                $data['data_vencimento'],
                $data['observacao'],
                (int) $_SESSION['usuario_id']
            );

            if ($result !== false) {
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
            'currentStatus' => $statusService->classify(is_array($current) ? (string) $current['data_vencimento'] : null),
            'currentDays' => $statusService->daysRemaining(is_array($current) ? (string) $current['data_vencimento'] : null),
            'data' => $data,
            'errors' => $errors,
            'canManageHealth' => AccessPolicy::allows('student.manage'),
        ]);
    }

    public function painelDva(): void
    {
        AccessPolicy::require('student.health');
        $filters = $this->filters();
        $filters['ativo'] = '1';
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $result = (new Aluno())->paginate($filters, (int) $page, 20, null, true, false);
        $this->recordSensitiveRead('lista_dva', count($result['items']));

        $this->view('alunos/dva-index', [
            'title' => 'Painel de DVAs',
            'filters' => $filters,
            'result' => $result,
        ]);
    }

    /** @return array<string,string> */
    private function filters(): array
    {
        if (input_string($_GET['dva'] ?? '') !== '' && !AccessPolicy::allows('student.health')) {
            AccessPolicy::require('student.health');
        }
        $active = input_string($_GET['ativo'] ?? '1');

        return [
            'q' => mb_substr(trim(input_string($_GET['q'] ?? '')), 0, 100, 'UTF-8'),
            'turma' => mb_substr(trim(input_string($_GET['turma'] ?? '')), 0, 20, 'UTF-8'),
            'ativo' => in_array($active, ['0', '1', 'todos'], true) ? ($active === 'todos' ? '' : $active) : '1',
            'dva' => in_array(input_string($_GET['dva'] ?? ''), DvaStatus::ALL, true) ? (string) $_GET['dva'] : '',
        ];
    }

    /** @return array<string,string> */
    private function formData(): array
    {
        if (!AccessPolicy::allows('student.health')
            && (input_string($_POST['data_vencimento'] ?? '') !== '' || input_string($_POST['observacao'] ?? '') !== '')) {
            AccessPolicy::require('student.health');
        }
        return [
            'revisao' => input_string($_POST['revisao'] ?? ''),
            'nome_completo' => mb_substr(input_string($_POST['nome_completo'] ?? ''), 0, Aluno::NAME_MAX_LENGTH + 1, 'UTF-8'),
            'data_nascimento' => mb_substr(trim(input_string($_POST['data_nascimento'] ?? '')), 0, 10, 'UTF-8'),
            'id_turma' => mb_substr(trim(input_string($_POST['id_turma'] ?? '')), 0, 20, 'UTF-8'),
            'telefone_aluno' => mb_substr(trim(input_string($_POST['telefone_aluno'] ?? '')), 0, 30, 'UTF-8'),
            'telefone_responsavel' => mb_substr(trim(input_string($_POST['telefone_responsavel'] ?? '')), 0, 30, 'UTF-8'),
            'data_vencimento' => mb_substr(trim(input_string($_POST['data_vencimento'] ?? '')), 0, 10, 'UTF-8'),
            'observacao' => mb_substr(trim(input_string($_POST['observacao'] ?? '')), 0, Dva::OBSERVATION_MAX_LENGTH + 1, 'UTF-8'),
        ];
    }

    private function studentError(?string $code): string
    {
        return match ($code) {
            'review_required' => 'Confira a comparação e marque a confirmação de revisão antes de salvar. Nenhuma alteração foi salva.',
            'stale_revision' => 'Outra pessoa atualizou este aluno. Sua tentativa foi preservada abaixo; compare com os dados atuais antes de salvar.',
            'invalid_revision' => 'Este formulário está desatualizado ou sem revisão válida. Reabra o cadastro e confira os dados atuais antes de salvar.',
            'active_passive_record' => 'O aluno possui um registro ativo no Arquivo Passivo. Abra esse registro e inative-o pelo fluxo do Arquivo Passivo antes de reativar o aluno. O histórico será preservado.',
            'class_change_requires_movement' => Auth::isAdmin()
                ? 'Este aluno possui histórico de movimentação escolar. Para alterar a turma, use Movimentar alunos e confira a progressão ou regressão adequada. Os dados enviados não foram salvos.'
                : 'Este aluno possui histórico de movimentação escolar. Para alterar a turma, solicite a um administrador a movimentação adequada. Os dados enviados não foram salvos.',
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

    private function recordSensitiveRead(string $context, int $quantity, ?int $studentId = null): void
    {
        AuditLogger::recordRequired(Model::getConexao(), 'student.sensitive_read', AuditLogger::SUCCESS,
            (int) $_SESSION['usuario_id'], null, 'Contexto=' . $context . '; quantidade=' . $quantity . '.', 'student', $studentId);
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
