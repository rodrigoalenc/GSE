<?php

declare(strict_types=1);

final class Router
{
    /** @var list<array{method:string,pattern:string,controller:string,action:string,auth:bool,admin:bool,password_change:bool}> */
    private array $routes;

    public function __construct()
    {
        $this->routes = [
            $this->route('GET', '', 'LoginController', 'index', false, false, true),
            $this->route('GET', 'login', 'LoginController', 'index', false, false, true),
            $this->route('POST', 'login/entrar', 'LoginController', 'entrar', false, false, true),
            $this->route('POST', 'login/sair', 'LoginController', 'sair', true, false, true),
            $this->route('GET', 'dashboard', 'DashboardController', 'index', true),
            $this->route('GET', 'usuario', 'UsuarioController', 'index', true, true),
            $this->route('GET', 'usuario/criar', 'UsuarioController', 'criar', true, true),
            $this->route('POST', 'usuario/criar', 'UsuarioController', 'criar', true, true),
            $this->route('GET', 'usuario/editar/{id}', 'UsuarioController', 'editar', true, true),
            $this->route('POST', 'usuario/editar/{id}', 'UsuarioController', 'editar', true, true),
            $this->route('POST', 'usuario/status/{id}', 'UsuarioController', 'status', true, true),
            $this->route('GET', 'senha/alterar', 'SenhaController', 'alterar', true, false, true),
            $this->route('POST', 'senha/alterar', 'SenhaController', 'alterar', true, false, true),
            $this->route('GET', 'auditoria', 'AuditoriaController', 'index', true, true),
            $this->route('GET', 'aluno', 'AlunoController', 'index', true),
            $this->route('GET', 'aluno/criar', 'AlunoController', 'criar', true),
            $this->route('POST', 'aluno/criar', 'AlunoController', 'criar', true),
            $this->route('GET', 'aluno/perfil/{id}', 'AlunoController', 'perfil', true),
            $this->route('GET', 'aluno/editar/{id}', 'AlunoController', 'editar', true),
            $this->route('POST', 'aluno/editar/{id}', 'AlunoController', 'editar', true),
            $this->route('POST', 'aluno/status/{id}', 'AlunoController', 'status', true, true),
            $this->route('GET', 'aluno/dva/{id}', 'AlunoController', 'dva', true),
            $this->route('POST', 'aluno/dva/{id}', 'AlunoController', 'dva', true),
            $this->route('GET', 'dva', 'AlunoController', 'painelDva', true),
            $this->route('GET', 'turma', 'TurmaController', 'index', true, true),
            $this->route('GET', 'turma/criar', 'TurmaController', 'criar', true, true),
            $this->route('POST', 'turma/criar', 'TurmaController', 'criar', true, true),
            $this->route('GET', 'turma/editar/{id}', 'TurmaController', 'editar', true, true),
            $this->route('POST', 'turma/editar/{id}', 'TurmaController', 'editar', true, true),
            $this->route('POST', 'turma/status/{id}', 'TurmaController', 'status', true, true),
        ];
    }

    public function dispatch(string $url, ?string $requestMethod = null): void
    {
        $path = $this->normalizePath($url);
        $method = strtoupper($requestMethod ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $pathMatches = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);

            if ($params === null) {
                continue;
            }

            $pathMatches[] = $route;

            if ($route['method'] !== $method) {
                continue;
            }

            $this->authorize($route);

            if ($method === 'POST' && !csrf_valido($_POST['_csrf_token'] ?? null)) {
                render_http_error(419, 'Sessão expirada', 'Atualize a página e tente enviar o formulário novamente.');
            }

            $this->invoke($route, $params);

            return;
        }

        if ($pathMatches !== []) {
            $allowed = array_values(array_unique(array_column($pathMatches, 'method')));
            header('Allow: ' . implode(', ', $allowed));
            render_http_error(405, 'Método não permitido', 'O método HTTP usado não é aceito nesta rota.');
        }

        render_http_error(404, 'Página não encontrada', 'A página solicitada não existe.');
    }

    /** @return list<array<string, bool|string>> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @param array{auth:bool,admin:bool,password_change:bool} $route */
    private function authorize(array $route): void
    {
        if (!$route['auth']) {
            return;
        }

        if (!Auth::check()) {
            SessionManager::startFreshForFlash();
            definir_flash('warning', 'Faça login para acessar esta página.');
            redirect('login');
        }

        if (Auth::mustChangePassword() && !$route['password_change']) {
            definir_flash('warning', 'Altere a senha temporária antes de continuar.');
            redirect('senha/alterar');
        }

        if ($route['admin'] && !Auth::isAdmin()) {
            $userId = (int) ($_SESSION['usuario_id'] ?? 0);
            AuditLogger::record(
                'authorization.admin_denied',
                AuditLogger::BLOCKED,
                $userId ?: null,
                $userId ?: null,
                'Acesso a recurso administrativo negado.'
            );
            render_http_error(403, 'Acesso não autorizado', 'Somente administradores podem acessar este recurso.', 'dashboard');
        }
    }

    /** @param array{controller:string,action:string} $route */
    private function invoke(array $route, array $params): void
    {
        $controllerName = $route['controller'];
        $controllerFile = ROOT_PATH . '/src/Controllers/' . $controllerName . '.php';

        if (!is_file($controllerFile)) {
            throw new RuntimeException('Controller de rota explícita não encontrado.');
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            throw new RuntimeException('Classe de controller inválida na tabela de rotas.');
        }

        $controller = new $controllerName();
        $action = $route['action'];
        $controller->{$action}(...array_values($params));
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $patternSegments = $pattern === '' ? [] : explode('/', $pattern);
        $pathSegments = $path === '' ? [] : explode('/', $path);

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];

        foreach ($patternSegments as $index => $segment) {
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/i', $segment, $match) === 1) {
                $value = $pathSegments[$index];

                if (($match[1] === 'id' && preg_match('/^[1-9][0-9]*$/', $value) !== 1)
                    || preg_match('/^[a-z0-9_-]+$/i', $value) !== 1) {
                    return null;
                }

                $params[$match[1]] = $value;
                continue;
            }

            if (!hash_equals($segment, $pathSegments[$index])) {
                return null;
            }
        }

        return $params;
    }

    private function normalizePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return '__invalid__';
        }

        $decoded = rawurldecode($path);

        if (str_contains($decoded, "\0") || str_contains($decoded, '..')) {
            return '__invalid__';
        }

        return trim(str_replace('/index.php', '', $decoded), '/');
    }

    /** @return array{method:string,pattern:string,controller:string,action:string,auth:bool,admin:bool,password_change:bool} */
    private function route(
        string $method,
        string $pattern,
        string $controller,
        string $action,
        bool $auth,
        bool $admin = false,
        bool $passwordChange = false
    ): array {
        return [
            'method' => $method,
            'pattern' => $pattern,
            'controller' => $controller,
            'action' => $action,
            'auth' => $auth,
            'admin' => $admin,
            'password_change' => $passwordChange,
        ];
    }
}
