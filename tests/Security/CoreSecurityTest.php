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

    public function testCsrfEEscapeHtml(): void
    {
        $token = \csrf_token();

        $this->assertSame(64, strlen($token));
        $this->assertTrue(\csrf_valido($token));
        $this->assertFalse(\csrf_valido('token-invalido'));
        $this->assertSame('&lt;script&gt;', \e('<script>'));
    }

    public function testRouterValidaSegmentosESingularizaRotas(): void
    {
        $router = new \Router();
        $reflection = new ReflectionClass($router);

        $segmentoValido = $reflection->getMethod('segmentoValido');
        $segmentoValido->setAccessible(true);
        $singularizar = $reflection->getMethod('singularizar');
        $singularizar->setAccessible(true);

        $this->assertTrue($segmentoValido->invoke($router, 'alunos'));
        $this->assertFalse($segmentoValido->invoke($router, '../segredo'));
        $this->assertSame('aluno', $singularizar->invoke($router, 'alunos'));
        $this->assertSame('certidao', $singularizar->invoke($router, 'certidoes'));
    }
}
