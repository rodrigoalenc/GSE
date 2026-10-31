<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\Support\DatabaseTestCase;

require_once ROOT_PATH . '/src/Core/PrivacyRetention.php';

final class AuditQueryRecorder extends PDO
{
    /** @var list<string> */
    public array $prepared = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return parent::prepare($query, $options);
    }
}

final class AuditRemediationTest extends DatabaseTestCase
{
    public function testBasicProjectionDoesNotFetchHealthOrContactColumns(): void
    {
        $id = $this->insertAlunoComDva('Aluno privacidade sintético', '2027-01-01');
        $this->pdo->exec("UPDATE alunos SET telefone_aluno='11999999999',telefone_responsavel='11888888888'");
        $model = new \Aluno();
        $list = $model->paginate([], 1, 15, null, false, false);
        $profile = $model->perfilBasico($id);
        foreach (['telefone_aluno', 'telefone_responsavel', 'dva_id', 'data_vencimento', 'dva_status', 'dva_observacao'] as $key) {
            self::assertArrayNotHasKey($key, $list['items'][0]);
            self::assertArrayNotHasKey($key, $profile['student']);
        }
        self::assertSame([], $profile['dva_history']);
        self::assertArrayHasKey('dva_status', $model->paginate([], 1, 15, null, true, false)['items'][0]);
        self::assertArrayNotHasKey('telefone_aluno', $model->paginate([], 1, 15, null, true, false)['items'][0]);
        $this->expectException(\DomainException::class);
        $model->paginate(['dva' => 'sem_dva'], 1, 15, null, false, false);
    }

    public function testBirthdayPagesRemainBoundedWithCompleteCountsAndNoDuplicates(): void
    {
        $today = new \DateTimeImmutable('2026-10-30');
        for ($n = 0; $n < 65; $n++) { $this->insertAlunoComDva(sprintf('Aniversariante %03d', $n), '2027-01-01'); }
        $this->pdo->exec("UPDATE alunos SET data_nascimento='2010-10-01'");
        $model = new \Aluno();
        self::assertSame(65, $model->contarAniversariantesDoMes($today));
        $first = $model->aniversariantesDoMesCompleto($today);
        $second = $model->aniversariantesDoMesCompleto($today, 20, 2);
        self::assertCount(20, $first); self::assertCount(20, $second);
        self::assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        self::assertCount(50, $model->aniversariantesDoMesCompleto($today, PHP_INT_MAX));
        self::assertCount(5, $model->aniversariantesDoMesCompleto($today, 20, PHP_INT_MAX));
    }

    public function testPdfLimitIsCheckedBeforePreparingNominalSelect(): void
    {
        for ($n = 0; $n < 501; $n++) { $this->insertAlunoComDva('Volume sintético ' . $n, '2027-01-01'); }
        $recording = new AuditQueryRecorder('sqlite:' . $_ENV['DB_PATH'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        \Model::setConexao($recording);
        try { (new \RelatorioAlunos())->query([], 1, true, 500); self::fail('PDF acima de 500 deve ser recusado.'); }
        catch (\DomainException $error) { self::assertStringContainsString('500', $error->getMessage()); }
        self::assertCount(1, $recording->prepared);
        self::assertStringStartsWith('SELECT COUNT(*)', $recording->prepared[0]);
        self::assertCount(501, (new \RelatorioAlunos())->query([], 1, true)['items']);
    }

    public function testApprovedRedactionPreservesIdentityVaccinationRecordAndHeldStudent(): void
    {
        [$first, $held, $admin, $reviewer] = $this->retentionFixture();
        $policy = $this->policy(); $policy['hold_student_ids'] = [$held];
        $plan = \PrivacyRetention::plan($this->pdo, $policy);
        self::assertSame([$first], array_column($plan['students'], 'id'));
        self::assertStringNotContainsString('11999999999', json_encode($plan));
        self::assertSame(1, \PrivacyRetention::apply($this->pdo, $plan, $plan['confirmation'], $admin, $reviewer));
        $row = $this->pdo->query('SELECT * FROM alunos WHERE id=' . $first)->fetch();
        self::assertSame('', $row['telefone_aluno']);
        self::assertSame('Aluno retido 1', $row['nome_completo']); self::assertSame('2010-05-10', $row['data_nascimento']);
        self::assertSame('11999999999', $this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id=' . $held)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT observacao FROM dvas WHERE id_aluno=' . $first)->fetchColumn());
        self::assertSame('2027-01-01', $this->pdo->query('SELECT data_vencimento FROM dvas WHERE id_aluno=' . $first)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='privacy.operational_data_redacted'")->fetchColumn());
    }

    public function testConcurrentChangeRejectsRedactionWithoutPartialWrite(): void
    {
        [$first, , $admin, $reviewer] = $this->retentionFixture();
        $plan = \PrivacyRetention::plan($this->pdo, $this->policy());
        $this->pdo->exec("UPDATE dvas SET observacao='Alteração concorrente' WHERE id_aluno=" . $first);
        try { \PrivacyRetention::apply($this->pdo, $plan, $plan['confirmation'], $admin, $reviewer); self::fail(); }
        catch (\DomainException $error) { self::assertStringContainsString('mudaram', $error->getMessage()); }
        self::assertSame('11999999999', $this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id=' . $first)->fetchColumn());
    }

    public function testPlanCannotBeAppliedToAnotherDatabaseCopyWithTheSameKeyAndRows(): void
    {
        [, , $admin, $reviewer] = $this->retentionFixture();
        $plan = \PrivacyRetention::plan($this->pdo, $this->policy());
        $copy = (string) getenv('DB_PATH') . '.other.sqlite';
        $other = null;
        try {
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($copy));
            $other = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $otherPlan = \PrivacyRetention::plan($other, $this->policy());
            self::assertSame($plan['students'], $otherPlan['students']);
            self::assertNotSame($plan['database_binding'], $otherPlan['database_binding']);
            try { \PrivacyRetention::apply($other, $plan, $plan['confirmation'], $admin, $reviewer); self::fail('Cross-database plan accepted.'); }
            catch (\DomainException) { self::assertSame(2,(int)$other->query("SELECT COUNT(*) FROM alunos WHERE telefone_aluno='11999999999'")->fetchColumn()); }
        } finally { $other=null; @unlink($copy); @unlink($copy.'-wal'); @unlink($copy.'-shm'); }
    }

    public function testImmutableIdentityAndDvaChangesAlsoInvalidateTheReviewedPlan(): void
    {
        [$first, , $admin, $reviewer] = $this->retentionFixture();
        foreach (["UPDATE alunos SET data_nascimento='2011-01-01' WHERE id=".$first,
            "UPDATE dvas SET data_vencimento='2028-01-01' WHERE id_aluno=".$first] as $sql) {
            $plan = \PrivacyRetention::plan($this->pdo, $this->policy());
            $this->pdo->exec($sql);
            try { \PrivacyRetention::apply($this->pdo, $plan, $plan['confirmation'], $admin, $reviewer); self::fail('Changed reviewed record accepted.'); }
            catch (\DomainException) { self::assertSame('11999999999',$this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id='.$first)->fetchColumn()); }
        }
    }

    public function testHeldRowsCannotHideAnOversizedPlanByTruncatingTheCandidateQuery(): void
    {
        [$first] = $this->retentionFixture();
        $insert = $this->pdo->prepare("INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,ativo,inativado_em,telefone_aluno) VALUES(?,?,'2010-01-01',0,'2020-01-01','11999999999')");
        for ($n=0;$n<1000;$n++) { $insert->execute(['Aluno lote '.$n,'aluno lote '.$n]); }
        $policy=$this->policy(); $policy['hold_student_ids']=[$first];
        $this->expectException(\DomainException::class);
        \PrivacyRetention::plan($this->pdo,$policy);
    }

    public function testAuditFailureRollsBackRedaction(): void
    {
        [$first, , $admin, $reviewer] = $this->retentionFixture();
        $plan = \PrivacyRetention::plan($this->pdo, $this->policy());
        $this->pdo->exec("CREATE TRIGGER fail_privacy BEFORE INSERT ON security_audit WHEN NEW.action='privacy.operational_data_redacted' BEGIN SELECT RAISE(ABORT,'synthetic'); END");
        try { \PrivacyRetention::apply($this->pdo, $plan, $plan['confirmation'], $admin, $reviewer); self::fail(); }
        catch (\PDOException) { self::assertSame('11999999999', $this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id=' . $first)->fetchColumn()); }
        self::assertSame('Nota sintética', $this->pdo->query('SELECT observacao FROM dvas WHERE id_aluno=' . $first)->fetchColumn());
    }

    public function testUnsignedOrUnreviewedPolicyCannotChangeData(): void
    {
        [, , $admin, $reviewer] = $this->retentionFixture();
        $policy = $this->policy(); $policy['approval_reference'] = '';
        try { \PrivacyRetention::plan($this->pdo, $policy); self::fail(); } catch (\DomainException) { self::assertTrue(true); }
        $plan = \PrivacyRetention::plan($this->pdo, $this->policy());
        foreach (['tampered', 'same-reviewer'] as $case) {
            $candidate = $plan;
            if ($case === 'tampered') { $candidate['students'] = []; }
            try { \PrivacyRetention::apply($this->pdo, $candidate, $plan['confirmation'], $admin, $case === 'same-reviewer' ? $admin : $reviewer); self::fail(); }
            catch (\DomainException) { self::assertTrue(true); }
        }
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM alunos WHERE telefone_aluno='11999999999'")->fetchColumn());
    }

    public function testExplicitBatchesOnlyNarrowTheApprovedCohortAndAlreadyRedactedDataIsSkipped(): void
    {
        [$first,$held,$admin,$reviewer]=$this->retentionFixture();
        $active=$this->insertAlunoComDva('Aluno ativo preservado','2027-01-01');
        $this->pdo->exec("UPDATE alunos SET telefone_aluno='11888888888' WHERE id=".$active);
        $policy=$this->policy();
        $policy['student_ids']=[$active,$held,$first];
        $policy['hold_student_ids']=[$held];
        $policy['categories']=['student_contacts'];
        $plan=\PrivacyRetention::plan($this->pdo,$policy);
        self::assertSame([$first],array_column($plan['students'],'id'));
        self::assertSame(1,\PrivacyRetention::apply($this->pdo,$plan,$plan['confirmation'],$admin,$reviewer));
        $empty=\PrivacyRetention::plan($this->pdo,$policy);
        self::assertSame([],$empty['students']);
        self::assertSame(0,\PrivacyRetention::apply($this->pdo,$empty,$empty['confirmation'],$admin,$reviewer));
        self::assertSame('Nota sintética',$this->pdo->query('SELECT observacao FROM dvas WHERE id_aluno='.$first)->fetchColumn());
        self::assertSame('11999999999',$this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id='.$held)->fetchColumn());
        self::assertSame('11888888888',$this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id='.$active)->fetchColumn());
        $policy['categories']=['dva_notes'];
        $plan=\PrivacyRetention::plan($this->pdo,$policy);
        self::assertSame([$first],array_column($plan['students'],'id'));
        self::assertSame(1,\PrivacyRetention::apply($this->pdo,$plan,$plan['confirmation'],$admin,$reviewer));
        self::assertSame([],\PrivacyRetention::plan($this->pdo,$policy)['students']);
        self::assertSame('Nota sintética',$this->pdo->query('SELECT observacao FROM dvas WHERE id_aluno='.$held)->fetchColumn());
    }

    public function testBatchIdentifiersAreStrictAndChangingSignedSelectionCannotExpandThePlan(): void
    {
        [$first,$held,$admin,$reviewer]=$this->retentionFixture();
        foreach ([[$first,$first],['1'],[0],[-1],range(1,1001),[2=>$first]] as $invalid) {
            $policy=$this->policy(); $policy['student_ids']=$invalid;
            try { \PrivacyRetention::plan($this->pdo,$policy); self::fail('Invalid batch selection accepted.'); }
            catch (\DomainException) { self::assertTrue(true); }
        }
        $misspelled=$this->policy(); $misspelled['student_id']=[$first];
        try { \PrivacyRetention::plan($this->pdo,$misspelled); self::fail('Unknown selection key silently broadened the cohort.'); }
        catch (\DomainException) { self::assertTrue(true); }
        $policy=$this->policy(); $policy['student_ids']=[$first];
        $plan=\PrivacyRetention::plan($this->pdo,$policy);
        $plan['policy']['student_ids']=[$first,$held];
        try { \PrivacyRetention::apply($this->pdo,$plan,$plan['confirmation'],$admin,$reviewer); self::fail('Tampered selection accepted.'); }
        catch (\DomainException) { self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM alunos WHERE telefone_aluno='11999999999'")->fetchColumn()); }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[RequiresOperatingSystemFamily('Linux')]
    public function testExplicitCliPreviewsAndAppliesWithoutLoadingTheInstitutionalEnvironment(): void
    {
        [, , $admin, $reviewer] = $this->retentionFixture();
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-retention-' . bin2hex(random_bytes(8));
        mkdir($directory,0700);
        $policyPath=$directory.DIRECTORY_SEPARATOR.'policy.json';
        $planPath=$directory.DIRECTORY_SEPARATOR.'plan.json';
        file_put_contents($policyPath,json_encode($this->policy(),JSON_THROW_ON_ERROR));
        if (DIRECTORY_SEPARATOR === '/') { chmod($policyPath,0600); }
        $run = static function (array $arguments): array {
            $process=proc_open(array_merge([PHP_BINARY,ROOT_PATH.'/bin/privacy-retention.php'],$arguments),[1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,getenv(),['bypass_shell'=>true,'create_no_window'=>true]);
            self::assertIsResource($process);
            $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process),$out,$error];
        };
        $arguments=['--database='.(string)getenv('DB_PATH'),'--keyring='.(string)getenv('GSE_KEYRING_PATH'),'--plan='.$planPath];
        try {
            [$exit,$out,$error]=$run(array_merge($arguments,['--policy='.$policyPath]));
            self::assertSame(0,$exit,$error); self::assertStringContainsString('Nenhum cadastro alterado',$out);
            self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM alunos WHERE telefone_aluno='11999999999'")->fetchColumn());
            $plan=json_decode((string)file_get_contents($planPath),true,16,JSON_THROW_ON_ERROR);
            [$exit,$out,$error]=$run(array_merge($arguments,['--apply','--maintenance-confirmed','--actor='.$admin,'--reviewer='.$reviewer,'--confirm='.$plan['confirmation']]));
            self::assertSame(0,$exit,$error); self::assertStringContainsString('2 aluno(s)',$out);
            self::assertStringNotContainsString('11999999999',$out.$error);
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM alunos WHERE telefone_aluno='11999999999'")->fetchColumn());
            self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        } finally { @unlink($policyPath); @unlink($planPath); @rmdir($directory); }
    }

    public function testCliRejectsRelativeTargetsBeforeOpeningAnyDatabase(): void
    {
        $before=$this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn();
        $process=proc_open([PHP_BINARY,ROOT_PATH.'/bin/privacy-retention.php','--database=relative.sqlite','--keyring=relative.json','--plan=relative-plan.json'],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT_PATH,getenv(),['bypass_shell'=>true,'create_no_window'=>true]);
        self::assertIsResource($process);
        $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(1,proc_close($process));
        self::assertSame('',$out); self::assertStringContainsString('caminhos absolutos',$error);
        self::assertSame($before,$this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
    }

    /** @return array{int,int,int,int} */
    private function retentionFixture(): array
    {
        $first = $this->insertAlunoComDva('Aluno retido 1', '2027-01-01');
        $held = $this->insertAlunoComDva('Aluno retido 2', '2027-01-01');
        $this->pdo->exec("UPDATE alunos SET ativo=0,inativado_em='2020-01-01 00:00:00',telefone_aluno='11999999999'; UPDATE dvas SET observacao='Nota sintética'");
        return [$first, $held, $this->insertUsuario('Administrador retenção'), $this->insertUsuario('Revisor retenção')];
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return ['reference' => 'TESTE-001', 'approval_reference' => 'TESTE-APROVACAO', 'review_reference' => 'TESTE-REVISAO',
            'legal_basis' => 'Política inteiramente sintética para teste', 'inactive_before' => '2020-12-31', 'categories' => ['student_contacts', 'dva_notes'], 'hold_student_ids' => []];
    }
}
