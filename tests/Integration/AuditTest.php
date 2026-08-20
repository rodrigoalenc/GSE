<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class AuditTest extends DatabaseTestCase
{
    public function testAuditIsPaginatedFilteredAndDoesNotStoreSensitiveFields(): void
    {
        \AuditLogger::record('login.success', \AuditLogger::SUCCESS, null, null, 'Autenticação concluída.');
        \AuditLogger::record('login.invalid', \AuditLogger::FAILURE, null, null, 'Falha de autenticação.');
        $audit = new \Auditoria();
        $result = $audit->paginate(['action' => 'login.invalid', 'result' => 'failure'], 1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame('login.invalid', $result['items'][0]['action']);
        $this->assertArrayNotHasKey('password', $result['items'][0]);
        $this->assertArrayNotHasKey('session_id', $result['items'][0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['items'][0]['request_id']);
    }

    public function testRetentionNeverDeletesRecentRecordsAndEnforcesMinimumNinetyDays(): void
    {
        $this->pdo->exec(
            "INSERT INTO security_audit (occurred_at, action, result, request_id, description)
             VALUES (datetime('now', '-200 days'), 'old.event', 'success', 'old', ''),
                    (datetime('now'), 'recent.event', 'success', 'recent', '')"
        );
        $deleted = (new \Auditoria())->cleanup(1);

        $this->assertSame(1, $deleted);
        $this->assertSame('recent.event', $this->pdo->query('SELECT action FROM security_audit')->fetchColumn());
    }

    public function testAuditCanFilterModuleTwoResourceType(): void
    {
        \AuditLogger::record('student.created', \AuditLogger::SUCCESS, null, null, 'Aluno cadastrado.', 'student', 10);
        \AuditLogger::record('class.created', \AuditLogger::SUCCESS, null, null, 'Turma cadastrada.', 'class', 20);

        $result = (new \Auditoria())->paginate(['resource_type' => 'student'], 1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame('student', $result['items'][0]['resource_type']);
        $this->assertSame(10, (int) $result['items'][0]['resource_id']);
        $this->assertNull($result['items'][0]['target_user_id']);
    }
}
