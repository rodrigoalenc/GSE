<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Passivo.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Services/PassivoCsvService.php';

final class PassivoController extends Controller
{
    public function index(): void
    {
        $this->renderIndex(null);
    }

    public function inativos(): void
    {
        $this->renderIndex('0');
    }

    public function criar(): void
    {
        $state = $this->consumeFormState('create');
        $data = is_array($state['data'] ?? null) ? $state['data'] : $this->formData();
        $errors = is_array($state['errors'] ?? null) ? array_values(array_map('strval', $state['errors'])) : [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $model = new Passivo();
            $id = $model->cadastrar($data, $this->actorId());

            if ($id !== false) {
                $this->redirectWithFlash('passivo/detalhes/' . $id, 'success', 'Registro adicionado ao Arquivo Passivo.');
            }

            $this->storeFormState('create', $data, [$model->validationMessage($model->lastErrorCode())]);
            redirect('passivo/criar');
        }

        $this->view('passivo/form', [
            'title' => 'Novo registro do Arquivo Passivo',
            'data' => $data,
            'errors' => $errors,
            'editing' => false,
        ]);
    }

    public function detalhes(string $id): void
    {
        $recordId = $this->validId($id);
        $record = (new Passivo())->buscarPorId($recordId);

        if (!$record) {
            render_http_error(404, 'Registro não encontrado', 'O item solicitado não existe no Arquivo Passivo.', 'passivo');
        }

        $this->view('passivo/detalhes', [
            'title' => 'Detalhes do Arquivo Passivo',
            'record' => $record,
            'canRestore' => Auth::isAdmin(),
        ]);
    }

    public function editar(string $id): void
    {
        $recordId = $this->validId($id);
        $model = new Passivo();
        $record = $model->buscarPorId($recordId);

        if (!$record) {
            render_http_error(404, 'Registro não encontrado', 'O item solicitado não existe no Arquivo Passivo.', 'passivo');
        }

        $defaultData = [
            'nome_completo' => (string) $record['nome_completo'],
            'data_nascimento' => (string) ($record['data_nascimento'] ?? ''),
            'numero' => (string) ($record['numero'] ?? ''),
            'caixa' => (string) ($record['caixa'] ?? ''),
        ];
        $state = $this->consumeFormState('edit_' . $recordId);
        $data = is_array($state['data'] ?? null) ? $state['data'] : $defaultData;
        $errors = is_array($state['errors'] ?? null) ? array_values(array_map('strval', $state['errors'])) : [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = $this->formData();

            if ($model->atualizar($recordId, $data, $this->actorId())) {
                $this->redirectWithFlash('passivo/detalhes/' . $recordId, 'success', 'Registro atualizado com sucesso.');
            }

            $this->storeFormState('edit_' . $recordId, $data, [$model->validationMessage($model->lastErrorCode())]);
            redirect('passivo/editar/' . $recordId);
        }

        $this->view('passivo/form', [
            'title' => 'Editar registro do Arquivo Passivo',
            'data' => $data,
            'errors' => $errors,
            'editing' => true,
            'recordId' => $recordId,
        ]);
    }

    public function excluir(string $id): void
    {
        // UC004: a exclusão lógica não aceita uma situação enviada pelo cliente.
        $this->changeStatus($id, false);
    }

    public function status(string $id): void
    {
        $active = filter_var($_POST['ativo'] ?? null, FILTER_VALIDATE_INT);

        if (!in_array($active, [0, 1], true)) {
            render_http_error(422, 'Solicitação inválida', 'A situação informada não é válida.', 'passivo');
        }

        $this->changeStatus($id, $active === 1);
    }

    private function changeStatus(string $id, bool $active): void
    {
        $recordId = $this->validId($id);
        $model = new Passivo();

        if (!$model->definirAtivo($recordId, $active, $this->actorId())) {
            if ($model->lastErrorCode() === 'not_found') {
                render_http_error(404, 'Registro não encontrado', 'O item solicitado não existe no Arquivo Passivo.', 'passivo');
            }

            AuditLogger::record(
                $active ? 'passive.reactivated' : 'passive.deactivated',
                AuditLogger::FAILURE,
                $this->actorId(),
                null,
                'Alteração de situação do arquivo passivo não concluída.',
                'passive_record',
                $recordId
            );
            $this->redirectWithFlash('passivo/detalhes/' . $recordId, 'danger', $model->validationMessage($model->lastErrorCode()));
        }

        $this->redirectWithFlash(
            'passivo/detalhes/' . $recordId,
            'success',
            $active ? 'Registro restaurado com sucesso.' : 'Registro excluído do acervo ativo. Seus dados e histórico foram preservados.'
        );
    }

    public function importar(): void
    {
        $token = (string) ($_GET['preview'] ?? '');
        $summaries = $_SESSION['passivo_import_summaries'] ?? [];
        $preview = preg_match('/^[a-f0-9]{64}$/', $token) === 1 && is_array($summaries)
            && isset($summaries[$token]) && is_array($summaries[$token])
            ? $summaries[$token]
            : null;

        $this->view('passivo/importar', [
            'title' => 'Importar Arquivo Passivo',
            'preview' => $preview,
            'previewToken' => $preview === null ? '' : $token,
            'maxFileSize' => PassivoCsvService::MAX_FILE_SIZE,
            'maxRows' => PassivoCsvService::MAX_ROWS,
        ]);
    }

    public function previewImport(): void
    {
        $service = new PassivoCsvService();
        $preview = $service->previewUpload(
            is_array($_FILES['arquivo_csv'] ?? null) ? $_FILES['arquivo_csv'] : [],
            $this->actorId(),
            new Passivo()
        );

        if ($preview === false) {
            AuditLogger::record(
                'passive.import_failed', AuditLogger::FAILURE, $this->actorId(), null,
                'Prévia de importação recusada.', 'passive_import'
            );
            $this->redirectWithFlash('passivo/importar', 'danger', $service->errorMessage());
        }

        $_SESSION['passivo_import_summaries'][$preview['token']] = $preview;
        AuditLogger::record(
            'passive.import_previewed', AuditLogger::SUCCESS, $this->actorId(), null,
            sprintf(
                'Prévia validada: %d válidos, %d inválidos, %d duplicados e %d conflitos.',
                $preview['valid'], $preview['invalid'], $preview['duplicate'], $preview['conflict']
            ),
            'passive_import'
        );
        redirect('passivo/importar?preview=' . $preview['token']);
    }

    public function confirmImport(): void
    {
        $token = (string) ($_POST['preview_token'] ?? '');
        $service = new PassivoCsvService();
        $count = $service->confirm($token, $this->actorId(), new Passivo());
        unset($_SESSION['passivo_import_summaries'][$token]);

        if ($count === false) {
            AuditLogger::record(
                'passive.import_failed', AuditLogger::FAILURE, $this->actorId(), null,
                'Confirmação de importação não concluída.', 'passive_import'
            );
            $this->redirectWithFlash('passivo/importar', 'danger', $service->errorMessage());
        }

        $this->redirectWithFlash('passivo', 'success', sprintf('%d registro(s) importado(s) sem apagar o acervo atual.', $count));
    }

    public function ferramentas(): void
    {
        $token = (string) ($_GET['preview'] ?? '');
        $previews = $_SESSION['passivo_enumeration_previews'] ?? [];
        $preview = preg_match('/^[a-f0-9]{64}$/', $token) === 1 && is_array($previews)
            && isset($previews[$token]) && is_array($previews[$token])
            ? $previews[$token]
            : null;

        if (is_array($preview) && ((int) ($preview['actor_id'] ?? 0) !== $this->actorId()
            || (int) ($preview['expires_at'] ?? 0) < time())) {
            unset($_SESSION['passivo_enumeration_previews'][$token]);
            $preview = null;
        }

        $this->view('passivo/ferramentas', [
            'title' => 'Ferramentas do Arquivo Passivo',
            'boxes' => (new Passivo())->caixas(),
            'preview' => $preview,
            'previewToken' => $preview === null ? '' : $token,
        ]);
    }

    public function previewEnumeration(): void
    {
        $model = new Passivo();
        $preview = $model->previewEnumeracao((string) ($_POST['caixa'] ?? ''));

        if ($preview === false) {
            AuditLogger::record(
                'passive.enumeration_previewed', AuditLogger::FAILURE, $this->actorId(), null,
                'Prévia de enumeração recusada.', 'passive_box'
            );
            $this->redirectWithFlash('passivo/ferramentas', 'danger', $model->validationMessage($model->lastErrorCode()));
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['passivo_enumeration_previews'][$token] = [
            'actor_id' => $this->actorId(),
            'expires_at' => time() + 900,
            'caixa' => $preview['caixa'],
            'assignments' => $preview['assignments'],
        ];
        AuditLogger::record(
            'passive.enumeration_previewed', AuditLogger::SUCCESS, $this->actorId(), null,
            sprintf('Prévia de enumeração gerada para %d registros.', count($preview['assignments'])), 'passive_box'
        );
        redirect('passivo/ferramentas?preview=' . $token);
    }

    public function confirmEnumeration(): void
    {
        $token = (string) ($_POST['preview_token'] ?? '');
        $previews = $_SESSION['passivo_enumeration_previews'] ?? [];
        $preview = preg_match('/^[a-f0-9]{64}$/', $token) === 1 && is_array($previews)
            && isset($previews[$token]) && is_array($previews[$token])
            ? $previews[$token]
            : null;
        unset($_SESSION['passivo_enumeration_previews'][$token]);

        if (!is_array($preview) || (int) ($preview['actor_id'] ?? 0) !== $this->actorId()
            || (int) ($preview['expires_at'] ?? 0) < time()
            || !is_array($preview['assignments'] ?? null)) {
            $this->redirectWithFlash('passivo/ferramentas', 'danger', 'A prévia expirou ou já foi utilizada.');
        }

        $model = new Passivo();
        /** @var list<array{id:int,nome:string,numero:string}> $assignments */
        $assignments = array_values($preview['assignments']);
        $count = $model->aplicarEnumeracao((string) $preview['caixa'], $assignments, $this->actorId());

        if ($count === false) {
            AuditLogger::record(
                'passive.enumerated', AuditLogger::FAILURE, $this->actorId(), null,
                'Enumeração de caixa não concluída.', 'passive_box'
            );
            $this->redirectWithFlash('passivo/ferramentas', 'danger', $model->validationMessage($model->lastErrorCode()));
        }

        $this->redirectWithFlash('passivo?caixa=' . rawurlencode((string) $preview['caixa']), 'success', sprintf('%d registro(s) numerado(s).', $count));
    }

    public function exportar(): never
    {
        $box = (string) ($_POST['caixa'] ?? '');
        $model = new Passivo();
        $rows = $model->listarParaTxt($box);

        if ($rows === false) {
            $this->redirectWithFlash('passivo', 'danger', $model->validationMessage($model->lastErrorCode()));
        }

        $safeBox = preg_replace('/[^a-z0-9_-]+/', '-', src\Core\TextNormalizer::searchKey($box)) ?? 'caixa';
        $safeBox = trim(mb_substr($safeBox, 0, 40, 'UTF-8'), '-_') ?: 'caixa';
        AuditLogger::record(
            'passive.exported', AuditLogger::SUCCESS, $this->actorId(), null,
            sprintf('Listagem de caixa exportada com %d registros.', count($rows)), 'passive_box'
        );
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="listagem-caixa-' . $safeBox . '.txt"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');

        foreach ($rows as $row) {
            $number = trim((string) ($row['numero'] ?? ''));
            echo ($number === '' ? 'Sem número' : $number) . ' - ' . $this->safeSpreadsheetText($row['nome_completo']) . "\r\n";
        }

        exit;
    }

    public function arquivarAluno(string $id): void
    {
        $studentId = $this->validId($id);
        $student = (new Aluno())->buscarPorId($studentId);

        if (!$student) {
            render_http_error(404, 'Aluno não encontrado', 'O cadastro solicitado não existe.', 'aluno');
        }

        $state = $this->consumeFormState('archive_' . $studentId);
        $defaultData = [
            'caixa' => mb_substr((string) ($_POST['caixa'] ?? ''), 0, Passivo::BOX_MAX_LENGTH + 1, 'UTF-8'),
            'numero' => mb_substr((string) ($_POST['numero'] ?? ''), 0, Passivo::NUMBER_MAX_LENGTH + 1, 'UTF-8'),
        ];
        $data = is_array($state['data'] ?? null) ? $state['data'] : $defaultData;
        $errors = is_array($state['errors'] ?? null) ? array_values(array_map('strval', $state['errors'])) : [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (($_POST['confirmar'] ?? '') !== '1') {
                $errors[] = 'Confirme conscientemente o envio ao Arquivo Passivo.';
            } else {
                $model = new Passivo();
                $passiveId = $model->arquivarAluno($studentId, $data, $this->actorId());

                if ($passiveId !== false) {
                    $this->redirectWithFlash('passivo/detalhes/' . $passiveId, 'success', 'Aluno enviado ao Arquivo Passivo sem remover seu cadastro ou histórico de DVA.');
                }

                $errors[] = $model->validationMessage($model->lastErrorCode());
            }

            $this->storeFormState('archive_' . $studentId, $data, $errors);
            redirect('aluno/arquivar/' . $studentId);
        }

        $this->view('passivo/arquivar-aluno', [
            'title' => 'Enviar aluno ao Arquivo Passivo',
            'student' => $student,
            'data' => $data,
            'errors' => $errors,
        ]);
    }

    private function renderIndex(?string $forcedActive): void
    {
        $active = $forcedActive ?? (string) ($_GET['ativo'] ?? '1');
        $filters = [
            'q' => mb_substr((string) ($_GET['q'] ?? ''), 0, Passivo::SEARCH_MAX_LENGTH, 'UTF-8'),
            'caixa' => mb_substr((string) ($_GET['caixa'] ?? ''), 0, Passivo::BOX_MAX_LENGTH, 'UTF-8'),
            'ativo' => in_array($active, ['0', '1', 'todos'], true) ? ($active === 'todos' ? '' : $active) : '1',
            'ordem' => in_array((string) ($_GET['ordem'] ?? 'nome'), ['nome', 'numero', 'caixa', 'recente'], true)
                ? (string) ($_GET['ordem'] ?? 'nome') : 'nome',
        ];
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $model = new Passivo();
        $activeFilter = $filters['ativo'] === '' ? null : $filters['ativo'] === '1';
        $boxes = $model->caixas($activeFilter);

        $this->view('passivo/index', [
            'title' => 'Arquivo Passivo (Ex-Alunos)',
            'filters' => $filters,
            'result' => $model->paginate($filters, (int) $page),
            'summary' => $model->resumo(),
            'boxes' => $boxes,
            'navigation' => $filters['caixa'] === ''
                ? ['anterior' => null, 'proxima' => null, 'lista' => []]
                : $model->navegacaoCaixas($filters['caixa'], $activeFilter),
            'canAdminister' => Auth::isAdmin(),
        ]);
    }

    /** @return array{nome_completo:string,data_nascimento:string,numero:string,caixa:string} */
    private function formData(): array
    {
        return [
            'nome_completo' => mb_substr((string) ($_POST['nome_completo'] ?? ''), 0, Passivo::NAME_MAX_LENGTH + 1, 'UTF-8'),
            'data_nascimento' => mb_substr(trim((string) ($_POST['data_nascimento'] ?? '')), 0, 10, 'UTF-8'),
            'numero' => mb_substr((string) ($_POST['numero'] ?? ''), 0, Passivo::NUMBER_MAX_LENGTH + 1, 'UTF-8'),
            'caixa' => mb_substr((string) ($_POST['caixa'] ?? ''), 0, Passivo::BOX_MAX_LENGTH + 1, 'UTF-8'),
        ];
    }

    private function actorId(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    private function validId(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);

        if ($value === false || (int) $value < 1) {
            render_http_error(404, 'Registro não encontrado', 'O identificador informado não é válido.', 'passivo');
        }

        return (int) $value;
    }

    private function safeSpreadsheetText(string $value): string
    {
        $trimmed = ltrim($value);

        return preg_match('/^[=+\-@]/u', $trimmed) === 1 ? "'" . $value : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $errors
     */
    private function storeFormState(string $key, array $data, array $errors): void
    {
        $_SESSION['passivo_form_state'][$key] = ['data' => $data, 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function consumeFormState(string $key): array
    {
        $states = $_SESSION['passivo_form_state'] ?? [];
        $state = is_array($states) && isset($states[$key]) && is_array($states[$key]) ? $states[$key] : [];
        unset($_SESSION['passivo_form_state'][$key]);

        return $state;
    }
}
