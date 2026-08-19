<?php

declare(strict_types=1);

namespace Tests\Integration;

use src\Core\Maintenance;
use Tests\Support\DatabaseTestCase;

final class MaintenanceTest extends DatabaseTestCase
{
    public function testMaintenanceRemovesOnlyExpiredRecordsAndIsIdempotent(): void
    {
        $now = 1_800_000_000;
        $oldLogin = gmdate('Y-m-d H:i:s', $now - (8 * 86400));
        $recentLogin = gmdate('Y-m-d H:i:s', $now - 60);
        $oldAudit = gmdate('Y-m-d H:i:s', $now - (366 * 86400));
        $recentAudit = gmdate('Y-m-d H:i:s', $now - 60);

        $login = $this->pdo->prepare(
            'INSERT INTO login_attempts
                (key_type, key_hash, failure_count, first_failure_at, last_failure_at)
             VALUES (:type, :hash, 1, :first, :last)'
        );
        $login->execute(['type' => 'account', 'hash' => 'old', 'first' => $oldLogin, 'last' => $oldLogin]);
        $login->execute(['type' => 'account', 'hash' => 'recent', 'first' => $recentLogin, 'last' => $recentLogin]);

        $audit = $this->pdo->prepare(
            "INSERT INTO security_audit (occurred_at, action, result, request_id, description)
             VALUES (:occurred, :action, 'success', :request, '')"
        );
        $audit->execute(['occurred' => $oldAudit, 'action' => 'old.event', 'request' => 'old']);
        $audit->execute(['occurred' => $recentAudit, 'action' => 'recent.event', 'request' => 'recent']);

        $maintenance = new Maintenance($this->pdo);

        $this->assertSame(['login_attempts' => 1, 'security_audit' => 1], $maintenance->run($now));
        $this->assertSame(['login_attempts' => 0, 'security_audit' => 0], $maintenance->run($now));
        $this->assertSame('recent', $this->pdo->query('SELECT key_hash FROM login_attempts')->fetchColumn());
        $this->assertSame('recent.event', $this->pdo->query('SELECT action FROM security_audit')->fetchColumn());
    }

    public function testWebAndAuthenticationPathsDoNotRunRetentionDeletes(): void
    {
        $frontController = file_get_contents(ROOT_PATH . '/public/index.php');
        $auth = file_get_contents(ROOT_PATH . '/src/Core/Auth.php');

        $this->assertIsString($frontController);
        $this->assertIsString($auth);
        $this->assertStringNotContainsString('->cleanup(', $frontController);
        $this->assertStringNotContainsString('->cleanup(', $auth);
    }
}
