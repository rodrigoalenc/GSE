<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Turma.php';

final class TurmaController extends Controller
{
    public function index(): void
    {
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $activeRaw = (string) ($_GET['ativo'] ?? '');
        $active = in_array($activeRaw, ['0', '1'], true) ? $activeRaw === '1' : null;

        $this->view('turmas/index', [
            'title' => 'Gestão de Turmas',
            'items' => (new Turma())->listar($search, $active),
            'search' => $search,
            'activeFilter' => $activeRaw,
        ]);
    }

    public function criar(): void
    {
        $data = $this->formData();
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Turma();
            $id = $model->cadastrar($data['nome_turma'], (int) $data['ano_letivo']);

            if ($id !== false) {
                AuditLogger::record(
                    'class.created', AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
                    'Turma cadastrada.', 'class', $id
                );
                $this->redirectWithFlash('turma', 'success', 'Turma cadastrada com sucesso.');
            }

            $errors[] = $this->errorMessage($model->lastErrorCode());
            http_response_code(422);
        }

        $this->view('turmas/form', [
            'title' => 'Cadastrar Turma', 'data' => $data, 'errors' => $errors, 'editing' => false,
        ]);
    }

    public function editar(string $id): void
    {
        $classId = $this->validId($id);
        $model = new Turma();
        $class = $model->buscarPorId($classId);

        if (!$class) {
            render_http_error(404, 'Turma não encontrada', 'O cadastro solicitado não existe.');
        }

        $data = ['nome_turma' => (string) $class['nome_turma'], 'ano_letivo' => (string) $class['ano_letivo']];
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();

            if ($model->atualizar($classId, $data['nome_turma'], (int) $data['ano_letivo'])) {
                AuditLogger::record(
                    'class.updated', AuditLogger::SUCCESS, (int) $_SESSION['usuario_id'], null,
                    'Turma atualizada.', 'class', $classId
                );
                $this->redirectWithFlash('turma', 'success', 'Turma atualizada com sucesso.');
            }

            $errors[] = $this->errorMessage($model->lastErrorCode());
            http_response_code(422);
        }

        $this->view('turmas/form', [
            'title' => 'Editar Turma', 'data' => $data, 'errors' => $errors,
            'editing' => true, 'classId' => $classId,
        ]);
    }

    public function status(string $id): void
    {
        $classId = $this->validId($id);
        $active = filter_var($_POST['ativo'] ?? null, FILTER_VALIDATE_INT);

        if (!in_array($active, [0, 1], true)) {
            render_http_error(422, 'Solicitação inválida', 'A situação informada não é válida.', 'turma');
        }

        $model = new Turma();

        if (!$model->definirAtiva($classId, $active === 1)) {
            $blocked = $model->lastErrorCode() === 'active_students';
            AuditLogger::record(
                'class.status_change_blocked', $blocked ? AuditLogger::BLOCKED : AuditLogger::FAILURE,
                (int) $_SESSION['usuario_id'], null,
                $blocked ? 'Turma possui alunos ativos vinculados.' : 'Alteração de situação não concluída.',
                'class', $classId
            );
            $this->redirectWithFlash('turma', 'danger', $this->errorMessage($model->lastErrorCode()));
        }

        AuditLogger::record(
            $active === 1 ? 'class.reactivated' : 'class.deactivated', AuditLogger::SUCCESS,
            (int) $_SESSION['usuario_id'], null,
            $active === 1 ? 'Turma reativada.' : 'Turma inativada.', 'class', $classId
        );
        $this->redirectWithFlash('turma', 'success', $active === 1 ? 'Turma reativada.' : 'Turma inativada.');
    }

    /** @return array{nome_turma:string,ano_letivo:string} */
    private function formData(): array
    {
        return [
            'nome_turma' => mb_substr((string) ($_POST['nome_turma'] ?? ''), 0, 101, 'UTF-8'),
            'ano_letivo' => mb_substr(trim((string) ($_POST['ano_letivo'] ?? date('Y'))), 0, 4, 'UTF-8'),
        ];
    }

    private function errorMessage(?string $code): string
    {
        return match ($code) {
            'duplicate_class' => 'Já existe uma turma com esse nome e ano letivo.',
            'active_students' => 'A turma possui alunos ativos. Remaneje ou inative esses alunos antes de inativar a turma.',
            'not_found' => 'Turma não encontrada.',
            default => 'Informe um nome com até 100 caracteres e um ano letivo válido.',
        };
    }

    private function validId(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);

        if ($value === false || (int) $value < 1) {
            render_http_error(404, 'Turma não encontrada', 'O identificador informado não é válido.');
        }

        return (int) $value;
    }
}
