<?php
declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/FormDraft.php';
require_once ROOT_PATH . '/src/Core/AccessPolicy.php';

final class FormDraftController extends Controller
{
    public function read(): void
    {
        $user = (int) $_SESSION['usuario_id'];
        $target = input_string($_GET['form'] ?? '');
        $this->authorizeTarget($target);
        $draft = FormDraft::read($user, $target);
        if (str_starts_with($target, 'aluno/') && !AccessPolicy::allows('student.health') && $draft !== null) {
            unset($draft['fields']['data_vencimento'], $draft['fields']['observacao']);
        }
        if (str_starts_with($target, 'aluno/') && !AccessPolicy::allows('student.contacts') && $draft !== null) {
            unset($draft['fields']['telefone_aluno'], $draft['fields']['telefone_responsavel']);
        }
        $this->respond(['draft' => $draft, 'context' => FormDraft::open($user, $target)]);
    }

    public function save(): void
    {
        $user = (int) $_SESSION['usuario_id'];
        $target = input_string($_POST['form'] ?? '');
        $this->authorizeTarget($target, true);
        if (FormDraft::target($target) !== $target) { $this->respond(['ok' => false], 422); return; }
        if (!FormDraft::acceptsContext($user, $target, input_string($_POST['context'] ?? ''))) { $this->respond(['ok' => false], 409); return; }
        if (input_string($_POST['operation'] ?? '') === 'discard') {
            FormDraft::discard($user, $target);
            $this->respond(['ok' => true]);
            return;
        }
        $encoded = input_string($_POST['fields'] ?? '');
        if (strlen($encoded) > 65536) { $this->respond(['ok' => false], 422); return; }
        try { $fields = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $this->respond(['ok' => false], 422); return; }
        if (str_starts_with($target, 'aluno/') && !AccessPolicy::allows('student.health') && is_array($fields)) {
            if (input_string($fields['data_vencimento'] ?? '') !== '' || input_string($fields['observacao'] ?? '') !== '') {
                AccessPolicy::require('student.health');
            }
        }
        if (str_starts_with($target, 'aluno/') && !AccessPolicy::allows('student.contacts') && is_array($fields)) {
            if (input_string($fields['telefone_aluno'] ?? '') !== '' || input_string($fields['telefone_responsavel'] ?? '') !== '') {
                AccessPolicy::require('student.contacts');
            }
        }
        $ok = is_array($fields) && FormDraft::save($user, $target, $fields, input_string($_POST['revision'] ?? ''));
        $this->respond(['ok' => $ok], $ok ? 200 : 422);
    }

    /** @param array<string,mixed> $data */
    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function authorizeTarget(string $target, bool $write = false): void
    {
        if (str_starts_with($target, 'aluno/dva/')) {
            AccessPolicy::require('student.health');
            if ($write) { AccessPolicy::require('student.manage'); }
        }
        elseif (str_starts_with($target, 'aluno/')) { AccessPolicy::require('student.manage'); }
    }
}
