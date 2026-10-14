<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class ActionBudgetReceiptTest extends DatabaseTestCase
{
    public function testConcurrentWorkersShareBudgetAndContractReceipt(): void
    {
        $user=$this->insertUsuario();
        $token=bin2hex(random_bytes(16));
        putenv('CONFIRM_MAX_ATTEMPTS=2');
        try {
            foreach (['budget','contract'] as $operation) {
                $workers=[];
                for ($i=0;$i<4;$i++) {
                    $pipes=[];
                    $process=proc_open([PHP_BINARY,ROOT_PATH.'/tests/fixtures/security-worker.php',$operation,(string)$user,$token],
                        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                    $this->assertIsResource($process);
                    fclose($pipes[0]);
                    $workers[]=[$process,$pipes];
                }
                $outputs=[];
                foreach ($workers as [$process,$pipes]) {
                    $outputs[]=stream_get_contents($pipes[1]);
                    $errors=stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    $this->assertSame(0,proc_close($process),'Synthetic worker must exit successfully.');
                    $this->assertSame('',$errors);
                }
                if ($operation==='budget') {
                    $this->assertSame(2,count(array_filter($outputs,static fn($value)=>$value==='accepted')));
                    $this->assertSame(2,count(array_filter($outputs,static fn($value)=>$value==='limited')));
                } else {
                    $this->assertCount(1,array_unique($outputs));
                    $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn());
                    $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn());
                }
            }
        } finally { putenv('CONFIRM_MAX_ATTEMPTS'); }
    }

    public function testConfirmationBudgetPersistsAcrossConnectionsAndHasIndependentPurposes(): void
    {
        $id=$this->insertUsuario();
        $_ENV['CONFIRM_MAX_ATTEMPTS']='2'; $_ENV['CONFIRM_WINDOW_SECONDS']='300';
        try {
            \ActionBudget::consume($this->pdo,$id,'profile');
            $other=new \PDO('sqlite:'.\src\Core\Database::resolvePath(),null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            \ActionBudget::consume($other,$id,'profile');
            \ActionBudget::consume($other,$id,'password');
            try { \ActionBudget::consume($other,$id,'profile'); $this->fail('Budget exceeded.'); }
            catch (\ActionLimitExceeded $exception) { $this->assertGreaterThan(0,$exception->retryAfter); }
            $this->pdo->exec('UPDATE action_budgets SET expires=0');
            \ActionBudget::consume($other,$id,'profile');
            $this->assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM action_budgets')->fetchColumn());
        } finally { unset($_ENV['CONFIRM_MAX_ATTEMPTS'],$_ENV['CONFIRM_WINDOW_SECONDS']); }
    }

    public function testContractReplayDoesNotDuplicateAuditAndMismatchedContentIsRejected(): void
    {
        $id=$this->insertUsuario(); $model=new \Contrato(); $token=bin2hex(random_bytes(16));
        $first=$model->create('Contrato ficticio','100,00','',$id,$token);
        $audits=(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        $this->assertSame($first,$model->create('Contrato ficticio','100,00','',$id,$token));
        $this->assertSame($audits,(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn());
        try { $model->create('Outro contrato','100,00','',$id,$token); $this->fail('Mismatched replay accepted.'); }
        catch (\DomainException) { $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()); }
        $failed=bin2hex(random_bytes(16));
        $this->pdo->exec("CREATE TRIGGER reject_contract_audit BEFORE INSERT ON security_audit WHEN NEW.resource_type='contrato' BEGIN SELECT RAISE(ABORT,'synthetic'); END");
        try { $model->create('Falha controlada','100,00','',$id,$failed); $this->fail('Audit failure accepted.'); }
        catch (\PDOException) { $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn()); }
    }

    public function testConfirmedStudentAndPassiveCreationReplayKeepsOneRecordAndAudit(): void
    {
        $actor=$this->insertUsuario(); $class=$this->insertTurma();
        $student=new \Aluno(); $token=bin2hex(random_bytes(16));
        $data=['nome_completo'=>'Aluno ficticio de replay','data_nascimento'=>'2010-05-10','id_turma'=>(string)$class,
            'telefone_aluno'=>'','telefone_responsavel'=>''];
        $first=$student->cadastrar($data,$actor,null,true,$token); $this->assertIsInt($first);
        $audits=(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        $this->assertSame($first,$student->cadastrar($data,$actor,null,true,$token));
        $this->assertSame($audits,(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertFalse($student->cadastrar([...$data,'nome_completo'=>'Outro aluno ficticio'],$actor,null,true,$token));
        $passive=new \Passivo(); $token=bin2hex(random_bytes(16));
        $data=['nome_completo'=>'Pasta ficticia de replay','caixa'=>'R','numero'=>'1'];
        $first=$passive->cadastrar($data,$actor,$token); $this->assertIsInt($first);
        $audits=(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        $this->assertSame($first,$passive->cadastrar($data,$actor,$token));
        $this->assertSame($audits,(int)$this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        $this->assertFalse($passive->cadastrar([...$data,'numero'=>'2'],$actor,$token));
        $this->assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }

    public function testCsrfUnexpectedTypesAndLegacyTwelveCharacterPassword(): void
    {
        $_SESSION['_csrf_token']=str_repeat('a',64);
        foreach ([null,[],['token'],42,true,'a',str_repeat('G',64)] as $value) { $this->assertFalse(\csrf_valido($value)); }
        $token=\csrf_token(); $this->assertTrue(\csrf_valido($token));
        $password='Azul céu 123';
        $this->assertSame(12,mb_strlen($password));
        $this->assertNotEmpty(\PasswordPolicy::validate($password));
        $hash=\PasswordPolicy::hash($password);
        $this->assertTrue(\PasswordPolicy::verify($password,$hash));
    }
}
