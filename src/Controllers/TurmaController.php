<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Turma.php';

final class TurmaController extends Controller
{
    public function index(): void
    {
        $search = mb_substr(trim(input_string($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $activeRaw = input_string($_GET['ativo'] ?? '');
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
        $data = ['nome_turma' => '', 'ano_letivo' => date('Y'), 'revisao' => ''];
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();
            $model = new Turma();
            $year = $this->parseSchoolYear($data['ano_letivo']);

            if ($year !== null && $model->cadastrar($data['nome_turma'], $year, (int) $_SESSION['usuario_id']) !== false) {
                $this->redirectWithFlash('turma', 'success', 'Turma cadastrada com sucesso.');
            }

            $errors[] = $this->errorMessage($year === null ? 'invalid_year' : $model->lastErrorCode());
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

        $data = ['nome_turma' => (string) $class['nome_turma'], 'ano_letivo' => (string) $class['ano_letivo'], 'revisao' => (string) $class['revisao']];
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();

            $revision = filter_var($data['revisao'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $year = $this->parseSchoolYear($data['ano_letivo']);
            if ($revision !== false && $year !== null && $model->atualizar($classId, $data['nome_turma'], $year, (int) $_SESSION['usuario_id'], $revision)) {
                $this->redirectWithFlash('turma', 'success', 'Turma atualizada com sucesso.');
            }

            $errors[] = $this->errorMessage($revision === false ? 'invalid_revision' : ($year === null ? 'invalid_year' : $model->lastErrorCode()));
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

        if (!$model->definirAtiva($classId, $active === 1, (int) $_SESSION['usuario_id'])) {
            $blocked = $model->lastErrorCode() === 'active_students';
            AuditLogger::record(
                'class.status_change_blocked', $blocked ? AuditLogger::BLOCKED : AuditLogger::FAILURE,
                (int) $_SESSION['usuario_id'], null,
                $blocked ? 'Turma possui alunos ativos vinculados.' : 'Alteração de situação não concluída.',
                'class', $classId
            );
            $this->redirectWithFlash('turma', 'danger', $this->errorMessage($model->lastErrorCode()));
        }

        $this->redirectWithFlash('turma', 'success', $active === 1 ? 'Turma reativada.' : 'Turma inativada.');
    }

    /** @return array{nome_turma:string,ano_letivo:string,revisao:string} */
    private function formData(): array
    {
        return [
            'revisao' => input_string($_POST['revisao'] ?? ''),
            'nome_turma' => mb_substr(input_string($_POST['nome_turma'] ?? ''), 0, 101, 'UTF-8'),
            'ano_letivo' => input_string($_POST['ano_letivo'] ?? ''),
        ];
    }

    private function parseSchoolYear(string $value): ?int
    {
        if (preg_match('/\A[0-9]{4}\z/', $value) !== 1) {
            return null;
        }

        $year = (int) $value;
        return Turma::validSchoolYear($year) ? $year : null;
    }

    private function errorMessage(?string $code): string
    {
        return match ($code) {
            'stale_revision', 'invalid_revision' => 'Este formulário está desatualizado ou sem revisão válida. Reabra o cadastro e confira os dados atuais antes de salvar.',
            'invalid_year' => sprintf('Informe o ano letivo com quatro dígitos, entre %d e %d.', Turma::MIN_SCHOOL_YEAR, Turma::maximumSchoolYear()),
            'duplicate_class' => 'Já existe uma turma com esse nome e ano letivo.',
            'active_students' => 'A turma possui alunos ativos. Remaneje ou inative esses alunos antes de inativar a turma.',
            'not_found' => 'Turma não encontrada.',
            default => 'Informe um nome com até 100 caracteres.',
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
