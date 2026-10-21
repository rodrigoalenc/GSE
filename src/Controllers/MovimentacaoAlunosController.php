<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';

final class MovimentacaoAlunosController extends Controller
{
    public function index(): void
    {
        $service = new MovimentacaoAlunos();
        $year = $this->year();
        $class = max(0, (int) input_string($_GET['turma'] ?? '0'));
        $search = mb_substr(trim(input_string($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $type = input_string($_GET['tipo'] ?? 'progressao') === 'regressao' ? 'regressao' : 'progressao';
        $token = input_string($_GET['preview'] ?? '');
        $preview = $this->getPreview($token);
        if ($token !== '' && $preview === null) {
            definir_flash('warning', 'A prévia expirou ou já foi confirmada. Selecione os alunos novamente.');
        }
        $this->view('alunos/movimentar', [
            'title' => 'Movimentar alunos', 'year' => $year, 'classes' => $service->turmas(),
            'state' => $service->conferencia($year), 'series' => MovimentacaoAlunos::series(),
            'students' => $class > 0 || $search !== '' ? $service->alunos($class, $search) : [],
            'classId' => $class, 'search' => $search, 'type' => $type,
            'configure' => input_string($_GET['configurar'] ?? '') === '1',
            'preview' => $preview, 'previewToken' => $token,
        ]);
    }

    public function series(): void
    {
        $service = new MovimentacaoAlunos();
        $mapping = is_array($_POST['series'] ?? null) ? $_POST['series'] : [];
        $ok = $service->definirSeries($mapping, $this->actor());
        $this->redirectWithFlash($this->path(), $ok ? 'success' : 'danger',
            $ok ? 'Séries identificadas. Os nomes e os anos letivos das turmas foram preservados.' : $service->errorMessage());
    }

    public function conferencia(): void
    {
        $service = new MovimentacaoAlunos();
        if (input_string($_POST['confirmar'] ?? '') !== '1') {
            $this->redirectWithFlash($this->path(), 'danger', 'Confirme que os concluintes foram enviados ao passivo e que os alunos restantes são reprovados.');
        }
        $ok = $service->confirmarConferencia($this->year(), input_string($_POST['fingerprint'] ?? ''), $this->actor());
        $this->redirectWithFlash($this->path(), $ok ? 'success' : 'danger',
            $ok ? 'Conferência do 3º ano concluída. Os reprovados continuam ativos e a progressão está liberada.' : $service->errorMessage());
    }

    public function preview(): void
    {
        $service = new MovimentacaoAlunos();
        $ids = is_array($_POST['alunos'] ?? null) ? $_POST['alunos'] : [];
        $destination = (int) input_string($_POST['destino'] ?? '0');
        $type = input_string($_POST['tipo'] ?? '');
        // A separate submit button also supports whole-class selection without JavaScript.
        if (input_string($_POST['turma_inteira'] ?? '') === '1') {
            $source = (int) input_string($_POST['turma'] ?? '0');
            $ids = $source > 0 ? array_column($service->alunos($source), 'id') : [];
        }
        $plan = $service->preview($ids, $destination, $type, $this->year());
        if ($plan === false) {
            $this->redirectWithFlash($this->path() . '&' . http_build_query([
                'turma' => input_string($_POST['turma'] ?? ''), 'tipo' => $type,
                'q' => mb_substr(input_string($_POST['q'] ?? ''), 0, 100, 'UTF-8'),
            ]), 'danger', $service->errorMessage());
        }
        $this->expirePreviews();
        while (count($_SESSION['student_move_previews']) >= 5) {
            array_shift($_SESSION['student_move_previews']);
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['student_move_previews'][$token] = ['actor' => $this->actor(), 'created_at' => time(), 'plan' => $plan];
        redirect($this->path() . '&preview=' . $token . '#movement-preview');
    }

    public function confirmar(): void
    {
        $token = input_string($_POST['preview_token'] ?? '');
        $preview = $this->getPreview($token);
        if ($preview === null) {
            $this->redirectWithFlash($this->path(), 'danger', 'A prévia expirou ou já foi confirmada. Selecione os alunos novamente.');
        }
        if (input_string($_POST['confirmar'] ?? '') !== '1') {
            $this->redirectWithFlash($this->path() . '&preview=' . $token . '#movement-preview', 'danger', 'Confira a prévia e marque a confirmação.');
        }
        $service = new MovimentacaoAlunos();
        $result = $service->confirmar($preview, $this->actor(), $token);
        unset($_SESSION['student_move_previews'][$token]);
        $this->redirectWithFlash($this->path(), $result !== false ? 'success' : 'danger',
            $result !== false ? sprintf('%d aluno(s) movimentado(s). O histórico está disponível no perfil de cada aluno.', count($preview['ids'])) : $service->errorMessage());
    }

    private function actor(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    private function year(): int
    {
        $raw = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? ($_POST['ano'] ?? null) : ($_GET['ano'] ?? date('Y'));
        $year = filter_var($raw, FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > (int) date('Y') + 5) {
            render_http_error(422, 'Ano inválido', 'Informe um ano de referência válido.', 'aluno/movimentar');
        }
        return $year;
    }

    private function path(): string
    {
        return 'aluno/movimentar?ano=' . $this->year();
    }

    private function expirePreviews(): void
    {
        if (!is_array($_SESSION['student_move_previews'] ?? null)) {
            $_SESSION['student_move_previews'] = [];
        }
        foreach ($_SESSION['student_move_previews'] as $token => $entry) {
            if (!is_array($entry) || (int) ($entry['created_at'] ?? 0) < time() - 1800) {
                unset($_SESSION['student_move_previews'][$token]);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function getPreview(string $token): ?array
    {
        $this->expirePreviews();
        $entry = $_SESSION['student_move_previews'][$token] ?? null;
        if (!is_array($entry) || (int) ($entry['actor'] ?? 0) !== $this->actor()) {
            return null;
        }
        return $entry['plan'];
    }
}
