<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use src\Core\Database;

final class RequestSecurityTest extends TestCase
{
    private array $envBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['TRUSTED_PROXIES'] = '';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $_SERVER['HTTP_HOST'] = 'localhost:8000';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_FORWARDED']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
        putenv('APP_ENV=testing');
        parent::tearDown();
    }

    public function testUnknownOrMissingEnvironmentFallsBackToProduction(): void
    {
        $_ENV['APP_ENV'] = 'unexpected';
        $this->assertSame('production', \Config::environment());
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');
        $this->assertSame('production', \Config::environment());
    }

    public function testUntrustedProxyHeadersAreIgnored(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame('198.51.100.10', \RequestContext::clientIp());
        $this->assertFalse(\RequestContext::isHttps());
    }

    public function testTrustedProxyUsesNearestSafeChainAndForwardedProtocol(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.10,10.0.0.0/8';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 10.1.2.3';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';

        $this->assertSame('203.0.113.77', \RequestContext::clientIp());
        $this->assertTrue(\RequestContext::isHttps());
    }

    public function testProductionHostIsRestrictedToAppUrlAndAllowlist(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_URL'] = 'https://gse.example.test';
        $_ENV['APP_ALLOWED_HOSTS'] = 'internal.example.test';
        $_ENV['FORCE_HTTPS'] = 'true';
        $_SERVER['HTTP_HOST'] = 'attacker.example.test';
        $this->assertFalse(\RequestContext::isHostAllowed());

        $_SERVER['HTTP_HOST'] = 'gse.example.test';
        $this->assertTrue(\RequestContext::isHostAllowed());
        $this->assertSame([], \Config::productionErrors());
    }

    public function testProductionRejectsDatabaseInsidePublic(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['DB_PATH'] = ROOT_PATH . '/public/private.sqlite';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora do diretório public');
        Database::resolvePath();
    }
}
