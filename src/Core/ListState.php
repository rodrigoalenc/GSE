<?php

declare(strict_types=1);

final class ListState
{
    private const TTL = 1800;
    private const MAX_ROUTE = 20;
    private const MAX_SESSION = 100;

    /** @var array<string,list<string>> */
    private const ROUTES = [
        'dashboard' => ['q', 'ativo', 'turma', 'dva', 'page', 'aniversariantes_pagina'],
        'aluno' => ['q', 'turma', 'ativo', 'dva', 'page'],
        'dva' => ['q', 'turma', 'dva', 'page'],
        'passivo' => ['q', 'caixa', 'localizacao', 'ordem', 'ativo', 'page'],
        'passivo/inativos' => ['q', 'caixa', 'localizacao', 'ordem', 'ativo', 'page'],
        'certidao' => ['fornecedor', 'tipo', 'validade', 'ano', 'busca', 'pendencias', 'page', 'documentos'],
        'certidao/arquivadas' => ['fornecedor', 'tipo', 'validade', 'ano', 'busca', 'pendencias', 'page', 'documentos'],
        'certidao/excluidas' => ['fornecedor', 'tipo', 'validade', 'ano', 'busca', 'pendencias', 'page', 'documentos'],
        'certidao/configurar' => ['busca'],
        'contrato' => ['busca', 'pagina', 'situacao'],
        'estoque' => ['busca', 'pagina'],
        'usuario' => ['q', 'pagina'],
        'turma' => ['q', 'ativo'],
        'relatorio' => ['turma', 'dva', 'ativo', 'pagina'],
        'backup' => ['page'],
    ];

    private const INTEGER_FIELDS = ['page', 'pagina', 'aniversariantes_pagina', 'turma', 'fornecedor', 'tipo', 'ano', 'passivo_turma'];
    private const LONG_FIELDS = ['q', 'busca'];

    private static ?string $currentToken = null;
    private static ?string $currentRoute = null;
    private static bool $expired = false;

    public static function configured(string $route): bool
    {
        return isset(self::ROUTES[$route]);
    }

    /** @return list<string> */
    public static function filterKeys(string $route): array
    {
        return self::ROUTES[$route] ?? [];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{token: string, filters: array<string, mixed>}
     */
    public static function create(string $route, array $input): array
    {
        $user = self::user();
        if ($user < 1 || !self::configured($route)) {
            throw new DomainException('Estado de lista indisponível.');
        }

        self::prune();
        $filters = self::normalize($route, $input);
        $token = bin2hex(random_bytes(16));
        $now = time();
        $_SESSION['list_states'][$token] = [
            'user' => $user,
            'route' => $route,
            'filters' => $filters,
            'created' => $now,
            'accessed' => $now,
            'digest' => self::digest($user, $route, $filters),
        ];
        self::$currentToken = $token;
        self::$currentRoute = $route;
        self::$expired = false;
        self::enforceLimits($route);

        return ['token' => $token, 'filters' => $filters];
    }

    /** @return array<string,mixed>|null */
    public static function restore(string $route, string $token): ?array
    {
        self::$currentToken = null;
        self::$currentRoute = $route;
        self::$expired = false;
        if (!self::configured($route) || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            return null;
        }

        $state = $_SESSION['list_states'][$token] ?? null;
        if (!is_array($state) || (int) ($state['user'] ?? 0) !== self::user() || ($state['route'] ?? null) !== $route
            || !is_array($state['filters'] ?? null)
            || !is_string($state['digest'] ?? null)
            || !hash_equals($state['digest'], self::digest(self::user(), $route, $state['filters']))) {
            return null;
        }
        if ((int) ($state['accessed'] ?? 0) < time() - self::TTL) {
            unset($_SESSION['list_states'][$token]);
            self::$expired = true;

            return null;
        }

        $_SESSION['list_states'][$token]['accessed'] = time();
        self::$currentToken = $token;

        return $state['filters'];
    }

    public static function currentToken(): ?string
    {
        return self::$currentToken;
    }

    public static function currentRoute(): ?string
    {
        return self::$currentRoute;
    }

    public static function expired(): bool
    {
        return self::$expired;
    }

    public static function queueRedirect(string $route, string $token): void
    {
        if (!self::configured($route) || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            throw new DomainException('Estado de redirecionamento inválido.');
        }
        $_SESSION['list_state_redirects'][$route] = $token;
    }

    /** @return array<string,mixed>|null */
    public static function consumeRedirect(string $route): ?array
    {
        $token = $_SESSION['list_state_redirects'][$route] ?? null;
        unset($_SESSION['list_state_redirects'][$route]);
        if (!is_string($token)) { return null; }

        return self::restore($route, $token);
    }

    public static function resetRequest(): void
    {
        self::$currentToken = null;
        self::$currentRoute = null;
        self::$expired = false;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalize(string $route, array $input): array
    {
        $filters = [];
        foreach (self::filterKeys($route) as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            $value = $input[$key];
            if ($key === 'documentos') {
                $pages = self::documentPages($value);
                if ($pages !== []) { $filters[$key] = $pages; }
                continue;
            }
            if (!is_string($value) && !is_int($value)) { continue; }
            $value = trim((string) $value);
            if ($value === '' || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { continue; }
            if ($key === 'ano' && $value === 'todos') {
                $filters[$key] = $value;
                continue;
            }
            if (in_array($key, self::INTEGER_FIELDS, true)) {
                if (preg_match('/\A[0-9]{1,9}\z/D', $value) !== 1) { continue; }
                $number = (int) $value;
                if ($number < 1) { continue; }
                $filters[$key] = (string) $number;
                continue;
            }
            $filters[$key] = mb_substr($value, 0, in_array($key, self::LONG_FIELDS, true) ? 150 : 60, 'UTF-8');
        }

        return $filters;
    }

    /** @return array<int,int> */
    private static function documentPages(mixed $value): array
    {
        if (!is_array($value) || count($value) > 50) { return []; }
        $pages = [];
        foreach ($value as $id => $page) {
            if (!is_int($id) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $id) !== 1) { return []; }
            if (!is_int($page) && (!is_string($page) || preg_match('/\A[1-9][0-9]{0,5}\z/D', $page) !== 1)) { return []; }
            $numericId = (int) $id;
            $numericPage = (int) $page;
            if ($numericId < 1 || $numericPage < 1 || $numericPage > 100000) { return []; }
            $pages[$numericId] = $numericPage;
        }

        return $pages;
    }

    /** @param array<string,mixed> $filters */
    private static function digest(int $user, string $route, array $filters): string
    {
        return hash('sha256', json_encode([$user, $route, $filters], JSON_THROW_ON_ERROR));
    }

    private static function user(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    private static function prune(): void
    {
        $cutoff = time() - self::TTL;
        foreach ($_SESSION['list_states'] ?? [] as $token => $state) {
            if (!is_array($state) || (int) ($state['accessed'] ?? 0) < $cutoff || (int) ($state['user'] ?? 0) !== self::user()) {
                unset($_SESSION['list_states'][$token]);
            }
        }
    }

    private static function enforceLimits(string $route): void
    {
        $states = is_array($_SESSION['list_states'] ?? null) ? $_SESSION['list_states'] : [];
        uasort($states, static fn (array $left, array $right): int => (int) ($left['accessed'] ?? 0) <=> (int) ($right['accessed'] ?? 0));
        $routeTokens = array_keys(array_filter($states, static fn (array $state): bool => ($state['route'] ?? null) === $route));
        while (count($routeTokens) > self::MAX_ROUTE) {
            unset($_SESSION['list_states'][array_shift($routeTokens)]);
        }
        while (count($_SESSION['list_states'] ?? []) > self::MAX_SESSION) {
            foreach (array_keys($states) as $token) {
                if (isset($_SESSION['list_states'][$token])) { unset($_SESSION['list_states'][$token]); break; }
            }
        }
    }
}
