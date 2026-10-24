<?php
declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/FormDraft.php';

final class FormDraftController extends Controller
{
    public function read(): void
    {
        $user = (int) $_SESSION['usuario_id'];
        $target = input_string($_GET['form'] ?? '');
        $this->respond(['draft' => FormDraft::read($user, $target), 'context' => FormDraft::open($user, $target)]);
    }

    public function save(): void
    {
        $user = (int) $_SESSION['usuario_id'];
        $target = input_string($_POST['form'] ?? '');
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
}
