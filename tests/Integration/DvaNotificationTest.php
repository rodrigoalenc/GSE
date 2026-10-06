<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

final class DvaNotificationTest extends DatabaseTestCase
{
    public function testAlertasSaoOptInEDesabilitadosAoRebaixarOuInativarAdministrador(): void
    {
        $admin = $this->insertUsuario('Admin Opt In');
        $this->insertUsuario('Admin Guardiao');
        $this->assertSame(0, (int) $this->pdo->query("SELECT recebe_alertas_dva FROM usuarios WHERE id = {$admin}")->fetchColumn());
        $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id = ?')->execute([$admin]);

        $users = new \Usuario();
        $row = $users->buscarPorId($admin);
        $this->assertTrue($users->atualizar(
            $admin,
            (string) $row['nome'],
            (string) $row['email'],
            \Usuario::PERFIL_FUNCIONARIO,
            null,
            true
        ));
        $this->assertSame(0, (int) $users->buscarPorId($admin)['recebe_alertas_dva']);

        $otherAdmin = $this->insertUsuario('Admin Inativado');
        $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id = ?')->execute([$otherAdmin]);
        $this->assertTrue($users->definirAtivo($otherAdmin, false));
        $this->assertSame(0, (int) $users->buscarPorId($otherAdmin)['recebe_alertas_dva']);
    }

    public function testEnviaSomenteParaAdministradoresAtivosHabilitadosEIdempotente(): void
    {
        $admin = $this->insertUsuario('Admin Alerta');
        $inactive = $this->insertUsuario('Admin Inativo');
        $employee = $this->insertUsuario('Funcionario Alerta', 'funcionario');
        $this->pdo->exec("UPDATE usuarios SET recebe_alertas_dva = 1 WHERE id IN ({$admin}, {$inactive}, {$employee})");
        $this->pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$inactive]);
        $class = $this->insertTurma();
        $student = (new \Aluno())->cadastrar([
            'nome_completo' => '<Aluno & Teste>', 'data_nascimento' => '2010-01-01', 'id_turma' => $class,
            'telefone_aluno' => '', 'telefone_responsavel' => '',
        ], $admin, ['data_vencimento' => '2026-08-25', 'observacao' => null]);
        $this->assertIsInt($student);
        $transport = new RecordingTransport();
        $service = new \DvaNotificationService(
            $this->pdo,
            $transport,
            new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30)
        );

        $first = $service->notify();
        $second = $service->notify();

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(1, $second['skipped']);
        $this->assertCount(1, $transport->messages);
        $this->assertStringContainsString('&lt;Aluno &amp; Teste&gt;', $transport->messages[0]['html']);
        $this->assertStringNotContainsString('<Aluno & Teste>', $transport->messages[0]['html']);
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
        $this->pdo->prepare('UPDATE usuarios SET recebe_alertas_dva = 1, email = ? WHERE id = ?')
            ->execute(['invalid-address', $admin]);
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

    public function testRevalidatesActiveProfilePreferenceAndEmailBeforeEachRecipient(): void
    {
        $first = $this->insertUsuario('Admin Primeiro');
        $inactive = $this->insertUsuario('Admin Inativado Durante Envio');
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
                    $this->pdo->prepare('UPDATE usuarios SET email = ? WHERE id = ?')->execute(['new@teste.local', $this->email]);
                }
            }
        };
        $result = (new \DvaNotificationService($this->pdo, $transport,
            new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30)))->notify();

        $this->assertSame(['warnings'=>1, 'recipients'=>5, 'sent'=>1, 'skipped'=>4, 'failed'=>0], $result);
        $this->assertSame(1, $transport->sent);
        $this->assertSame([$first], $this->pdo->query('SELECT user_id FROM dva_notification_deliveries')->fetchAll(\PDO::FETCH_COLUMN));
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
