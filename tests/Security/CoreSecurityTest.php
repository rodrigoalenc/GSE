<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CoreSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testCsrfEscapeHtmlAndSafeRedirectHelpers(): void
    {
        $token = \csrf_token();

        $this->assertSame(64, strlen($token));
        $this->assertTrue(\csrf_valido($token));
        $this->assertFalse(\csrf_valido('token-invalido'));
        $this->assertNotSame($token, \csrf_renovar());
        $this->assertSame('&lt;script&gt;', \e('<script>'));
    }

    public function testRouterUsesExplicitRoutesAndMatchesOnlyNumericIdentifiers(): void
    {
        $router = new \Router();
        $routes = $router->routes();
        $reflection = new ReflectionClass($router);
        $match = $reflection->getMethod('match');
        $match->setAccessible(true);

        $this->assertCount(94, $routes);
        $routeKeys = array_map(static fn (array $route): string => $route['method'] . ' ' . $route['pattern'], $routes);
        $this->assertCount(count($routes), array_unique($routeKeys), 'Cada método/caminho deve ter uma única autorização.');
        $profileRoutes = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === 'usuario/perfil'));
        $this->assertSame(['GET', 'POST'], array_column($profileRoutes, 'method'));
        foreach ($profileRoutes as $profileRoute) {
            $this->assertTrue($profileRoute['auth']);
            $this->assertFalse($profileRoute['admin']);
            $this->assertFalse($profileRoute['password_change']);
        }
        foreach ([
            'aluno/arquivar-lote' => 'GET',
            'aluno/arquivar-lote/selecionar' => 'POST',
            'aluno/arquivar-lote/preview' => 'POST',
            'aluno/arquivar-lote/confirmar' => 'POST',
        ] as $path => $method) {
            $batchRoute = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === $path));
            $this->assertCount(1, $batchRoute);
            $this->assertSame($method, $batchRoute[0]['method']);
            $this->assertTrue($batchRoute[0]['auth']);
            $this->assertTrue($batchRoute[0]['admin']);
            $this->assertSame('PassivoController', $batchRoute[0]['controller']);
        }
        $stockReview = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === 'contrato/conferir-abertura/{id}'));
        $this->assertTrue($stockReview[0]['admin']);
        $this->assertSame('POST', $stockReview[0]['method']);
        $this->assertTrue($stockReview[0]['auth']);
        $unarchive = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === 'certidao/desarquivar/{id}'));
        $this->assertCount(1, $unarchive);
        $this->assertSame('POST', $unarchive[0]['method']);
        $this->assertTrue($unarchive[0]['auth']);
        $this->assertFalse($unarchive[0]['admin']);
        $this->assertSame('CertidaoController', $unarchive[0]['controller']);
        $this->assertSame('desarquivar', $unarchive[0]['action']);
        $this->assertSame(['id' => '42'], $match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/42'));
        $this->assertNull($match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/excluirTudo'));
        $this->assertNull($match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/../1'));
        $this->assertContainsOnlyArray($routes);
        foreach (['backup' => 'GET', 'backup/criar' => 'POST', 'backup/baixar/{nome}' => 'GET'] as $path => $method) {
            $found = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === $path));
            $this->assertCount(1, $found);
            $this->assertSame($method, $found[0]['method']);
            $this->assertTrue($found[0]['auth']);
            $this->assertTrue($found[0]['admin']);
            $this->assertFalse($found[0]['password_change']);
        }
        $backupName = 'escola_backup_MANUAL_2026-10-09_10-20-30_' . str_repeat('a', 32) . '.db';
        $this->assertSame(['nome' => $backupName], $match->invoke($router, 'backup/baixar/{nome}', 'backup/baixar/' . $backupName));
        foreach (['../' . $backupName, 'other.db', $backupName . '.pdf', 'a%2f' . $backupName, "evil\r\n.db"] as $invalid) {
            $this->assertNull($match->invoke($router, 'backup/baixar/{nome}', 'backup/baixar/' . $invalid));
        }

        $studentStatus = array_values(array_filter(
            $routes,
            static fn (array $route): bool => $route['pattern'] === 'aluno/status/{id}'
        ));
        $this->assertTrue($studentStatus[0]['admin']);
        $this->assertSame('POST', $studentStatus[0]['method']);

        $passiveStatus = array_values(array_filter(
            $routes,
            static fn (array $route): bool => $route['pattern'] === 'passivo/status/{id}'
        ));
        $this->assertTrue($passiveStatus[0]['admin']);
        $this->assertSame('POST', $passiveStatus[0]['method']);

        foreach (['contrato','estoque','relatorio','relatorio/csv','relatorio/pdf','contrato/imprimir/{id}'] as $path) {
            $found = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === $path && $route['method'] === 'GET'));
            $this->assertCount(1, $found);
            $this->assertTrue($found[0]['auth']);
        }
        foreach (['contrato/conciliar/{id}' => true, 'contrato/movimentar/{id}' => false, 'contrato/excluir/{id}' => false] as $path => $adminOnly) {
            $found = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === $path && $route['method'] === 'POST'));
            $this->assertCount(1, $found);
            $this->assertTrue($found[0]['auth']);
            $this->assertSame($adminOnly, $found[0]['admin']);
        }

        foreach ($routes as $route) {
            $this->assertContains($route['method'], ['GET', 'POST']);
            $this->assertNotSame('', $route['controller']);
            $this->assertNotSame('', $route['action']);
            $this->assertSame(!in_array($route['pattern'], ['', 'login', 'login/entrar'], true), $route['auth']);
            if ($route['admin']) {
                $this->assertTrue($route['auth']);
            }
            if (str_contains($route['pattern'], '{id}')) {
                $path = str_replace('{id}', '42', $route['pattern']);
                $this->assertSame(['id' => '42'], $match->invoke($router, $route['pattern'], $path));
                foreach (['0', '-1', '1.2', '01', 'abc', '%31', '1/2'] as $invalid) {
                    $this->assertNull($match->invoke($router, $route['pattern'], str_replace('{id}', $invalid, $route['pattern'])));
                }
            }
        }
    }

    public function testSecurityHeadersDoNotPermitInlineStylesAndHstsRequiresHttps(): void
    {
        $http = \SecurityHeaders::values(false);
        $https = \SecurityHeaders::values(true);

        $this->assertSame('DENY', $http['X-Frame-Options']);
        $this->assertStringContainsString("default-src 'self'", $http['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', $http['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", $http['Content-Security-Policy']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $http);
        $this->assertArrayHasKey('Strict-Transport-Security', $https);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $http['X-Request-ID']);
    }

    public function testPdfPreviewPermitsLocalBlobFramesWithoutAllowingBlobScriptsOrEmbeddingTheApplication(): void
    {
        foreach ([false, true] as $isHttps) {
            $headers = \SecurityHeaders::values($isHttps);
            $directives = [];
            foreach (explode(';', $headers['Content-Security-Policy']) as $directive) {
                $tokens = preg_split('/\s+/', trim($directive));
                $directives[array_shift($tokens)] = $tokens;
            }

            $this->assertSame(["'self'", 'blob:'], $directives['frame-src']);
            $this->assertSame(["'self'"], $directives['default-src']);
            $this->assertSame(["'self'"], $directives['script-src']);
            $this->assertSame(["'self'"], $directives['style-src']);
            $this->assertSame(["'self'"], $directives['base-uri']);
            $this->assertSame(["'self'"], $directives['form-action']);
            $this->assertSame(["'none'"], $directives['object-src']);
            $this->assertSame(["'none'"], $directives['frame-ancestors']);
            $this->assertSame('DENY', $headers['X-Frame-Options']);
        }
    }
}
