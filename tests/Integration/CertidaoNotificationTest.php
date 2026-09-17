<?php
declare(strict_types=1);
namespace Tests\Integration;
use Tests\Support\DatabaseTestCase;
require_once ROOT_PATH.'/src/Services/CertidaoNotificationService.php';

final class CertidaoNotificationTest extends DatabaseTestCase
{
    private function seed(): int
    {
        $admin=$this->insertUsuario('Administrador Certidao');
        $this->insertUsuario('Outro Admin');
        $this->insertUsuario('Funcionario','funcionario');
        $this->insertUsuario('Inativo','administrador',false);
        $this->pdo->exec("UPDATE usuarios SET recebe_alertas_dva=0");
        $this->pdo->exec("INSERT INTO lista_fornecedores(nome) VALUES ('<Fornecedor>'); INSERT INTO lista_tipos_certidao(nome) VALUES ('Fiscal')");
        $this->pdo->exec("INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,observacao,arquivado,status) VALUES
            (1,1,'2026-01-01','2026-01-02','NÃO INCLUIR',0,1),
            (1,1,'2026-01-01','2026-09-16','NÃO INCLUIR',0,1),
            (1,1,'2026-01-01','2026-10-01','NÃO INCLUIR',0,1),
            (1,1,'2026-01-01','2026-10-02','NÃO INCLUIR',0,1),
            (1,1,'2026-01-01','2026-01-02','NÃO INCLUIR',1,0)");
        return $admin;
    }
    private function dates(): \CertidaoStatus { return new \CertidaoStatus(new \DateTimeImmutable('2026-09-16T12:00:00Z')); }
    public function testRecipientsContentPartialFailureRetryAndDailyDeduplication(): void
    {
        $this->seed();
        $fake=new class implements \MailTransport {
            public array $messages=[]; public bool $fail=true;
            public function send(string $recipientAddress,string $recipientName,string $subject,string $html,string $text): void {
                if ($this->fail && $recipientName==='Outro Admin') { throw new \RuntimeException('Synthetic failure'); }
                $this->messages[]=[$recipientAddress,$html,$text];
            }
        };
        $service=new \CertidaoNotificationService($this->pdo,$fake,$this->dates());
        $first=$service->notify();
        $this->assertSame(['warnings'=>3,'recipients'=>2,'sent'=>1,'skipped'=>0,'failed'=>1],$first);
        $this->assertStringContainsString('&lt;Fornecedor&gt;',$fake->messages[0][1]);
        $this->assertStringNotContainsString('NÃO INCLUIR',$fake->messages[0][1]);
        $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM certidao_notification_deliveries')->fetchColumn());
        $fake->fail=false; $retry=$service->notify(); $this->assertSame(1,$retry['sent']); $this->assertSame(1,$retry['skipped']);
        $this->assertSame(2,$service->notify()['skipped']); $this->assertCount(2,$fake->messages);
    }
    public function testNoWarningsSendsNothingAndMissingValidAdministratorFails(): void
    {
        $fake=new class implements \MailTransport { public function send(string $a,string $n,string $s,string $h,string $t): void { throw new \LogicException('Unexpected send'); } };
        $service=new \CertidaoNotificationService($this->pdo,$fake,$this->dates());
        $this->assertSame(0,$service->notify()['failed']);
        $this->seed(); $this->pdo->exec("UPDATE usuarios SET email='invalid-'||id WHERE tipo='administrador'");
        $this->assertSame(1,$service->notify()['failed']);
        $this->pdo->exec("UPDATE certidoes SET excluido_em='2026-09-16' WHERE id IN (1,2,3)");
        $this->assertSame(0,$service->notify()['warnings']);
    }
    public function testConcurrentWorkerCannotSendWhileFirstTransportIsInProgress(): void
    {
        $this->seed();
        $db=$this->pdo->query('PRAGMA database_list')->fetchAll()[0]['file'];
        $other=new \PDO('sqlite:'.$db,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $other->exec('PRAGMA busy_timeout=10');
        $secondFake=new class implements \MailTransport { public int $sent=0; public function send(string $a,string $n,string $s,string $h,string $t): void { $this->sent++; } };
        $second=new \CertidaoNotificationService($other,$secondFake,$this->dates());
        $firstFake=new class($second,$other) implements \MailTransport {
            public array $attempts=[];
            public function __construct(private \CertidaoNotificationService $second, private \PDO $other) {}
            public function send(string $a,string $n,string $s,string $h,string $t): void {
                // A different connection must commit a real write during SMTP.
                $this->other->exec("INSERT INTO lista_fornecedores(nome) VALUES ('During SMTP ' || hex(randomblob(8)))");
                $this->attempts[]=$this->second->notify();
            }
        };
        $result=(new \CertidaoNotificationService($this->pdo,$firstFake,$this->dates()))->notify();
        $this->assertSame(2,$result['sent']); $this->assertSame(0,$secondFake->sent);
        $this->assertSame(3,(int)$other->query('SELECT COUNT(*) FROM lista_fornecedores')->fetchColumn());
        $this->assertSame(2,$firstFake->attempts[0]['skipped']);
        $this->assertSame(2,$second->notify()['skipped']);
    }

    public function testSmtpAcceptanceBeforeConfirmationFailureCanDuplicateOnRetry(): void
    {
        $this->seed();
        $fake=new class implements \MailTransport {
            public int $sent=0;
            public function send(string $a,string $n,string $s,string $h,string $t): void { $this->sent++; }
        };
        $this->pdo->exec("CREATE TRIGGER fail_confirmation BEFORE INSERT ON certidao_notification_deliveries BEGIN SELECT RAISE(ABORT,'forced'); END");
        $service=new \CertidaoNotificationService($this->pdo,$fake,$this->dates());
        $this->assertSame(2,$service->notify()['failed']);
        $this->assertSame(2,$fake->sent);
        $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM certidao_notification_deliveries')->fetchColumn());
        $this->pdo->exec('DROP TRIGGER fail_confirmation');
        $this->assertSame(2,$service->notify()['sent']);
        $this->assertSame(4,$fake->sent);
        $this->assertSame([2,2],$this->pdo->query('SELECT attempts FROM certidao_notification_attempts ORDER BY user_id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(2,$service->notify()['skipped']);
    }

    public function testProcessExitReleasesLockAndUnconfirmedAttemptIsRetried(): void
    {
        $this->seed();
        $db=$this->pdo->query('PRAGMA database_list')->fetchAll()[0]['file'];
        $process=proc_open([PHP_BINARY,ROOT_PATH.'/tests/fixtures/certidao-notification-crash.php',$db], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $this->assertIsResource($process);
        $fake=new class implements \MailTransport {
            public int $sent=0;
            public function send(string $a,string $n,string $s,string $h,string $t): void { $this->sent++; }
        };
        try {
            $this->assertSame('SMTP_READY',trim((string)fgets($pipes[1])));
            $this->pdo->exec("INSERT INTO lista_fornecedores(nome) VALUES('During child SMTP')");
            $concurrent=(new \CertidaoNotificationService($this->pdo,$fake,$this->dates()))->notify();
            $this->assertSame(2,$concurrent['skipped']);
            $this->assertSame(0,$fake->sent);
        } finally {
            // EOF lets the child terminate even if an assertion failed.
            fclose($pipes[0]);
        }
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(73,proc_close($process),$output.$error);
        $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM certidao_notification_attempts')->fetchColumn());
        $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM certidao_notification_deliveries')->fetchColumn());
        $result=(new \CertidaoNotificationService($this->pdo,$fake,$this->dates()))->notify();
        $this->assertSame(2,$result['sent']);
        $this->assertSame([2,1],$this->pdo->query('SELECT attempts FROM certidao_notification_attempts ORDER BY user_id')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
