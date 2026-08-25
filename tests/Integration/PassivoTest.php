<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use Tests\Support\DatabaseTestCase;

final class PassivoTest extends DatabaseTestCase
{
    public function testCrudSearchFiltersPaginationAndLogicalLifecycle(): void
    {
        $actor = $this->insertUsuario('Admin Passivo');
        $model = new \Passivo();
        $id = $model->cadastrar([
            'nome_completo' => "Jos\u{00E9} da Silva",
            'data_nascimento' => '2000-01-01',
            'numero' => '7',
            'caixa' => 'CX-1',
        ], $actor);

        $this->assertIsInt($id);
        $this->assertSame(1, $model->paginate(['q' => 'Jose', 'ativo' => '1'])['total']);
        $this->assertSame(1, $model->paginate(['q' => "JOSE\u{0301}", 'ativo' => '1'])['total']);
        $this->assertSame(1, $model->paginate(['caixa' => 'cx-1', 'ativo' => '1'])['total']);
        $this->assertSame("Jos\u{00E9} da Silva", $model->buscarPorId($id)['nome_completo'] ?? null);

        $percentId = $model->cadastrar([
            'nome_completo' => 'Pessoa 100%', 'data_nascimento' => '', 'numero' => '', 'caixa' => 'CX-2',
        ], $actor);
        $this->assertIsInt($percentId);
        $this->assertSame(1, $model->paginate(['q' => '%', 'ativo' => '1'])['total']);
        $this->assertSame(0, $model->paginate(['q' => '_', 'ativo' => '1'])['total']);

        $this->assertTrue($model->atualizar($id, [
            'nome_completo' => "Jos\u{00E9} Atualizado", 'data_nascimento' => '2000-01-01',
            'numero' => '8', 'caixa' => 'CX-1',
        ], $actor));
        $this->assertFalse($model->cadastrar([
            'nome_completo' => 'Conflito', 'data_nascimento' => '', 'numero' => '8', 'caixa' => 'cx-1',
        ], $actor));
        $this->assertSame('location_conflict', $model->lastErrorCode());

        $this->assertTrue($model->definirAtivo($id, false, $actor));
        $this->assertSame(0, (int) $model->buscarPorId($id)['ativo']);
        $this->assertTrue($model->definirAtivo($id, true, $actor));
        $this->assertSame(1, (int) $model->buscarPorId($id)['ativo']);
        $this->assertSame(2, $model->resumo()['registros']);

        $this->expectException(PDOException::class);
        $this->pdo->exec('DELETE FROM alunos_passivo WHERE id = ' . $id);
    }

    public function testArchiveInactiveStudentPreservesStudentAndDvaAndRejectsDuplicate(): void
    {
        $actor = $this->insertUsuario('Admin Integracao');
        $name = \src\Core\TextNormalizer::displayName('Aluno Inativo');
        $statement = $this->pdo->prepare(
            'INSERT INTO alunos (nome_completo, nome_normalizado, data_nascimento, ativo) VALUES (?, ?, ?, 0)'
        );
        $statement->execute([$name, \src\Core\TextNormalizer::comparisonKey($name), '2010-05-10']);
        $studentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO dvas (id_aluno, data_vencimento) VALUES (?, ?)')
            ->execute([$studentId, '2027-01-01']);

        $model = new \Passivo();
        $passiveId = $model->arquivarAluno($studentId, ['caixa' => 'CX-9', 'numero' => '1'], $actor);

        $this->assertIsInt($passiveId);
        $this->assertSame($studentId, (int) $model->buscarPorId($passiveId)['aluno_origem_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos WHERE id = ' . $studentId)->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas WHERE id_aluno = ' . $studentId)->fetchColumn());
        $this->assertFalse($model->arquivarAluno($studentId, ['caixa' => 'CX-10', 'numero' => '2'], $actor));
        $this->assertSame('origin_conflict', $model->lastErrorCode());

        $this->pdo->exec('UPDATE alunos SET ativo = 1 WHERE id = ' . $studentId);
        $this->assertTrue($model->definirAtivo($passiveId, false, $actor));
        $this->assertFalse($model->arquivarAluno($studentId, ['caixa' => 'CX-10', 'numero' => '2'], $actor));
        $this->assertSame('active_student', $model->lastErrorCode());
    }

    public function testEnumerationUsesOnlySelectedBoxAndPreservesExistingNumbers(): void
    {
        $actor = $this->insertUsuario('Admin Enumeracao');
        $model = new \Passivo();
        $model->cadastrar(['nome_completo' => 'Zeta', 'data_nascimento' => '', 'numero' => '7', 'caixa' => 'A'], $actor);
        $ana = $model->cadastrar(['nome_completo' => 'Ana', 'data_nascimento' => '', 'numero' => '', 'caixa' => 'A'], $actor);
        $bia = $model->cadastrar(['nome_completo' => 'Bia', 'data_nascimento' => '', 'numero' => '', 'caixa' => 'A'], $actor);
        $other = $model->cadastrar(['nome_completo' => 'Outra', 'data_nascimento' => '', 'numero' => '', 'caixa' => 'B'], $actor);

        $preview = $model->previewEnumeracao('a');
        $this->assertIsArray($preview);
        $this->assertSame([$ana, $bia], array_column($preview['assignments'], 'id'));
        $this->assertSame(['8', '9'], array_column($preview['assignments'], 'numero'));
        $this->assertSame(2, $model->aplicarEnumeracao('A', $preview['assignments'], $actor));
        $this->assertSame('8', $model->buscarPorId($ana)['numero']);
        $this->assertSame('9', $model->buscarPorId($bia)['numero']);
        $this->assertNull($model->buscarPorId($other)['numero']);
        $this->assertCount(3, $model->listarParaTxt('A'));
    }

    public function testValidationRejectsFutureDateInvalidUtf8AndMalformedLocation(): void
    {
        $model = new \Passivo();

        $this->assertFalse($model->validarDados(['nome_completo' => '', 'caixa' => 'A']));
        $this->assertSame('invalid_name', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => 'Nome', 'caixa' => '../A']));
        $this->assertSame('invalid_box', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => 'Nome', 'caixa' => 'A', 'data_nascimento' => '2999-01-01']));
        $this->assertSame('future_birth_date', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => "Nome\xFF", 'caixa' => 'A']));
        $this->assertSame('invalid_utf8', $model->lastErrorCode());
    }

    public function testRequiredAuditFailureRollsBackSensitiveOperation(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Passivo');
        $this->pdo->exec(
            "CREATE TRIGGER fail_passive_audit BEFORE INSERT ON security_audit
             WHEN NEW.action = 'passive.created'
             BEGIN SELECT RAISE(ABORT, 'forced_passive_audit_failure'); END"
        );
        $model = new \Passivo();

        $this->assertFalse($model->cadastrar([
            'nome_completo' => 'Rollback Auditoria', 'data_nascimento' => '', 'numero' => '1', 'caixa' => 'AUDIT',
        ], $actor));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }
}
