<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class ModuleTwoTest extends DatabaseTestCase
{
    public function testTurmasPossuemCrudLogicoDuplicidadeEProtecaoDeVinculo(): void
    {
        $model = new \Turma();
        $id = $model->cadastrar('  2 Ano   B ', 2026);

        $this->assertIsInt($id);
        $this->assertSame('2 Ano B', $model->buscarPorId($id)['nome_turma']);
        $this->assertFalse($model->cadastrar('2 ano b', 2026));
        $this->assertSame('duplicate_class', $model->lastErrorCode());
        $this->assertIsInt($model->cadastrar('2 Ano B', 2027));
        $this->assertTrue($model->atualizar($id, '2 Ano C', 2026));

        $actor = $this->insertUsuario();
        $student = (new \Aluno())->cadastrar($this->validStudentData($id), $actor);
        $this->assertIsInt($student);
        $this->assertFalse($model->definirAtiva($id, false));
        $this->assertSame('active_students', $model->lastErrorCode());

        $this->assertTrue((new \Aluno())->definirAtivo($student, false, $actor));
        $this->assertTrue($model->definirAtiva($id, false));
        $this->assertSame(0, (int) $model->buscarPorId($id)['ativo']);
        $this->assertTrue($model->definirAtiva($id, true));
    }

    public function testTurmasUnicodeColidemNoMesmoAnoMasNaoEmAnosDiferentes(): void
    {
        $model = new \Turma();
        $first = $model->cadastrar('3º Ano Á', 2026);

        $this->assertIsInt($first);
        $this->assertFalse($model->cadastrar("3º ANO A\u{0301}", 2026));
        $this->assertSame('duplicate_class', $model->lastErrorCode());
        $this->assertIsInt($model->cadastrar('3º ANO Á', 2027));
        $this->assertSame('3º Ano Á', $model->buscarPorId($first)['nome_turma']);
        $this->assertSame('3º ano á', $model->buscarPorId($first)['nome_normalizado']);
    }

    public function testValidacoesDeAlunoRecusamDatasTelefonesETurmasInvalidas(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();

        $data = $this->validStudentData($classId);
        $data['data_nascimento'] = 'amanha';
        $this->assertFalse($model->cadastrar($data, $actor));
        $this->assertSame('invalid_birth_date', $model->lastErrorCode());

        $data = $this->validStudentData($classId);
        $data['data_nascimento'] = date('Y-m-d', strtotime('+1 day'));
        $this->assertFalse($model->cadastrar($data, $actor));
        $this->assertSame('future_birth_date', $model->lastErrorCode());

        $data = $this->validStudentData($classId);
        $data['telefone_aluno'] = '123';
        $this->assertFalse($model->cadastrar($data, $actor));
        $this->assertSame('invalid_phone', $model->lastErrorCode());

        $data = $this->validStudentData(999999);
        $this->assertFalse($model->cadastrar($data, $actor));
        $this->assertSame('invalid_class', $model->lastErrorCode());

        $this->pdo->prepare('UPDATE turmas SET ativo = 0 WHERE id = ?')->execute([$classId]);
        $this->assertFalse($model->cadastrar($this->validStudentData($classId), $actor));
        $this->assertSame('invalid_class', $model->lastErrorCode());
    }

    public function testDuplicidadeExigeConfirmacaoMasNaoCriaRestricaoAbsoluta(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();
        $data = $this->validStudentData($classId);

        $this->assertIsInt($model->cadastrar($data, $actor));
        $this->assertFalse($model->cadastrar($data, $actor));
        $this->assertSame('possible_duplicate', $model->lastErrorCode());
        $this->assertIsInt($model->cadastrar($data, $actor, null, true));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
    }

    public function testEdicaoIgnoraOProprioAlunoEBloqueiaColisaoNormalizadaAteConfirmacao(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();
        $first = $this->validStudentData($classId);
        $first['nome_completo'] = 'Álvaro Souza';
        $second = $this->validStudentData($classId);
        $second['nome_completo'] = 'Outra Pessoa';
        $second['data_nascimento'] = '2011-03-10';
        $firstId = $model->cadastrar($first, $actor);
        $secondId = $model->cadastrar($second, $actor);

        $this->assertIsInt($firstId);
        $this->assertIsInt($secondId);
        $this->assertTrue($model->atualizar($firstId, $first));

        $collision = $second;
        $collision['nome_completo'] = "  A\u{0301}LVARO   SOUZA ";
        $collision['data_nascimento'] = $first['data_nascimento'];
        $this->assertFalse($model->atualizar($secondId, $collision));
        $this->assertSame('possible_duplicate', $model->lastErrorCode());
        $this->assertSame('Outra Pessoa', $model->buscarPorId($secondId)['nome_completo']);

        $this->assertTrue($model->atualizar($secondId, $collision, true));
        $this->assertSame('ÁLVARO SOUZA', $model->buscarPorId($secondId)['nome_completo']);
        $this->assertSame('álvaro souza', $model->buscarPorId($secondId)['nome_normalizado']);
    }

    public function testPesquisaDeAlunoReconheceCaixaAcentuadaENfcEquivalente(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();
        $data = $this->validStudentData($classId);
        $data['nome_completo'] = 'João Álvares';

        $studentId = $model->cadastrar($data, $actor);

        $this->assertIsInt($studentId);
        $this->assertSame(1, $model->paginate(['q' => 'JOÃO'], 1, 10)['total']);
        $this->assertSame(1, $model->paginate(['q' => "A\u{0301}LVARES"], 1, 10)['total']);
        $this->assertCount(1, (new \Turma())->listar('1 ANO A'));
    }

    public function testMesmoNomeComNascimentoDiferenteNaoEhDuplicidade(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();
        $first = $this->validStudentData($classId);
        $second = $first;
        $second['data_nascimento'] = '2011-02-28';

        $this->assertIsInt($model->cadastrar($first, $actor));
        $this->assertIsInt($model->cadastrar($second, $actor));
    }

    public function testRenovacaoDaDvaTemUmaAtivaPreservaAtorEExecutaRollback(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = (new \Aluno())->cadastrar($this->validStudentData($classId), $actor);
        $this->assertIsInt($studentId);
        $model = new \Dva();

        $initial = $model->registrar($studentId, '2026-08-19', 'Histórica', $actor);
        $renewed = $model->registrar($studentId, '2026-09-19', 'Nova', $actor);
        $this->assertFalse($initial['renewed']);
        $this->assertTrue($renewed['renewed']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM dvas WHERE ativo = 1")->fetchColumn());
        $this->assertSame($actor, (int) $model->atualDoAluno($studentId)['id_usuario_registro']);

        $this->expectException(\PDOException::class);
        $statement = $this->pdo->prepare(
            'INSERT INTO dvas (id_aluno, id_usuario_registro, data_vencimento, ativo) VALUES (?, ?, ?, 1)'
        );
        $statement->execute([$studentId, $actor, '2027-01-01']);
    }

    public function testFalhaNaInsercaoDeRenovacaoRestauraDvaAnterior(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $studentId = (new \Aluno())->cadastrar($this->validStudentData($classId), $actor);
        $model = new \Dva();
        $current = $model->registrar($studentId, '2026-10-01', null, $actor);
        $this->pdo->exec(
            "CREATE TRIGGER fail_test_dva BEFORE INSERT ON dvas
             WHEN NEW.data_vencimento = '2026-11-01'
             BEGIN SELECT RAISE(ABORT, 'test_failure'); END"
        );

        $this->assertFalse($model->registrar($studentId, '2026-11-01', null, $actor));
        $this->assertSame('database_error', $model->lastErrorCode());
        $this->assertSame($current['id'], (int) $model->atualDoAluno($studentId)['id']);
        $this->assertCount(1, $model->historicoDoAluno($studentId));
    }

    public function testAuditoriaAceitaRecursoSemConfundirComUsuarioAfetado(): void
    {
        $actor = $this->insertUsuario();
        \AuditLogger::record('student.updated', \AuditLogger::SUCCESS, $actor, null, 'Aluno atualizado.', 'student', 42);
        $row = $this->pdo->query('SELECT * FROM security_audit ORDER BY id DESC LIMIT 1')->fetch();

        $this->assertNull($row['target_user_id']);
        $this->assertSame('student', $row['resource_type']);
        $this->assertSame(42, (int) $row['resource_id']);
        $this->assertArrayNotHasKey('telefone', $row);
    }

    public function testPesquisaTrataCuringasComoTextoENaoExisteExclusaoFisica(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $model = new \Aluno();

        foreach (['Nome % Literal', 'Nome _ Literal', 'Nome \\ Literal'] as $name) {
            $data = $this->validStudentData($classId);
            $data['nome_completo'] = $name;
            $this->assertIsInt($model->cadastrar($data, $actor));
        }

        $this->assertSame(1, $model->paginate(['q' => '%'], 1, 10)['total']);
        $this->assertSame(1, $model->paginate(['q' => '_'], 1, 10)['total']);
        $this->assertSame(1, $model->paginate(['q' => '\\'], 1, 10)['total']);
        $this->assertFalse(method_exists($model, 'excluir'));
        $this->assertFalse($model->buscarPorId(999999));
    }

    public function testAlunoInativoNaoRecebeNovaDva(): void
    {
        $classId = $this->insertTurma();
        $actor = $this->insertUsuario();
        $students = new \Aluno();
        $studentId = $students->cadastrar($this->validStudentData($classId), $actor);
        $this->assertTrue($students->definirAtivo($studentId, false, $actor));

        $dva = new \Dva();
        $this->assertFalse($dva->registrar($studentId, '2026-09-01', null, $actor));
        $this->assertSame('inactive_student', $dva->lastErrorCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function validStudentData(int $classId): array
    {
        return [
            'nome_completo' => 'Aluno de Teste',
            'data_nascimento' => '2012-02-29',
            'id_turma' => $classId,
            'telefone_aluno' => '(65) 99999-1234',
            'telefone_responsavel' => '',
        ];
    }
}
