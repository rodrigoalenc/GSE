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

        $this->assertCount(84, $routes);
        $stockReview = array_values(array_filter($routes, static fn (array $route): bool => $route['pattern'] === 'contrato/conferir-abertura/{id}'));
        $this->assertTrue($stockReview[0]['admin']);
        $this->assertSame('POST', $stockReview[0]['method']);
        $this->assertSame(['id' => '42'], $match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/42'));
        $this->assertNull($match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/excluirTudo'));
        $this->assertNull($match->invoke($router, 'usuario/editar/{id}', 'usuario/editar/../1'));
        $this->assertContainsOnlyArray($routes);

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
}
