<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class LoginThrottleTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        foreach (['LOGIN_PAIR_MAX_ATTEMPTS','LOGIN_PAIR_WINDOW_SECONDS','LOGIN_ORIGIN_MAX_ATTEMPTS','LOGIN_ORIGIN_WINDOW_SECONDS'] as $name) {
            unset($_ENV[$name]); putenv($name);
        }
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        (new \ReflectionProperty(\PasswordPolicy::class, 'runtime'))->setValue(null, null);
        parent::tearDown();
    }

    public function testAccountBudgetIsScopedToOriginAndNormalizesCase(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertTrue($throttle->reserve('CONTA@example.test', '192.0.2.1', $now)['admitted']);
        }
        $this->assertFalse($throttle->reserve('conta@EXAMPLE.test', '192.0.2.1', $now + 1)['admitted']);
        $this->assertTrue($throttle->reserve('conta@example.test', '198.51.100.1', $now + 1)['admitted']);
        $this->assertTrue($throttle->reserve('outra@example.test', '192.0.2.1', $now + 1)['admitted']);
    }

    public function testOriginBudgetBoundsPasswordSprayingButSharedSchoolRecoversWithinTenSeconds(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->assertTrue($throttle->reserve("conta{$attempt}@example.test", '192.0.2.2', $now)['admitted']);
        }
        $refused = $throttle->reserve('legitima@example.test', '192.0.2.2', $now + 1);
        $this->assertFalse($refused['admitted']);
        $this->assertSame(9, $refused['retry_after']);
        $this->assertTrue($throttle->reserve('legitima@example.test', '192.0.2.2', $now + 10)['admitted']);
    }

    public function testRejectedRequestsAreReadOnlyAndCannotExtendDeadlineOrCounter(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) { $throttle->reserve('conta@example.test', '192.0.2.3', $now); }
        $before = $this->pdo->query('SELECT * FROM login_attempts ORDER BY key_type')->fetchAll();
        $this->pdo->exec('PRAGMA query_only=ON');
        try {
            for ($i = 1; $i < 10; $i++) {
                $result = $throttle->reserve('conta@example.test', '192.0.2.3', $now + $i);
                $this->assertFalse($result['admitted']);
                $this->assertSame(60 - $i, $result['retry_after']);
                $this->assertSame([], $result['reached']);
            }
            $this->assertSame($before, $this->pdo->query('SELECT * FROM login_attempts ORDER BY key_type')->fetchAll());
        } finally { $this->pdo->exec('PRAGMA query_only=OFF'); }
        $this->assertTrue($throttle->reserve('conta@example.test', '192.0.2.3', $now + 60)['admitted']);
    }

    public function testSuccessClearsOnlyItsPairNotOtherOriginsOrSharedOriginBudget(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        $throttle->reserve('conta@example.test', '192.0.2.4', $now);
        $throttle->reserve('conta@example.test', '192.0.2.5', $now);
        $throttle->recordSuccess('CONTA@example.test', '192.0.2.4');
        $this->assertSame(0, $throttle->status('conta@example.test', '192.0.2.4', $now)['account_failures']);
        $this->assertSame(1, $throttle->status('conta@example.test', '192.0.2.4', $now)['ip_failures']);
        $this->assertSame(1, $throttle->status('conta@example.test', '192.0.2.5', $now)['account_failures']);
    }

    public function testLegacyGlobalBlocksCannotLockOutNewScopedBudgets(): void
    {
        $now = time();
        $key = \DataProtection::keyring()['index'];
        $query = $this->pdo->prepare('INSERT INTO login_attempts(key_type,key_hash,failure_count,first_failure_at,last_failure_at,blocked_until) VALUES(?,?,40,?,?,?)');
        foreach ([['account','login-account:' . hash('sha256', 'victim@example.test')], ['ip','login-ip:192.0.2.6']] as [$type,$value]) {
            $query->execute([$type,hash_hmac('sha256',$value,$key),gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+900)]);
        }
        $this->assertTrue((new \LoginThrottle($this->pdo))->reserve('victim@example.test','192.0.2.6',$now)['admitted']);
    }

    public function testBlockedAuthenticationDoesNotChooseHashSleepOrWriteAudit(): void
    {
        $throttle = new \LoginThrottle($this->pdo);
        for ($i=0;$i<5;$i++) { $throttle->reserve('blocked@example.test','127.0.0.1'); }
        $runtime = new class extends \PasswordHashRuntime {
            public function algorithms(): array { throw new \LogicException('Blocked request must never select a password algorithm.'); }
        };
        (new \ReflectionProperty(\PasswordPolicy::class,'runtime'))->setValue(null,$runtime);
        $before = (int) $this->pdo->query('SELECT total_changes()')->fetchColumn();
        $this->pdo->exec('PRAGMA query_only=ON');
        try {
            for ($i=0;$i<20;$i++) {
                $this->assertFalse(\Auth::attempt('blocked@example.test','private-input'));
                $this->assertSame(\Auth::ATTEMPT_BLOCKED,\Auth::lastAttemptStatus());
                $this->assertGreaterThan(0,\Auth::retryAfter());
            }
            $this->assertSame($before,(int)$this->pdo->query('SELECT total_changes()')->fetchColumn());
            $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        } finally { $this->pdo->exec('PRAGMA query_only=OFF'); }
    }

    public function testThresholdAuditIsAggregatedAndDoesNotRevealWhetherAccountExists(): void
    {
        $this->insertUsuario('Conta Auditada','funcionario',true,'Frase auditada 2026');
        foreach (['conta.auditada@teste.local','inexistente@example.test'] as $email) {
            for ($i=0;$i<6;$i++) { $this->assertFalse(\Auth::attempt($email,'private-wrong-password')); }
        }
        $events = $this->pdo->query("SELECT description,target_user_id FROM security_audit WHERE action='login.blocked'")->fetchAll();
        $this->assertCount(2,$events);
        $this->assertSame($events[0],$events[1]);
        $this->assertNull($events[0]['target_user_id']);
        $this->assertStringContainsString('5 tentativas em 60 segundos',$events[0]['description']);
        $this->assertStringNotContainsString('private-wrong-password',json_encode($events));
        $this->assertSame(10,(int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='login.invalid'")->fetchColumn());
    }

    public function testConcurrentReservationsCannotExceedPairBudget(): void
    {
        $processes=[];
        for ($i=0;$i<8;$i++) {
            $process=proc_open([PHP_BINARY,ROOT_PATH.'/tests/fixtures/login-reservation.php',(string)getenv('DB_PATH')],
                [1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,getenv(),['bypass_shell'=>true,'create_no_window'=>true]);
            $this->assertIsResource($process);
            $processes[]=[$process,$pipes];
        }
        $admitted=0;
        foreach ($processes as [$process,$pipes]) {
            $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0,proc_close($process),$error);
            $admitted+=(int)$output;
        }
        $this->assertSame(5,$admitted);
        $this->assertSame(5,(new \LoginThrottle($this->pdo))->status('parallel@example.test','192.0.2.9')['account_failures']);
    }
}
