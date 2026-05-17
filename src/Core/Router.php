<?php

class Router
{
    private const DEFAULT_CONTROLLER = 'login';
    private const DEFAULT_ACTION = 'index';

    public function dispatch(string $url): void
    {
        $url = $this->normalizarUrl($url);
        $urlParts = $url === '' ? [] : explode('/', $url);

        $controller = $urlParts[0] ?? self::DEFAULT_CONTROLLER;
        $actionName = $urlParts[1] ?? self::DEFAULT_ACTION;
        $params = array_slice($urlParts, 2);

        if (!$this->segmentoValido($controller) || !$this->segmentoValido($actionName)) {
            error_log("Roteamento: URL invalida recebida: {$url}");
            $this->mostrarErro404();
        }

        $controller = $this->singularizar($controller);
        $controllerName = ucfirst($controller) . 'Controller';
        $controllerFile = ROOT_PATH . '/src/Controllers/' . $controllerName . '.php';

        if (!is_file($controllerFile)) {
            error_log("Roteamento: controller '{$controllerName}' nao encontrado. URL tentada: {$url}");
            $this->mostrarErro404();
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            error_log("Roteamento: classe '{$controllerName}' nao encontrada em '{$controllerFile}'.");
            $this->mostrarErro404();
        }

        $controllerInstance = new $controllerName();

        if (!$this->acaoPublicaValida($controllerInstance, $actionName)) {
            error_log("Roteamento: acao '{$actionName}' nao encontrada em '{$controllerName}'. URL tentada: {$url}");
            $this->mostrarErro404();
        }

        call_user_func_array([$controllerInstance, $actionName], $params);
    }

    private function normalizarUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = str_replace('/index.php', '', $path);
        $path = trim(rawurldecode($path), '/');

        return filter_var($path, FILTER_SANITIZE_URL) ?: '';
    }

    private function segmentoValido(string $segmento): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $segmento);
    }

    private function singularizar(string $controller): string
    {
        $controller = preg_replace('/oes$/', 'ao', $controller);
        $controller = preg_replace('/ais$/', 'al', $controller);

        return rtrim($controller, 's');
    }

    private function acaoPublicaValida(object $controllerInstance, string $actionName): bool
    {
        if (!method_exists($controllerInstance, $actionName)) {
            return false;
        }

        $method = new ReflectionMethod($controllerInstance, $actionName);

        return $method->isPublic()
            && !$method->isConstructor()
            && $method->getDeclaringClass()->getName() === get_class($controllerInstance);
    }

    private function mostrarErro404(): void
    {
        http_response_code(404);

        echo "<div style='font-family: sans-serif; text-align: center; margin-top: 10%; color: #334155;'>";
        echo '<h1>Pagina nao encontrada (404)</h1>';
        echo '<p>Desculpe, a pagina que voce esta procurando nao existe ou foi removida.</p>';
        echo "<a href='/' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #0f172a; color: white; text-decoration: none; border-radius: 6px;'>Voltar para a pagina inicial</a>";
        echo '</div>';
        exit;
    }
}
