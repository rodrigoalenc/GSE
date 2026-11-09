<?php
declare(strict_types=1);

final class NavigationContext
{
    private const LISTS = ['aluno', 'dva', 'passivo', 'contrato', 'certidao', 'estoque', 'turma', 'usuario', 'relatorio'];

    public static function route(string $path): ?string
    {
        $last = basename(rtrim($path, '/'));
        return in_array($last, self::LISTS, true) ? $last : null;
    }

    /** @param array<mixed> $query */
    public static function remember(string $list, array $query): void
    {
        // The browser restores the opaque ListState token for its own tab.
        // Personal filters are never rebuilt into a return URL.
    }

    public static function back(string $list): string
    {
        if (!in_array($list, self::LISTS, true)) { return 'dashboard'; }
        return $list;
    }

    /** @return array<string,string> */
    public static function links(): array
    {
        $links = [];
        foreach (self::LISTS as $list) { $links[url($list)] = url(self::back($list)); }
        return $links;
    }
}
