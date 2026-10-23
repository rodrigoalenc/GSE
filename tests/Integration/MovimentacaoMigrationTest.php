<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use src\Core\DatabaseInitializer;
use Tests\Support\DatabaseTestCase;

final class MovimentacaoMigrationTest extends DatabaseTestCase
{
    private function versionEighteen(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $migration = new \ReflectionMethod(DatabaseInitializer::class, 'runMigration');
        $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
        for ($version = 1; $version <= 18; $version++) {
            $migration->invoke(null, $pdo, $version, $schema);
        }
        return $pdo;
    }

    public function testUpgradeAddsEmptyMetadataWithoutChangingStudentsClassesOrDocuments(): void
    {
        $pdo = $this->versionEighteen();
        $pdo->exec("INSERT INTO turmas(id,nome_turma,nome_normalizado,ano_letivo) VALUES(71,'3º A legado','3º a legado',2026);
            INSERT INTO alunos(id,nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(81,'Aluno legado','aluno legado','2010-01-01',71);
            INSERT INTO dvas(id,id_aluno,data_vencimento,observacao) VALUES(91,81,'2027-01-01','Documento histórico')");
        $before = [];
        foreach (['turmas', 'alunos', 'dvas', 'alunos_passivo', 'request_receipts', 'security_audit'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
        }
        DatabaseInitializer::initialize($pdo, true);
        DatabaseInitializer::initialize($pdo, true);
        foreach ($before as $table => $rows) {
            $after = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
            if (in_array($table, ['turmas', 'alunos', 'alunos_passivo'], true)) {
                foreach ($after as &$row) {
                    $this->assertSame(1, (int) $row['revisao']);
                    unset($row['revisao']);
                }
                unset($row);
            }
            $this->assertSame($rows, $after);
        }
        foreach (['turmas_series', 'alunos_conferencias', 'alunos_movimentacao_lotes', 'alunos_movimentacoes', 'alunos_encerramento_turmas', 'turmas_progressao_destinos', 'alunos_progressao_anos'] as $table) {
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $this->assertSame(21, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertEmpty($pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testFailureRecordingMigrationRollsBackAllNewTables(): void
    {
        $pdo = $this->versionEighteen();
        $pdo->exec("CREATE TRIGGER fail_movement_migration BEFORE INSERT ON schema_migrations
            WHEN NEW.version=19 BEGIN SELECT RAISE(ABORT,'forced19'); END");
        try {
            (new \ReflectionMethod(DatabaseInitializer::class, 'runMigration'))->invoke(null, $pdo, 19, file_get_contents(ROOT_PATH . '/database/schema.sql'));
            $this->fail('Expected migration failure.');
        } catch (\PDOException) {
            $this->assertSame(18, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'
                AND name IN ('turmas_series','alunos_conferencias','alunos_movimentacao_lotes','alunos_movimentacoes')")->fetchColumn());
        }
    }
}
