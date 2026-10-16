<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once ROOT_PATH . '/src/Model/AlunoImportacao.php';

use DomainException;
use PDOException;
use Tests\Support\DatabaseTestCase;

final class AlunoImportacaoTest extends DatabaseTestCase
{
    public function testPreviewDoesNotWriteAndImportCreatesOnlyNeededClassesAndStudents(): void
    {
        $actor = $this->insertUsuario('Admin importacao');
        $classId = $this->classForYear('Turma existente', 2026);
        $rows = [
            $this->row('Ana Silva', 'Turma existente'),
            $this->row('José Souza', 'Nova turma'),
            $this->row('Maria Souza', '  NOVA   TURMA  '),
        ];
        $before = $this->state();
        $model = new \AlunoImportacao();
        $preview = $model->preview($rows, 2026);

        $this->assertSame($before, $this->state());
        $this->assertSame(['importar' => 3, 'duplicado' => 0, 'revisar' => 0], $preview['counts']);
        $this->assertCount(1, $preview['classes_new']);
        $this->assertSame(2026, $preview['year']);
        $this->assertSame('Ana Silva', $preview['items'][0]['nome_completo']);
        $this->assertSame(['imported' => 3, 'classes_created' => 1, 'duplicates' => 0, 'review' => 0], $model->import($rows, 2026, $actor));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame($classId, (int) $this->pdo->query("SELECT id_turma FROM alunos WHERE nome_completo = 'Ana Silva'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos WHERE telefone_aluno IS NULL AND telefone_responsavel IS NULL')->fetchColumn());
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action = 'student.created'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action = 'class.created'")->fetchColumn());
        $audit = (string) $this->pdo->query("SELECT description FROM security_audit WHERE action = 'student.import_completed'")->fetchColumn();
        $this->assertStringContainsString('3 alunos', $audit);
        $this->assertStringNotContainsString('Ana Silva', $audit);
    }

    public function testExistingAndRepeatedIdentitiesSkipWithoutCreatingUnusedClassesOrChangingDva(): void
    {
        $actor = $this->insertUsuario('Admin duplicados');
        $classId = $this->classForYear('Original', 2026);
        $studentId = $this->insertAlunoComDva('José da Silva', '2027-01-01', $classId);
        $rows = [
            $this->row("  JOSÉ  DA SILVA ", 'Turma só para duplicado'),
            $this->row('Outro Aluno', 'Nova turma'),
            $this->row('OUTRO ALUNO', 'Turma descartada'),
            $this->row('José da Silva', 'Nova turma', '2011-05-10'),
        ];
        $model = new \AlunoImportacao();
        $preview = $model->preview($rows, 2026);

        $this->assertSame(['importar' => 2, 'duplicado' => 2, 'revisar' => 0], $preview['counts']);
        $this->assertSame(['Nova turma'], $preview['classes_new']);
        $this->assertSame(['imported' => 2, 'classes_created' => 1, 'duplicates' => 2, 'review' => 0], $model->import($rows, 2026, $actor));
        $this->assertSame($classId, (int) $this->pdo->query('SELECT id_turma FROM alunos WHERE id = ' . $studentId)->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas WHERE ativo = 1')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame(['imported' => 0, 'classes_created' => 0, 'duplicates' => 4, 'review' => 0], $model->import($rows, 2027, $actor));
    }

    public function testInactiveStudentRemainsInactiveAndIsReportedAsDuplicate(): void
    {
        $actor = $this->insertUsuario('Admin inativos');
        $studentId = $this->insertAlunoComDva('Aluno inativo', '2027-01-01');
        $this->pdo->prepare('UPDATE alunos SET ativo = 0,inativado_em = ?,inativado_por = ? WHERE id = ?')
            ->execute(['2026-01-01 00:00:00', $actor, $studentId]);
        $before = $this->pdo->query('SELECT * FROM alunos')->fetchAll();
        $model = new \AlunoImportacao();
        $rows = [$this->row('ALUNO INATIVO', 'Não criar')];

        $preview = $model->preview($rows, 2026);
        $this->assertSame('duplicado', $preview['items'][0]['action']);
        $this->assertStringContainsString('inativo', $preview['items'][0]['reason']);
        $this->assertSame(['imported' => 0, 'classes_created' => 0, 'duplicates' => 1, 'review' => 0], $model->import($rows, 2026, $actor));
        $this->assertSame($before, $this->pdo->query('SELECT * FROM alunos')->fetchAll());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
    }

    public function testInactiveAndLegacyClassesRequireReviewButExactActiveYearTakesPrecedence(): void
    {
        $actor = $this->insertUsuario('Admin turmas revisar');
        $this->classForYear('Inativa', 2026, false);
        $this->insertTurma('Legada');
        $this->insertTurma('Com ano');
        $classId = $this->classForYear('Com ano', 2026);
        $rows = [
            $this->row('Aluno um', 'Inativa'),
            $this->row('Aluno dois', 'Legada'),
            $this->row('Aluno três', 'Com ano'),
        ];
        $model = new \AlunoImportacao();

        $plan = $model->preview($rows, 2026);
        $this->assertSame(['importar' => 1, 'duplicado' => 0, 'revisar' => 2], $plan['counts']);
        $this->assertSame([], $plan['classes_new']);
        $this->assertStringContainsString('inativa', $plan['items'][0]['reason']);
        $this->assertStringContainsString('sem ano letivo', $plan['items'][1]['reason']);
        $this->assertSame(['imported' => 1, 'classes_created' => 0, 'duplicates' => 0, 'review' => 2], $model->import($rows, 2026, $actor));
        $this->assertSame($classId, (int) $this->pdo->query('SELECT id_turma FROM alunos')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT ativo FROM turmas WHERE nome_turma = 'Inativa'")->fetchColumn());
    }

    public function testSchoolYearDistinguishesClassesWithoutMovingExistingStudents(): void
    {
        $actor = $this->insertUsuario('Admin anos');
        $firstClass = $this->classForYear('1º Ano A', 2026);
        $model = new \AlunoImportacao();
        $first = [$this->row('Primeiro aluno', '1º Ano A')];
        $this->assertSame(1, $model->import($first, 2026, $actor)['imported']);
        $this->assertSame(0, $model->import($first, 2027, $actor)['classes_created']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame(1, $model->import([$this->row('Segundo aluno', '1º Ano A')], 2027, $actor)['classes_created']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertSame($firstClass, (int) $this->pdo->query("SELECT id_turma FROM alunos WHERE nome_completo = 'Primeiro aluno'")->fetchColumn());
    }

    public function testTransferredAndOtherStatusesNeverImportEvenWhenTheirDataIsInvalid(): void
    {
        $actor = $this->insertUsuario('Admin situacoes');
        $rows = [$this->row('Aluno em curso', 'Importar')];

        foreach (['Transferido', 'Cancelado', 'Concluído', '', 'Não está Em curso'] as $status) {
            $row = $this->row('', 'Não criar', '31/02/2020');
            $row['situacao'] = $status;
            $rows[] = $row;
        }

        $model = new \AlunoImportacao();
        $this->assertCount(1, $model->preview($rows, 2026)['items']);
        $this->assertSame(['imported' => 1, 'classes_created' => 1, 'duplicates' => 0, 'review' => 0], $model->import($rows, 2026, $actor));
        $this->assertSame('Aluno em curso', $this->pdo->query('SELECT nome_completo FROM alunos')->fetchColumn());
    }

    public function testInvalidEligibleDataOrYearFailsBeforeAnyWrite(): void
    {
        $actor = $this->insertUsuario('Admin invalidos');
        $invalidRows = [
            $this->row('', 'Turma'), $this->row(str_repeat('A', 151), 'Turma'),
            $this->row('Aluno', ''), $this->row('Aluno', str_repeat('A', 101)),
            $this->row('Aluno', 'Turma', '2010-02-30'),
            $this->row('Aluno', 'Turma', '3000-01-01'),
            $this->row('Aluno', 'Turma', '0000-01-01'),
        ];
        $model = new \AlunoImportacao();
        $before = $this->state();

        foreach ($invalidRows as $row) {
            try {
                $model->import([$this->row('Válido primeiro', 'Não criar'), $row], 2026, $actor);
                $this->fail('Dados inválidos foram aceitos.');
            } catch (DomainException) {
                $this->assertSame($before, $this->state());
            }
        }

        foreach ([1999, (int) gmdate('Y') + 6] as $year) {
            try {
                $model->import([$this->row('Aluno', 'Turma')], $year, $actor);
                $this->fail('Ano inválido foi aceito.');
            } catch (DomainException) {
                $this->assertSame($before, $this->state());
            }
        }
    }

    public function testInactiveAndNonAdministrativeActorsCannotImport(): void
    {
        $inactive = $this->insertUsuario('Admin inativo', 'administrador', false);
        $employee = $this->insertUsuario('Funcionario importar', 'funcionario');
        $before = $this->state();
        $model = new \AlunoImportacao();

        foreach ([0, 999999, $inactive, $employee] as $actorId) {
            try {
                $model->import([$this->row('Aluno', 'Não criar')], 2026, $actorId);
                $this->fail('Importação autorizada para ator inválido.');
            } catch (DomainException) {
                $this->assertSame($before, $this->state());
            }
        }
    }

    public function testImportRechecksDatabaseChangesAfterPreview(): void
    {
        $actor = $this->insertUsuario('Admin previa alterada');
        $classId = $this->classForYear('Inativada depois', 2026);
        $rows = [$this->row('Inserido depois', 'Turma descartada'), $this->row('Revisar depois', 'Inativada depois')];
        $model = new \AlunoImportacao();
        $this->assertSame(2, $model->preview($rows, 2026)['counts']['importar']);
        $this->insertAlunoComDva('Inserido depois', '2027-01-01');
        $this->pdo->prepare('UPDATE turmas SET ativo = 0 WHERE id = ?')->execute([$classId]);

        $this->assertSame(['imported' => 0, 'classes_created' => 0, 'duplicates' => 1, 'review' => 1], $model->import($rows, 2026, $actor));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
    }

    public function testEveryRequiredAuditFailureRollsBackClassesStudentsAndOtherAuditEntries(): void
    {
        $actor = $this->insertUsuario('Admin atomicidade importar');
        $model = new \AlunoImportacao();
        $rows = [$this->row('Aluno um', 'Turma nova'), $this->row('Aluno dois', 'Outra turma nova')];
        $before = $this->state();

        foreach (['class.created', 'student.created', 'student.import_completed'] as $action) {
            $this->pdo->exec("CREATE TRIGGER reject_import_audit BEFORE INSERT ON security_audit WHEN NEW.action = '" . $action . "' BEGIN SELECT RAISE(ABORT, 'audit_rejected'); END");

            try {
                $model->import($rows, 2026, $actor);
                $this->fail('Falha de auditoria não interrompeu a importação.');
            } catch (PDOException) {
                $this->assertSame($before, $this->state());
            } finally {
                $this->pdo->exec('DROP TRIGGER reject_import_audit');
            }
        }

        $this->assertSame(2, $model->import($rows, 2026, $actor)['imported']);
    }

    /** @return array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int} */
    private function row(string $name, string $class, string $birth = '2010-05-10'): array
    {
        return [
            'nome_completo' => $name, 'data_nascimento' => $birth, 'turma' => $class,
            'codigo_sgde' => '123456', 'situacao' => 'Em curso', 'pagina' => 1,
        ];
    }

    private function classForYear(string $name, int $year, bool $active = true): int
    {
        $id = $this->insertTurma($name);
        $this->pdo->prepare('UPDATE turmas SET ano_letivo = ?,ativo = ? WHERE id = ?')->execute([$year, $active ? 1 : 0, $id]);

        return $id;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function state(): array
    {
        $state = [];

        foreach (['alunos', 'turmas', 'dvas', 'security_audit', 'sqlite_sequence'] as $table) {
            $state[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
        }

        return $state;
    }
}
