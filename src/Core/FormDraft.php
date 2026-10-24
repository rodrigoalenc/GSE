<?php
declare(strict_types=1);

/** Temporary business fields only; never a replacement for form authorization or revisions. */
final class FormDraft
{
    private const TTL = 1800;
    private const MAX_BYTES = 65536;
    private const MAX_DRAFTS = 10;

    public static function open(int $user, string $target): string
    {
        self::prune(time());
        if ($user < 1 || (int) ($_SESSION['usuario_id'] ?? 0) !== $user || self::target($target) !== $target) { return ''; }
        $context = bin2hex(random_bytes(16));
        $_SESSION['draft_contexts'][$context] = ['user' => $user, 'target' => $target, 'expires' => time() + self::TTL];
        while (count($_SESSION['draft_contexts']) > 30) { array_shift($_SESSION['draft_contexts']); }
        return $context;
    }

    public static function acceptsContext(int $user, string $target, string $context): bool
    {
        self::prune(time());
        $entry = $_SESSION['draft_contexts'][$context] ?? null;
        return (int) ($_SESSION['usuario_id'] ?? 0) === $user && is_array($entry) && $entry['user'] === $user && $entry['target'] === $target;
    }

    public static function close(int $user, string $target): void
    {
        if ((int) ($_SESSION['usuario_id'] ?? 0) !== $user) { return; }
        self::discard($user, $target);
        foreach ($_SESSION['draft_contexts'] ?? [] as $key => $entry) {
            if (($entry['user'] ?? null) === $user && ($entry['target'] ?? null) === $target) { unset($_SESSION['draft_contexts'][$key]); }
        }
    }

    public static function target(string $path): ?string
    {
        return preg_match('~(?:^|/)((?:aluno/(?:criar|editar/[1-9][0-9]*|dva/[1-9][0-9]*)|contrato/criar|certidao/(?:cadastrar|editar/[1-9][0-9]*|renovar/[1-9][0-9]*)|passivo/(?:criar|editar/[1-9][0-9]*)))$~D', $path, $match) === 1 ? $match[1] : null;
    }

    /** @param array<mixed> $fields */
    public static function save(int $user, string $target, array $fields, string $revision = '', ?int $now = null): bool
    {
        $now ??= time();
        self::prune($now);
        if ($user < 1 || (int) ($_SESSION['usuario_id'] ?? 0) !== $user || self::target($target) !== $target) { return false; }
        try {
            if (count($fields) > 1000 || strlen(json_encode($fields, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) { return false; }
        } catch (JsonException) {
            // Invalid strings are discarded individually below; never serialize raw input.
        }
        $safe = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name) || !is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { continue; }
            $limit = self::fieldLimit($target, $name);
            if ($limit !== null) { $safe[$name] = mb_substr($value, 0, $limit); }
        }
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) { return false; }
        $entry = ['user' => $user, 'fields' => $safe, 'revision' => preg_match('/^[1-9][0-9]{0,10}$/D', $revision) === 1 ? $revision : '', 'updated' => $now];
        unset($_SESSION['form_drafts'][$target]);
        $_SESSION['form_drafts'][$target] = $entry;
        while (count($_SESSION['form_drafts']) > self::MAX_DRAFTS) { array_shift($_SESSION['form_drafts']); }
        return true;
    }

    /** @return array{user:int,fields:array<string,string>,revision:string,updated:int}|null */
    public static function read(int $user, string $target, ?int $now = null): ?array
    {
        self::prune($now ?? time());
        $entry = $_SESSION['form_drafts'][$target] ?? null;
        return $user > 0 && (int) ($_SESSION['usuario_id'] ?? 0) === $user && is_array($entry) && $entry['user'] === $user ? $entry : null;
    }

    public static function discard(int $user, string $target): void
    {
        if ((int) ($_SESSION['usuario_id'] ?? 0) === $user) { unset($_SESSION['form_drafts'][$target]); }
    }

    private static function prune(int $now): void
    {
        if (!is_array($_SESSION['draft_contexts'] ?? null)) { $_SESSION['draft_contexts'] = []; }
        foreach ($_SESSION['draft_contexts'] as $key => $entry) {
            if (!is_array($entry) || !is_int($entry['expires'] ?? null) || $entry['expires'] < $now) { unset($_SESSION['draft_contexts'][$key]); }
        }
        if (!is_array($_SESSION['form_drafts'] ?? null)) { $_SESSION['form_drafts'] = []; }
        foreach ($_SESSION['form_drafts'] as $key => $entry) {
            if (!is_array($entry) || !is_int($entry['user'] ?? null) || !is_array($entry['fields'] ?? null) || !is_string($entry['revision'] ?? null) || !is_int($entry['updated'] ?? null) || $entry['updated'] < $now - self::TTL || $entry['updated'] > $now + 60) { unset($_SESSION['form_drafts'][$key]); }
        }
    }

    private static function fieldLimit(string $target, string $name): ?int
    {
        $group = explode('/', $target)[0];
        $fields = match ($group) {
            'aluno' => ['nome_completo' => 150, 'data_nascimento' => 10, 'id_turma' => 20, 'telefone_aluno' => 30, 'telefone_responsavel' => 30, 'data_vencimento' => 10, 'observacao' => 1000],
            'contrato' => ['titulo' => 150, 'valor' => 30, 'fornecedor' => 20],
            'certidao' => ['fornecedor' => 150, 'tipo_certidao' => 150, 'data_emissao' => 10, 'data_vencimento' => 10, 'observacoes' => 2000, 'observacao' => 2000, 'id_fornecedor' => 20, 'id_tipo_certidao' => 20],
            'passivo' => ['nome_completo' => 150, 'data_nascimento' => 10, 'numero' => 60, 'caixa' => 60],
            default => [],
        };
        if (isset($fields[$name])) { return $fields[$name]; }
        if ($group === 'contrato' && preg_match('/^folhas\[([0-9]{1,2})\](?:\[observacao\]|\[produtos\]\[([0-9]{1,3})\]\[(nome|marca|unidade|quantidade|preco)\])$/D', $name, $matches) === 1) {
            return match ($matches[3] ?? 'observacao') { 'nome' => 150, 'marca' => 100, 'observacao' => 2000, default => 30 };
        }
        return null;
    }
}
