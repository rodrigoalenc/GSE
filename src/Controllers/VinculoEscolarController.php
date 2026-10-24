<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Services/VinculoEscolar.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Model/Passivo.php';

final class VinculoEscolarController extends Controller
{
    public function transferir(string $id): void
    {
        $this->show('transferencia', $this->validId($id));
    }

    public function retornar(string $id): void
    {
        $this->show('retorno', $this->validId($id));
    }

    public function previewTransferencia(string $id): void
    {
        $this->preview('transferencia', $this->validId($id));
    }

    public function previewRetorno(string $id): void
    {
        $this->preview('retorno', $this->validId($id));
    }

    private function show(string $type, int $id): void
    {
        $this->requireAdmin();
        $service = new VinculoEscolar();
        $record = $type === 'retorno' ? (new Passivo())->buscarPorId($id) : null;
        $student = $type === 'retorno' ? ($record && $record['aluno_origem_id'] !== null
            ? (new Aluno())->buscarPorId((int) $record['aluno_origem_id']) : false) : (new Aluno())->buscarPorId($id);
        if (!$student || ($type === 'retorno' && !$record)) {
            render_http_error(404, 'Vínculo não encontrado', 'O retorno exige um registro vinculado ao cadastro original do aluno.', 'passivo');
        }
        if (($type === 'retorno' && ((int) $record['ativo'] !== 1 || (int) $student['ativo'] !== 0))
            || ($type === 'transferencia' && (int) $student['ativo'] !== 1)) {
            render_http_error(422, 'Situação incompatível', $type === 'retorno'
                ? 'O aluno deve estar inativo e seu registro no Arquivo Passivo deve estar ativo.'
                : 'A transferência exige aluno ativo.', $type === 'retorno' ? 'passivo/detalhes/' . $id : 'aluno/perfil/' . $id);
        }
        $classes = $service->turmas();
        $source = array_column($classes, null, 'id')[(int) $student['id_turma']] ?? null;
        $classes = array_values(array_filter($classes, static function (array $class) use ($type, $source): bool {
            $valid = (int) $class['ativo'] === 1 && $class['serie'] !== null && $class['ano_letivo'] !== null
                && (int) $class['ano_letivo'] >= 2000 && (int) $class['ano_letivo'] <= (int) date('Y') + 5;
            return $valid && ($type === 'retorno' || ($source !== null && (int) $source['ativo'] === 1
                && (int) $class['id'] !== (int) $source['id'] && $source['serie'] === $class['serie']
                && $source['ano_letivo'] !== null && (int) $source['ano_letivo'] === (int) $class['ano_letivo']));
        }));
        $previewToken = input_string($_GET['preview'] ?? '');
        $batch = $this->getPreview($previewToken);
        $preview = $batch['plan'] ?? null;
        if ($preview !== null && ($preview['tipo'] !== $type
            || (int) ($type === 'retorno' ? $preview['passivo']['id'] : $preview['aluno']['id']) !== $id)) {
            $preview = null;
        }
        if ($previewToken !== '' && $preview === null) {
            definir_flash('warning', 'A prévia expirou ou não pertence a este vínculo. Escolha a turma e confira novamente.');
        }
        $this->view('alunos/vinculo-escolar', [
            'title' => $type === 'retorno' ? 'Retorno do Arquivo Passivo' : 'Transferir entre turmas',
            'type' => $type, 'student' => $student, 'record' => $record, 'classes' => $classes,
            'preview' => $preview, 'previewToken' => $preview === null ? '' : $previewToken,
            'requestToken' => $preview === null ? '' : $batch['request_token'],
            'selected' => (int) ($preview['destino']['id'] ?? input_string($_GET['destino'] ?? '0')),
            'path' => $this->path($type, $id),
            'previewPath' => $type === 'retorno' ? 'passivo/retornar/preview/' . $id : 'aluno/transferir/preview/' . $id,
        ]);
    }

    private function preview(string $type, int $id): void
    {
        $this->requireAdmin();
        $destination = filter_var(input_string($_POST['destino'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $service = new VinculoEscolar();
        $plan = $type === 'retorno' ? $service->previewRetorno($id, (int) $destination, $this->actor())
            : $service->previewTransferencia($id, (int) $destination, $this->actor());
        if ($plan === false) {
            $this->redirectWithFlash($this->path($type, $id), 'danger', $service->errorMessage());
        }
        $this->expirePreviews();
        while (count($_SESSION['school_link_previews']) >= 10) {
            array_shift($_SESSION['school_link_previews']);
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['school_link_previews'][$token] = ['actor' => $this->actor(), 'created_at' => time(),
            'request_token' => bin2hex(random_bytes(16)), 'plan' => $plan];
        redirect($this->path($type, $id) . '?preview=' . $token . '#school-link-review');
    }

    public function confirmar(): void
    {
        $this->requireAdmin();
        $token = input_string($_POST['preview_token'] ?? '');
        $batch = $this->getPreview($token);
        if ($batch === null) {
            $this->redirectWithFlash('aluno', 'danger', 'A prévia expirou. Abra o aluno ou o registro passivo e confira a movimentação novamente.');
        }
        $plan = $batch['plan'];
        $id = (int) ($plan['tipo'] === 'retorno' ? $plan['passivo']['id'] : $plan['aluno']['id']);
        $path = $this->path($plan['tipo'], $id);
        if (!hash_equals($batch['request_token'], input_string($_POST['_request_token'] ?? ''))
            || input_string($_POST['confirmar'] ?? '') !== '1') {
            $this->redirectWithFlash($path . '?preview=' . $token . '#school-link-review', 'danger',
                'Marque a confirmação após conferir o aluno e a turma de destino.');
        }
        $service = new VinculoEscolar();
        $result = $service->confirmar($plan, $this->actor(), $batch['request_token']);
        if ($result === false) {
            unset($_SESSION['school_link_previews'][$token]);
            $this->redirectWithFlash($path, 'danger', $service->errorMessage());
        }
        unset($_SESSION['school_link_previews'][$token]);
        $this->redirectWithFlash('aluno/perfil/' . (int) $plan['aluno']['id'], 'success', $plan['tipo'] === 'retorno'
            ? 'Aluno reativado na turma escolhida. O vínculo ativo no Arquivo Passivo foi encerrado e o histórico foi preservado.'
            : 'Transferência concluída entre turmas da mesma série. O histórico foi preservado.');
    }

    /** @return array<string,mixed>|null */
    private function getPreview(string $token): ?array
    {
        $this->expirePreviews();
        $batch = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1 ? ($_SESSION['school_link_previews'][$token] ?? null) : null;
        return is_array($batch) && ($batch['actor'] ?? null) === $this->actor() && is_array($batch['plan'] ?? null) ? $batch : null;
    }

    private function expirePreviews(): void
    {
        if (!is_array($_SESSION['school_link_previews'] ?? null)) {
            $_SESSION['school_link_previews'] = [];
        }
        foreach ($_SESSION['school_link_previews'] as $token => $batch) {
            if (!is_array($batch) || (int) ($batch['created_at'] ?? 0) < time() - 1800) {
                unset($_SESSION['school_link_previews'][$token]);
            }
        }
    }

    private function path(string $type, int $id): string
    {
        return ($type === 'retorno' ? 'passivo/retornar/' : 'aluno/transferir/') . $id;
    }

    private function validId(string $id): int
    {
        $this->requireAdmin();
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            render_http_error(404, 'Vínculo não encontrado', 'O identificador informado não é válido.', 'aluno');
        }
        return $value;
    }

    private function actor(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }
}
