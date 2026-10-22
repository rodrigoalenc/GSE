<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

require_once ROOT_PATH . '/src/Services/MovimentacaoAlunos.php';
require_once ROOT_PATH . '/src/Model/AlunoImportacao.php';

final class SerieAutomaticaTest extends DatabaseTestCase
{
    public function testMesmoNumeroDeAnoComEnsFundamentalEMedioProgrideDentroDaEtapaCorreta(): void
    {
        $admin = $this->insertUsuario();
        $firstFundamental = $this->insertTurma('1º Ano - Ens. Fundamental');
        $secondFundamental = $this->insertTurma('2º Ano - Ens. Fundamental');
        $firstHigh = $this->insertTurma('1º Ano - Ens. Médio');
        $secondHigh = $this->insertTurma('2º Ano - Ens. Médio');
        $fundamentalStudent = $this->insertAlunoComDva('Aluno fundamental', '2099-01-01', $firstFundamental);
        $highStudent = $this->insertAlunoComDva('Aluno médio', '2099-01-01', $firstHigh);
        $classesBefore = $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll();
        $service = new \MovimentacaoAlunos();
        $state = $service->conferencia(2027);
        $this->assertSame(0, $state['pendentes']);
        $this->assertTrue($service->confirmarConferencia(2027, $state['fingerprint'], $admin));
        $this->assertFalse($service->preview([$fundamentalStudent], $secondHigh, 'progressao', 2027));
        $this->assertFalse($service->preview([$highStudent], $secondFundamental, 'progressao', 2027));
        $plan = $service->previewTodas([], [], 2027);
        $this->assertIsArray($plan);
        $this->assertSame($secondFundamental, $plan['destinos'][$firstFundamental]);
        $this->assertSame($secondHigh, $plan['destinos'][$firstHigh]);
        $this->assertIsInt($service->confirmarTodas($plan, $admin, str_repeat('d', 32)));
        $this->assertSame($secondFundamental, (int) (new \Aluno())->buscarPorId($fundamentalStudent)['id_turma']);
        $this->assertSame($secondHigh, (int) (new \Aluno())->buscarPorId($highStudent)['id_turma']);
        $this->assertSame($classesBefore, $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll());
    }

    public function testAbreviacaoEnsEquivaleAEnsinoAoResolverTurmaETurno(): void
    {
        $origins = ['1º Ano A - Ens. Fundamental', 'Ens. Médio - 1º Ano A'];
        $targets = ['2º Ano A - Ensino Fundamental', '2º Ano A - Ensino Médio'];
        $alternatives = ['2º Ano B - Ensino Fundamental', '2º Ano B - Ensino Médio'];
        $service = new \MovimentacaoAlunos();
        foreach ($origins as $index => $name) {
            $source = $this->insertTurma($name);
            $target = $this->insertTurma($targets[$index]);
            $this->insertTurma($alternatives[$index]);
            $this->assertSame($target, $service->destinoProgressao($source, 2027)['id'] ?? null, $name);
        }
    }

    public function testTurmasExistentesFuncionamNaConferenciaPreviaEConfirmacaoSemConfigurar(): void
    {
        $admin = $this->insertUsuario();
        $first = $this->insertTurma('1º Ano A - EM');
        $second = $this->insertTurma('2º Ano A - EM');
        $third = $this->insertTurma('3º Ano A - EM');
        $student = $this->insertAlunoComDva('Aprovado primeiro', '2099-01-01', $first);
        $senior = $this->insertAlunoComDva('Reprovado terceiro', '2099-01-01', $third);
        $classesBefore = $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll();
        $dvasBefore = $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll();
        $service = new \MovimentacaoAlunos();
        $classes = array_column($service->turmas(), null, 'id');
        $this->assertSame(10, $classes[$first]['serie']);
        $this->assertTrue($classes[$first]['serie_automatica']);
        $this->assertSame(10, $service->alunos($first)[0]['serie']);
        $state = $service->conferencia(2027);
        $this->assertSame(0, $state['pendentes']);
        $this->assertSame([$senior], array_column($state['alunos'], 'id'));
        $this->assertTrue($service->confirmarConferencia(2027, $state['fingerprint'], $admin));
        $plan = $service->preview([$student], $second, 'progressao', 2027);
        $this->assertIsArray($plan);
        $this->assertIsInt($service->confirmar($plan, $admin, str_repeat('a', 32)));
        $this->assertSame($second, (int) (new \Aluno())->buscarPorId($student)['id_turma']);
        $this->assertSame([$senior], array_column($service->conferencia(2027)['alunos'], 'id'));
        $this->assertSame($classesBefore, $this->pdo->query('SELECT * FROM turmas ORDER BY id')->fetchAll());
        $this->assertSame($dvasBefore, $this->pdo->query('SELECT * FROM dvas ORDER BY id')->fetchAll());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas_series')->fetchColumn());
    }

    public function testCorrecaoManualPrevalecePodeSerRemovidaENaoEhPerdidaAoRenomear(): void
    {
        $admin = $this->insertUsuario();
        $class = $this->insertTurma('2º Ano A - EM');
        $service = new \MovimentacaoAlunos();
        $this->assertTrue($service->definirSeries([$class => '11'], $admin));
        $this->assertSame(11, (int) $this->pdo->query('SELECT serie FROM turmas_series')->fetchColumn());
        $this->assertTrue($service->definirSeries([$class => '10'], $admin));
        $this->assertSame(10, array_column($service->turmas(), null, 'id')[$class]['serie']);
        $this->assertTrue((new \Turma())->atualizar($class, '3º Ano A - EM', 2026, $admin));
        $this->assertSame(10, array_column($service->turmas(), null, 'id')[$class]['serie']);
        $this->assertTrue($service->definirSeries([$class => ''], $admin));
        $this->assertSame(12, array_column($service->turmas(), null, 'id')[$class]['serie']);
    }

    public function testTurmasNovasEImportadasSaoReconhecidasSemEtapaAdicional(): void
    {
        $admin = $this->insertUsuario();
        $created = (new \Turma())->cadastrar('6º Ano A', 2026, $admin);
        $this->assertIsInt($created);
        (new \AlunoImportacao())->import([[
            'nome_completo' => 'Aluno importado', 'data_nascimento' => '2010-05-10',
            'turma' => '1º Ano A - EF - TURMA A', 'codigo_sgde' => '9000001',
            'situacao' => 'Em curso', 'pagina' => 1,
        ]], 2026, $admin);
        $service = new \MovimentacaoAlunos();
        $this->assertSame(6, array_column($service->turmas(), null, 'id')[$created]['serie']);
        $this->assertSame(1, $service->alunos()[0]['serie']);
        $this->assertSame(0, $service->conferencia(2027)['pendentes']);
    }

    public function testRenomearTurmaAutomaticaAposPreviaBloqueiaConfirmacao(): void
    {
        $admin = $this->insertUsuario();
        $first = $this->insertTurma('1º Ano A - EM');
        $second = $this->insertTurma('2º Ano A - EM');
        $student = $this->insertAlunoComDva('Aluno', '2099-01-01', $second);
        $service = new \MovimentacaoAlunos();
        $plan = $service->preview([$student], $first, 'regressao', 2027);
        $this->assertIsArray($plan);
        $this->assertTrue((new \Turma())->atualizar($first, '3º Ano A - EM', 2026, $admin));
        $this->assertFalse($service->confirmar($plan, $admin, str_repeat('b', 32)));
        $this->assertSame($second, (int) (new \Aluno())->buscarPorId($student)['id_turma']);
        $this->assertCount(0, $service->historico($student));
    }

    public function testAutomaticoNaoIncluiRecemProgredidosNaConferenciaENaoEscondeAlunosSemTurma(): void
    {
        $admin = $this->insertUsuario();
        $second = $this->insertTurma('2º Ano A - EM');
        $third = $this->insertTurma('3º Ano A - EM');
        $student = $this->insertAlunoComDva('Aprovado segundo', '2099-01-01', $second);
        $service = new \MovimentacaoAlunos();
        $state = $service->conferencia(2027);
        $this->assertTrue($service->confirmarConferencia(2027, $state['fingerprint'], $admin));
        $plan = $service->preview([$student], $third, 'progressao', 2027);
        $this->assertIsArray($plan);
        $this->assertIsInt($service->confirmar($plan, $admin, str_repeat('c', 32)));
        $this->assertSame([], $service->conferencia(2027)['alunos']);
        $this->assertTrue($service->conferencia(2027)['liberada']);
        $this->insertAlunoComDva('Sem turma', '2099-01-01');
        $state = $service->conferencia(2027);
        $this->assertSame(1, $state['pendentes']);
        $this->assertFalse($state['liberada']);
    }

    public function testTelaAbreSomentePendenciasEMantemRevisaoDasIdentificadas(): void
    {
        $automatic = $this->insertTurma('1º Ano A - EF');
        $unknown = $this->insertTurma('3º B');
        $inactive = $this->insertTurma('Turma antiga');
        $this->pdo->prepare('UPDATE turmas SET ativo=0 WHERE id=?')->execute([$inactive]);
        $this->insertAlunoComDva('Aluno pendente', '2099-01-01', $unknown);
        $service = new \MovimentacaoAlunos();
        $classes = $service->turmas();
        $series = \MovimentacaoAlunos::series();
        $year = 2027;
        $state = $service->conferencia($year);
        $type = 'regressao';
        $configure = false;
        $preview = null;
        $students = [];
        $classId = 0;
        $search = '';
        $_GET = [];
        ob_start();
        try {
            require ROOT_PATH . '/src/Views/alunos/movimentar.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('id="series-' . $unknown . '"', $html);
        $this->assertStringNotContainsString('id="series-' . $automatic . '"', $html);
        $this->assertStringNotContainsString('id="series-' . $inactive . '"', $html);
        $this->assertStringContainsString('Revisar todas as séries', $html);
        $configure = true;
        ob_start();
        try {
            require ROOT_PATH . '/src/Views/alunos/movimentar.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('id="series-' . $automatic . '"', $html);
        $this->assertStringContainsString('Identificada automaticamente: 1º ano do ensino fundamental', $html);
        $this->assertStringContainsString('value="" selected', $html);
    }
}
