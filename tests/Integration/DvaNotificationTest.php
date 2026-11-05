<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

final class DvaNotificationTest extends DatabaseTestCase
{
    public function testAlertasSaoOptInEPreservadosEntrePerfisAtivos(): void
    {
        $admin = $this->insertUsuario('Admin Guardiao');
        $employee = $this->insertUsuario('Funcionario Opt In', 'funcionario');
        $users = new \Usuario();
        $row = $users->buscarPorId($employee);
        $this->assertSame(0, (int) $users->buscarPorId($admin)['recebe_alertas_dva']);
        $this->assertSame(0, (int) $row['recebe_alertas_dva']);
        $name = (string) $row['nome'];
        $email = (string) $row['email'];

        $this->assertTrue($users->atualizar($employee, $name, $email, \Usuario::PERFIL_FUNCIONARIO, null, true, $admin));
        $this->assertSame(1, (int) $users->buscarPorId($employee)['recebe_alertas_dva']);
        foreach ([\Usuario::PERFIL_ADMINISTRADOR, \Usuario::PERFIL_FUNCIONARIO] as $role) {
            $this->assertTrue($users->atualizar($employee, $name, $email, $role, actorId: $admin));
            $this->assertSame(1, (int) $users->buscarPorId($employee)['recebe_alertas_dva']);
        }

        $this->assertTrue($users->definirAtivo($employee, false, $admin));
        $this->assertSame(0, (int) $users->buscarPorId($employee)['recebe_alertas_dva']);
        $this->assertTrue($users->definirAtivo($employee, true, $admin));
        $this->assertSame(0, (int) $users->buscarPorId($employee)['recebe_alertas_dva']);
    }

    public function testEnviaSomenteParaUsuariosAtivosHabilitadosEIdempotente(): void
    {
        $admin = $this->insertUsuario('Admin Alerta');
        $employee = $this->insertUsuario('Funcionario Alerta', 'funcionario');
        $inactive = $this->insertUsuario('Funcionario Inativo', 'funcionario', false);
        $this->insertUsuario('Admin Sem Alerta');
        $this->insertUsuario('Funcionario Sem Alerta', 'funcionario');
        $this->pdo->exec("UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id IN ({$admin}, {$inactive}, {$employee})");
        $class = $this->insertTurma();
        $student = (new \Aluno())->cadastrar([
            'nome_completo' => '<Aluno & Teste>', 'data_nascimento' => '2010-01-01', 'id_turma' => $class,
            'telefone_aluno' => '', 'telefone_responsavel' => '',
        ], $admin, ['data_vencimento' => '2026-08-25', 'observacao' => null]);
        $this->assertIsInt($student);
        $transport = new RecordingTransport();
        $previousUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://school.example.test';
        $service = new \DvaNotificationService(
            $this->pdo,
            $transport,
            new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30)
        );

        try { $first = $service->notify(); $second = $service->notify(); }
        finally { if ($previousUrl === null) { unset($_ENV['APP_URL']); } else { $_ENV['APP_URL'] = $previousUrl; } }

        $this->assertSame(['warnings'=>1, 'recipients'=>2, 'sent'=>2, 'skipped'=>0, 'failed'=>0], $first);
        $this->assertSame(['warnings'=>1, 'recipients'=>2, 'sent'=>0, 'skipped'=>2, 'failed'=>0], $second);
        $this->assertSame(['admin.alerta@teste.local', 'funcionario.alerta@teste.local'], array_column($transport->messages, 'recipientAddress'));
        foreach ($transport->messages as $message) {
            $this->assertStringContainsString('https://school.example.test/dva', $message['html']);
            foreach (['html', 'text'] as $format) {
                $this->assertStringContainsString('1 registro(s)', $message[$format]);
                $this->assertStringNotContainsString('Aluno', $message[$format]);
                $this->assertStringNotContainsString('2026-08-25', $message[$format]);
                $this->assertStringNotContainsString('1 Ano A', $message[$format]);
            }
        }
    }

    public function testSemAvisosOuDestinatariosNaoEnviaETransportFailurePodeSerTentadaNovamente(): void
    {
        $transport = new RecordingTransport();
        $status = new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30);
        $service = new \DvaNotificationService($this->pdo, $transport, $status);
        $this->assertSame(0, $service->notify()['sent']);

        $admin = $this->insertUsuario('Admin Falha');
        $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id = ?')->execute([$admin]);
        $class = $this->insertTurma();
        (new \Aluno())->cadastrar([
            'nome_completo' => 'Aluno Aviso', 'data_nascimento' => '2010-01-01', 'id_turma' => $class,
            'telefone_aluno' => '', 'telefone_responsavel' => '',
        ], $admin, ['data_vencimento' => '2026-08-01', 'observacao' => null]);
        $transport->fail = true;
        $this->assertSame(1, $service->notify()['failed']);
        $transport->fail = false;
        $this->assertSame(1, $service->notify()['sent']);
    }

    public function testInvalidLegacyEmailIsNeverPassedToTransport(): void
    {
        $admin = $this->insertUsuario('Admin Email Invalido');
        \test_update_email($this->pdo, $admin, 'invalid-address');
        $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva=1 WHERE id=?')->execute([$admin]);
        $this->insertAlunoComDva('Aluno Aviso', '2026-08-01');
        $this->pdo->exec('UPDATE dvas SET ativo = 1');
        $transport = new RecordingTransport();
        $result = (new \DvaNotificationService($this->pdo, $transport,
            new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30)))->notify();

        $this->assertSame(1, $result['warnings']);
        $this->assertSame(0, $result['recipients']);
        $this->assertCount(0, $transport->messages);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dva_notification_deliveries')->fetchColumn());
    }

    public function testRevalidatesActivityPreferenceAndEmailBeforeEachRecipient(): void
    {
        $first = $this->insertUsuario('Admin Primeiro');
        $inactive = $this->insertUsuario('Funcionario Inativado Durante Envio', 'funcionario');
        $employee = $this->insertUsuario('Admin Rebaixado Durante Envio');
        $optOut = $this->insertUsuario('Admin Preferencia Alterada');
        $email = $this->insertUsuario('Admin Email Alterado');
        $this->pdo->exec('UPDATE usuarios SET recebe_alertas_dva = 1');
        $this->insertAlunoComDva('Aluno Aviso', '2026-08-01');
        $this->pdo->exec('UPDATE dvas SET ativo = 1');
        $transport = new class($this->pdo, $inactive, $employee, $optOut, $email) implements \MailTransport {
            public int $sent = 0;
            public function __construct(private \PDO $pdo, private int $inactive, private int $employee,
                private int $optOut, private int $email) {}
            public function send(string $a, string $n, string $s, string $h, string $t): void
            {
                $this->sent++;
                if ($this->sent === 1) {
                    $this->pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$this->inactive]);
                    $this->pdo->prepare("UPDATE usuarios SET tipo = 'funcionario' WHERE id = ?")->execute([$this->employee]);
                    $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva = 0 WHERE id = ?')->execute([$this->optOut]);
                    \test_update_email($this->pdo, (int) $this->email, 'new@teste.local');
                }
            }
        };
        $result = (new \DvaNotificationService($this->pdo, $transport,
            new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30)))->notify();

        $this->assertSame(['warnings'=>1, 'recipients'=>5, 'sent'=>2, 'skipped'=>3, 'failed'=>0], $result);
        $this->assertSame(2, $transport->sent);
        $this->assertSame([$first, $employee], $this->pdo->query('SELECT user_id FROM dva_notification_deliveries ORDER BY user_id')->fetchAll(\PDO::FETCH_COLUMN));
    }
}

final class RecordingTransport implements \MailTransport
{
    /** @var list<array<string,string>> */
    public array $messages = [];
    public bool $fail = false;

    public function send(string $recipientAddress, string $recipientName, string $subject, string $html, string $text): void
    {
        if ($this->fail) {
            throw new RuntimeException('Falha simulada sem dados pessoais.');
        }

        $this->messages[] = compact('recipientAddress', 'recipientName', 'subject', 'html', 'text');
    }
}
