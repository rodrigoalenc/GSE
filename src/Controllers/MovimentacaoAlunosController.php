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
        $type = input_string($_GET['tipo'] ?? '');
        $type = in_array($type, ['passivo', 'progressao', 'regressao'], true) ? $type : '';
        $archiveClass = max(0, (int) input_string($_GET['passivo_turma'] ?? '0'));
        $classes = $service->turmas();
        if ($archiveClass > 0 && !in_array($archiveClass, array_map(static fn (array $item): int => (int) $item['id'], $classes), true)) {
            render_http_error(422, 'Turma inválida', 'Escolha uma turma cadastrada para consultar seus alunos.', 'aluno/movimentar');
        }
        $archiveSelected = null;
        $selection = input_string($_GET['passivo_selecao'] ?? '');
        if ($selection !== '') {
            $batch = preg_match('/^[a-f0-9]{64}$/D', $selection) === 1 ? ($_SESSION['passivo_archive_batches'][$selection] ?? null) : null;
            $archiveSelected = [];
            if (is_array($batch) && (int) ($batch['actor_id'] ?? 0) === $this->actor()
                && (int) ($batch['created_at'] ?? 0) >= time() - 1800
                && (int) ($batch['movimentacao_ano'] ?? 0) === $year
                && (int) ($batch['movimentacao_turma'] ?? 0) === $archiveClass) {
                $archiveSelected = $batch['ids'];
            } else {
                definir_flash('warning', 'A seleção anterior expirou. Marque os alunos que deseja enviar ao Arquivo Passivo.');
            }
        }
        $token = input_string($_GET['preview'] ?? '');
        $preview = $this->getPreview($token);
        if ($preview !== null) {
            $type = $preview['tipo'];
            $year = (int) $preview['ano'];
        } elseif ($type === '' && $archiveClass > 0) {
            $type = 'passivo';
        }
        if ($token !== '' && $preview === null) {
            definir_flash('warning', 'A prévia expirou ou já foi confirmada. Selecione os alunos novamente.');
        }
        $allClasses = $type === 'progressao' && (input_string($_GET['todas'] ?? '') === '1' || !empty($preview['todas_turmas']));
        $bulkDraft = $_SESSION['student_move_all_draft'] ?? [];
        if (!is_array($bulkDraft) || ($bulkDraft['actor'] ?? 0) !== $this->actor()
            || ($bulkDraft['ano'] ?? 0) !== $year || ($bulkDraft['created_at'] ?? 0) < time() - 1800) {
            $bulkDraft = [];
        }
        if (!empty($preview['todas_turmas'])) {
            $bulkDraft = $preview;
        }
        $this->view('alunos/movimentar', [
            'title' => 'Movimentar alunos', 'year' => $year, 'classes' => $classes,
            'state' => $service->conferencia($year), 'series' => MovimentacaoAlunos::series(),
            'students' => in_array($type, ['progressao', 'regressao'], true) && ($class > 0 || $search !== '') ? $service->alunos($class, $search) : [],
            'classId' => $class, 'search' => $search, 'type' => $type,
            'configure' => input_string($_GET['configurar'] ?? '') === '1',
            'preview' => $preview, 'previewToken' => $token,
            'archiveClassId' => $archiveClass,
            'archiveStudents' => $type === 'passivo' && $archiveClass > 0 ? $service->alunos($archiveClass) : [],
            'archiveAfter' => input_string($_GET['arquivados'] ?? '') === '1',
            'archiveSelected' => $archiveSelected,
            'allClasses' => $allClasses, 'bulkOptions' => $allClasses ? $service->opcoesTodas($year) : null,
            'bulkDraft' => $bulkDraft,
            'automaticDestination' => !$allClasses && $type === 'progressao' && $class > 0 ? $service->destinoProgressao($class, $year) : null,
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
        $bulk = input_string($_POST['todas_turmas'] ?? '') === '1';
        if ($bulk) {
            if ($type !== 'progressao' || input_string($_POST['todas_completo'] ?? '') !== '1') {
                $this->redirectWithFlash($this->path('progressao'), 'danger', 'O formulário de todas as turmas está incompleto. Reabra a seleção e confira os destinos e os alunos que devem permanecer.');
            }
            $destinations = is_array($_POST['destinos'] ?? null) ? $_POST['destinos'] : [];
            $keep = is_array($_POST['manter'] ?? null) ? $_POST['manter'] : [];
            $draftDestinations = [];
            foreach ($destinations as $source => $target) {
                if (filter_var($source, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
                    && (is_string($target) || is_int($target)) && ($target === '' || ctype_digit((string) $target))) {
                    $draftDestinations[$source] = $target;
                }
            }
            $_SESSION['student_move_all_draft'] = ['actor' => $this->actor(), 'ano' => $this->year(), 'created_at' => time(),
                'destinos' => $draftDestinations, 'manter' => array_values(array_filter($keep,
                    static fn (mixed $id): bool => (is_string($id) || is_int($id)) && ctype_digit((string) $id)))];
            $plan = $service->previewTodas($destinations, $keep, $this->year());
        } else {
            // A separate submit button also supports whole-class selection without JavaScript.
            if (input_string($_POST['turma_inteira'] ?? '') === '1') {
                $source = (int) input_string($_POST['turma'] ?? '0');
                $ids = $source > 0 ? array_column($service->alunos($source), 'id') : [];
            }
            $plan = $service->preview($ids, $destination, $type, $this->year());
        }
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
        redirect($this->path($type) . '&preview=' . $token . '#movement-preview');
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
        $_POST['ano'] = $preview['ano'];
        $_POST['todas_turmas'] = !empty($preview['todas_turmas']) ? '1' : '0';
        $result = !empty($preview['todas_turmas']) ? $service->confirmarTodas($preview, $this->actor(), $token)
            : $service->confirmar($preview, $this->actor(), $token);
        if ($result !== false && !empty($preview['todas_turmas'])) {
            unset($_SESSION['student_move_all_draft']);
        }
        unset($_SESSION['student_move_previews'][$token]);
        $this->redirectWithFlash($this->path($preview['tipo']), $result !== false ? 'success' : 'danger',
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

    private function path(string $type = ''): string
    {
        $type = $type !== '' ? $type : input_string($_POST['tipo'] ?? 'progressao');
        $type = in_array($type, ['progressao', 'regressao'], true) ? $type : 'progressao';
        return 'aluno/movimentar?ano=' . $this->year() . '&tipo=' . $type
            . ($type === 'progressao' && input_string($_POST['todas_turmas'] ?? '') === '1' ? '&todas=1' : '');
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
