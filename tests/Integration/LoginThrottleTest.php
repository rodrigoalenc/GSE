<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class LoginThrottleTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnvironment('LOGIN_ACCOUNT_MAX_FAILURES', '5');
        $this->setEnvironment('LOGIN_IP_MAX_FAILURES', '40');
        $this->setEnvironment('LOGIN_THROTTLE_WINDOW_SECONDS', '900');
        $this->setEnvironment('LOGIN_DELAY_BASE_MS', '0');
        $this->setEnvironment('LOGIN_DELAY_MAX_MS', '0');
        $this->unsetEnvironment('LOGIN_MAX_FAILURES');
        $this->unsetEnvironment('LOGIN_WINDOW_SECONDS');
    }

    protected function tearDown(): void
    {
        $this->setEnvironment('LOGIN_ACCOUNT_MAX_FAILURES', '5');
        $this->setEnvironment('LOGIN_IP_MAX_FAILURES', '40');
        $this->setEnvironment('LOGIN_THROTTLE_WINDOW_SECONDS', '900');
        $this->setEnvironment('LOGIN_DELAY_BASE_MS', '0');
        $this->setEnvironment('LOGIN_DELAY_MAX_MS', '0');
        $this->unsetEnvironment('LOGIN_MAX_FAILURES');
        $this->unsetEnvironment('LOGIN_WINDOW_SECONDS');
        parent::tearDown();
    }

    public function testAccountBlocksAfterFiveFailuresAcrossDifferentIpsAndNormalizesCase(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $throttle->recordFailure(
                $attempt % 2 === 0 ? 'CONTA@example.test' : 'conta@EXAMPLE.test',
                '192.0.2.' . $attempt,
                $now + $attempt
            );
        }

        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['account_blocked']);
        $this->assertFalse($result['ip_blocked']);
        $this->assertTrue($throttle->status('Conta@example.test', '198.51.100.1', $now + 6)['blocked']);
    }

    public function testFiveFailuresFromSharedIpDoNotBlockAnotherAccount(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        $sharedIp = '192.0.2.20';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $throttle->recordFailure("conta{$attempt}@example.test", $sharedIp, $now + $attempt);
        }

        $status = $throttle->status('legitima@example.test', $sharedIp, $now + 6);
        $this->assertFalse($status['blocked']);
        $this->assertSame(5, $status['ip_failures']);
        $this->assertSame(0, $status['account_failures']);
    }

    public function testIpBlocksAfterItsIndependentHigherThreshold(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        $sharedIp = '192.0.2.30';

        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $result = $throttle->recordFailure("conta{$attempt}@example.test", $sharedIp, $now + $attempt);
        }

        $this->assertTrue($result['blocked']);
        $this->assertFalse($result['account_blocked']);
        $this->assertTrue($result['ip_blocked']);
        $this->assertTrue($throttle->status('legitima@example.test', $sharedIp, $now + 41)['blocked']);
    }

    public function testSuccessfulLoginClearsOnlyAccountHistory(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        $throttle->recordFailure('conta@example.test', '192.0.2.40', $now);
        $throttle->recordSuccess('CONTA@example.test', '192.0.2.40');

        $status = $throttle->status('conta@example.test', '192.0.2.40', $now + 1);
        $this->assertSame(0, $status['account_failures']);
        $this->assertSame(1, $status['ip_failures']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        $this->assertSame('ip', $this->pdo->query('SELECT key_type FROM login_attempts')->fetchColumn());
    }

    public function testBlockAndCountingWindowExpire(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $throttle->recordFailure('expira@example.test', '192.0.2.50', $now + $attempt);
        }

        $this->assertTrue($throttle->status('expira@example.test', '198.51.100.50', $now + 10)['blocked']);
        $this->assertFalse($throttle->status('expira@example.test', '198.51.100.50', $now + 910)['blocked']);

        $reset = $throttle->recordFailure('expira@example.test', '198.51.100.50', $now + 910);
        $this->assertSame(1, $reset['account_failures']);
        $this->assertFalse($reset['blocked']);
    }

    public function testInvalidConfigurationFallsBackToSafeSeparatedDefaults(): void
    {
        $this->setEnvironment('LOGIN_ACCOUNT_MAX_FAILURES', 'invalido');
        $this->setEnvironment('LOGIN_IP_MAX_FAILURES', 'invalido');
        $this->setEnvironment('LOGIN_THROTTLE_WINDOW_SECONDS', 'invalido');
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $throttle->recordFailure('padrao@example.test', '192.0.2.' . $attempt, $now + $attempt);
        }

        $this->assertTrue($result['account_blocked']);
        $this->assertFalse($result['ip_blocked']);
        $this->assertFalse($throttle->status('outra@example.test', '192.0.2.1', $now + 6)['blocked']);
    }

    public function testLegacyAccountAndWindowSettingsRemainCompatibleWithoutLoweringIpLimit(): void
    {
        $this->unsetEnvironment('LOGIN_ACCOUNT_MAX_FAILURES');
        $this->unsetEnvironment('LOGIN_THROTTLE_WINDOW_SECONDS');
        $this->setEnvironment('LOGIN_MAX_FAILURES', '3');
        $this->setEnvironment('LOGIN_WINDOW_SECONDS', '60');
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $throttle->recordFailure('legada@example.test', '192.0.2.' . $attempt, $now + $attempt);
        }

        $this->assertTrue($result['account_blocked']);
        $this->assertFalse($result['ip_blocked']);
        $this->assertFalse($throttle->status('outra@example.test', '192.0.2.1', $now + 4)['blocked']);
        $this->assertFalse($throttle->status('legada@example.test', '198.51.100.1', $now + 904)['blocked']);
    }

    public function testProgressiveDelayUsesAccountHistoryInsteadOfSharedIpHistory(): void
    {
        $this->setEnvironment('LOGIN_DELAY_BASE_MS', '100');
        $this->setEnvironment('LOGIN_DELAY_MAX_MS', '2000');
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;

        $first = $throttle->recordFailure('primeira@example.test', '192.0.2.60', $now);
        $secondAccount = $throttle->recordFailure('segunda@example.test', '192.0.2.60', $now + 1);
        $secondFailure = $throttle->recordFailure('primeira@example.test', '198.51.100.60', $now + 2);

        $this->assertSame(100, $first['delay_ms']);
        $this->assertSame(100, $secondAccount['delay_ms']);
        $this->assertSame(200, $secondFailure['delay_ms']);

        $this->setEnvironment('LOGIN_DELAY_BASE_MS', '0');
        $this->setEnvironment('LOGIN_DELAY_MAX_MS', '0');
    }

    public function testValidLoginWorksAfterExpiredHistoricalBlock(): void
    {
        $this->insertUsuario('Conta Expirada', 'funcionario', true, 'Frase expirada 2026');
        $throttle = new \LoginThrottle($this->pdo);
        $past = time() - 2000;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $throttle->recordFailure('conta.expirada@teste.local', '127.0.0.1', $past + $attempt);
        }

        $this->assertTrue(\Auth::attempt('CONTA.EXPIRADA@teste.local', 'Frase expirada 2026'));
    }

    public function testAuthenticationUsesGenericOutcomeForExistingAndUnknownAccounts(): void
    {
        $this->insertUsuario('Conta Auditada', 'funcionario', true, 'Frase auditada 2026');

        $this->assertFalse(\Auth::attempt('conta.auditada@teste.local', 'tentativa incorreta'));
        $this->assertSame(\Auth::ATTEMPT_INVALID, \Auth::lastAttemptStatus());
        $this->assertFalse(\Auth::attempt('inexistente@teste.local', 'tentativa incorreta'));
        $this->assertSame(\Auth::ATTEMPT_INVALID, \Auth::lastAttemptStatus());

        $events = $this->pdo->query(
            "SELECT action, result, description FROM security_audit WHERE action = 'login.invalid' ORDER BY id"
        )->fetchAll();
        $this->assertCount(2, $events);
        $this->assertSame($events[0]['action'], $events[1]['action']);
        $this->assertSame($events[0]['result'], $events[1]['result']);
        $this->assertSame($events[0]['description'], $events[1]['description']);
        $this->assertStringNotContainsString('tentativa incorreta', $events[0]['description']);
    }

    public function testAuthenticationAuditsBlockWithoutSensitiveInput(): void
    {
        $this->insertUsuario('Conta Bloqueada', 'funcionario', true, 'Frase bloqueada 2026');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertFalse(\Auth::attempt('conta.bloqueada@teste.local', 'segredo de teste incorreto'));
        }

        $actions = $this->pdo->query('SELECT action FROM security_audit ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('login.invalid', $actions);
        $this->assertContains('login.blocked', $actions);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE description LIKE '%segredo de teste%'")->fetchColumn()
        );
    }

    private function setEnvironment(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    private function unsetEnvironment(string $name): void
    {
        unset($_ENV[$name]);
        putenv($name);
    }
}
