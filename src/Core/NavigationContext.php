<?php
declare(strict_types=1);

final class NavigationContext
{
    private const LISTS = ['aluno', 'dva', 'passivo', 'contrato', 'certidao', 'estoque', 'turma', 'usuario', 'relatorio'];
    private const FILTERS = ['q','busca','page','pagina','turma','ativo','dva','caixa','localizacao','situacao','status','fornecedor','tipo','estado','ano','ordem','limite','mostrar','periodo','validade','pendencias'];

    public static function route(string $path): ?string
    {
        $last = basename(rtrim($path, '/'));
        return in_array($last, self::LISTS, true) ? $last : null;
    }

    /** @param array<mixed> $query */
    public static function remember(string $list, array $query): void
    {
        $user = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($user < 1 || !in_array($list, self::LISTS, true)) { return; }
        $filters = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && in_array($key, self::FILTERS, true) && is_string($value) && mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0")) {
                $filters[$key] = mb_substr($value, 0, in_array($key, ['q', 'busca'], true) ? 100 : 60);
            }
        }
        $_SESSION['list_context'][$list] = ['user' => $user, 'query' => $filters];
    }

    public static function back(string $list): string
    {
        if (!in_array($list, self::LISTS, true)) { return 'dashboard'; }
        $context = $_SESSION['list_context'][$list] ?? null;
        if (!is_array($context) || ($context['user'] ?? null) !== (int) ($_SESSION['usuario_id'] ?? 0) || !is_array($context['query'] ?? null)) { return $list; }
        $query = http_build_query($context['query'], '', '&', PHP_QUERY_RFC3986);
        return $list . ($query === '' ? '' : '?' . $query);
    }

    /** @return array<string,string> */
    public static function links(): array
    {
        $links = [];
        foreach (self::LISTS as $list) { $links[url($list)] = url(self::back($list)); }
        return $links;
    }
}
