<?php
declare(strict_types=1);
namespace Tests\Integration;
use Tests\Support\DatabaseTestCase;
require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';

final class ProgressaoAnualTest extends DatabaseTestCase
{
    private \MovimentacaoAlunos $service;
    private int $admin;
    private array $classes;
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new \MovimentacaoAlunos();
        $this->admin = $this->insertUsuario();
        $this->classes = [$this->insertTurma('PRIMEIRO EXISTENTE'), $this->insertTurma('SEGUNDO EXISTENTE'), $this->insertTurma('TERCEIRO EXISTENTE')];
        $this->assertTrue($this->service->definirSeries(array_combine($this->classes, ['10','11','12']), $this->admin));
    }
    private function close(array $selected): void
    {
        $closure = $this->service->conferenciaTurma(2027, $this->classes[2]);
        if ($selected === []) {
            $this->assertTrue($this->service->manterTurma(2027, $this->classes[2], $closure['fingerprint'], $this->admin));
            return;
        }
        $passive = new \Passivo();
        $plan = $passive->previewArquivamentoLote($selected, 'CONCLUINTES', 'nova');
        $this->assertIsArray($plan);
        $this->assertIsArray($passive->arquivarAlunosLote($selected, $plan['caixa'], 'nova', $plan['fingerprint'], $this->admin, $closure));
    }
    public function testExigeEnvioDoTerceiroEProgrideTodosUmaVezSemCascata(): void
    {
        $first = $this->insertAlunoComDva('Primeiro', '2099-01-01', $this->classes[0]);
        $second = $this->insertAlunoComDva('Segundo', '2099-01-01', $this->classes[1]);
        $graduate = $this->insertAlunoComDva('Concluinte', '2099-01-01', $this->classes[2]);
        $repeater = $this->insertAlunoComDva('Reprovado terceiro', '2099-01-01', $this->classes[2]);
        $this->assertFalse($this->service->previewAnual([], 2027));
        $this->assertSame('review_required', $this->service->lastErrorCode());
        $this->close([$graduate]);
        $classesBefore = $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll();
        $dvas = $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
        $plan = $this->service->previewAnual([], 2027);
        $this->assertIsArray($plan);
        $this->assertCount(2, $plan['ids']);
        $batch = $this->service->confirmarAutomatico($plan, $this->admin, str_repeat('a', 32));
        $this->assertIsInt($batch);
        $this->assertSame([$this->classes[1],$this->classes[2],$this->classes[2],$this->classes[2]], array_map('intval', $this->pdo->query('SELECT id_turma FROM alunos ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame([1,1,0,1], array_map('intval', $this->pdo->query('SELECT ativo FROM alunos ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame($classesBefore, $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll());
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll());
        $this->assertSame($batch, $this->service->confirmarAutomatico($plan, $this->admin, str_repeat('a', 32)));
        $this->assertFalse($this->service->previewAnual([], 2027));
        $this->assertSame('year_completed', $this->service->lastErrorCode());
        $regression = $this->service->previewRegressao([$first,$second], [], 2027);
        $this->assertIsArray($regression);
        $this->assertIsInt($this->service->confirmarAutomatico($regression, $this->admin, str_repeat('b',32)));
        $this->assertSame($this->classes[0], (int)(new \Aluno())->buscarPorId($first)['id_turma']);
        $this->assertSame($this->classes[1], (int)(new \Aluno())->buscarPorId($second)['id_turma']);
        $this->assertSame(1, (int)(new \Aluno())->buscarPorId($repeater)['ativo']);
        $this->assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.progressed'")->fetchColumn());
        $this->assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.regressed'")->fetchColumn());
    }
    public function testReprovaTodosDoTerceiroSemInventarArquivamentoEInvalidaNovosAlunos(): void
    {
        $this->insertAlunoComDva('Retido', '2099-01-01', $this->classes[2]);
        $this->insertAlunoComDva('Primeiro', '2099-01-01', $this->classes[0]);
        $this->close([]);
        $plan = $this->service->previewAnual([], 2027);
        $this->assertIsArray($plan);
        $this->insertAlunoComDva('Concluinte novo', '2099-01-01', $this->classes[2]);
        $this->assertFalse($this->service->confirmarAutomatico($plan,$this->admin,str_repeat('c',32)));
        $this->assertCount(0,$this->pdo->query('SELECT * FROM alunos_movimentacoes')->fetchAll());
        $this->assertCount(0,$this->pdo->query('SELECT * FROM alunos_passivo')->fetchAll());
    }
    public function testFalhaDeAuditoriaDesfazEnvioEEncerramento(): void
    {
        $student=$this->insertAlunoComDva('Concluinte', '2099-01-01', $this->classes[2]);
        $closure=$this->service->conferenciaTurma(2027,$this->classes[2]);
        $passive=new \Passivo();
        $plan=$passive->previewArquivamentoLote([$student],'TESTE','nova');
        $this->pdo->exec("CREATE TRIGGER fail_close BEFORE INSERT ON security_audit WHEN NEW.action='student.senior_class_closed' BEGIN SELECT RAISE(ABORT,'forced'); END");
        $this->assertFalse($passive->arquivarAlunosLote([$student],'TESTE','nova',$plan['fingerprint'],$this->admin,$closure));
        $this->assertSame(1,(int)(new \Aluno())->buscarPorId($student)['ativo']);
        $this->assertCount(0,$this->pdo->query('SELECT * FROM alunos_passivo')->fetchAll());
        $this->assertCount(0,$this->pdo->query('SELECT * FROM alunos_encerramento_turmas')->fetchAll());
    }
    public function testFalhaDeAuditoriaDesfazProgressaoAnualERecibo(): void
    {
        $student=$this->insertAlunoComDva('Primeiro', '2099-01-01', $this->classes[0]);
        $plan=$this->service->previewAnual([],2027);
        $this->assertIsArray($plan);
        $this->pdo->exec("CREATE TRIGGER fail_year BEFORE INSERT ON security_audit WHEN NEW.action='student.year_progressed' BEGIN SELECT RAISE(ABORT,'forced'); END");
        $this->assertFalse($this->service->confirmarAutomatico($plan,$this->admin,str_repeat('d',32)));
        $this->assertSame($this->classes[0],(int)(new \Aluno())->buscarPorId($student)['id_turma']);
        foreach(['alunos_movimentacoes','alunos_progressao_anos','request_receipts'] as $table) $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
    }
    public function testExigeDestinoQuandoHaDuasTurmasDaMesmaSerie(): void
    {
        $this->insertAlunoComDva('Primeiro', '2099-01-01', $this->classes[0]);
        $other=$this->insertTurma('OUTRO SEGUNDO');
        $this->service->definirSeries([$other=>'11'],$this->admin);
        $this->assertFalse($this->service->previewAnual([],2027));
        $this->assertSame('destination_required',$this->service->lastErrorCode());
        $this->assertIsArray($this->service->previewAnual([$this->classes[0]=>$other],2027));
    }
    public function testProgressaoGeralNaoSeLimitaA200Alunos(): void
    {
        $insert=$this->pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(?,?,?,?)');
        for($index=0;$index<205;$index++) $insert->execute(['Aluno '.$index,'aluno '.$index,'2010-01-01',$this->classes[0]]);
        $plan=$this->service->previewAnual([],2027);
        $this->assertIsArray($plan);
        $this->assertCount(205,$plan['ids']);
        $this->assertIsInt($this->service->confirmarAutomatico($plan,$this->admin,str_repeat('e',32)));
        $this->assertSame(205,(int)$this->pdo->query('SELECT COUNT(*) FROM alunos WHERE id_turma='.$this->classes[1])->fetchColumn());
    }
    public function testRecusaEncerramentoSeSelecaoDeOutraTurmaOuTurmaMudou(): void
    {
        $graduate=$this->insertAlunoComDva('Concluinte', '2099-01-01', $this->classes[2]);
        $outsider=$this->insertAlunoComDva('Outro', '2099-01-01', $this->classes[0]);
        $closure=$this->service->conferenciaTurma(2027,$this->classes[2]);
        $passive=new \Passivo();
        $plan=$passive->previewArquivamentoLote([$outsider],'OUTRA TURMA','nova');
        $this->assertFalse($passive->arquivarAlunosLote([$outsider],'OUTRA TURMA','nova',$plan['fingerprint'],$this->admin,$closure));
        $plan=$passive->previewArquivamentoLote([$graduate],'STALE','nova');
        $this->insertAlunoComDva('Aluno chegou depois', '2099-01-01', $this->classes[2]);
        $this->assertFalse($passive->arquivarAlunosLote([$graduate],'STALE','nova',$plan['fingerprint'],$this->admin,$closure));
        $this->assertSame(1,(int)(new \Aluno())->buscarPorId($graduate)['ativo']);
        $this->assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM alunos_encerramento_turmas')->fetchColumn());
    }
    public function testContagemDasTurmasNaoPedeNovoDestinoParaQuemJaProgrediuNoAno(): void
    {
        $already=$this->insertAlunoComDva('Já progrediu','2099-01-01',$this->classes[0]);
        $review=$this->service->conferencia(2027);
        $this->assertTrue($this->service->confirmarConferencia(2027,$review['fingerprint'],$this->admin));
        $oldPlan=$this->service->preview([$already],$this->classes[1],'progressao',2027);
        $this->assertIsInt($this->service->confirmar($oldPlan,$this->admin,str_repeat('f',32)));
        $new=$this->insertAlunoComDva('Ainda deve progredir','2099-01-01',$this->classes[0]);
        $this->assertSame([$this->classes[0]=>1],$this->service->contagensAnuais(2027));
        $plan=$this->service->previewAnual([],2027);
        $this->assertIsArray($plan);
        $this->assertSame([$new],$plan['ids']);
    }
}
