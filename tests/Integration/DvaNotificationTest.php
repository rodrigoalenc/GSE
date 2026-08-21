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
