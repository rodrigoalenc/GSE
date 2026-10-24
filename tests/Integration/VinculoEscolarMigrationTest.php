<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use src\Core\DatabaseInitializer;
use Tests\Support\DatabaseTestCase;

final class VinculoEscolarMigrationTest extends DatabaseTestCase
{
    private function versionTwentyOne(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $migration = new \ReflectionMethod(DatabaseInitializer::class, 'runMigration');
        $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
        for ($version = 1; $version <= 21; $version++) {
            $migration->invoke(null, $pdo, $version, $schema);
        }
        return $pdo;
    }

    public function testMigracaoPreservaDadosConvergeEExecutaUmaVez(): void
    {
        $old = $this->versionTwentyOne();
        $old->exec("INSERT INTO turmas(id,nome_turma,nome_normalizado,ano_letivo) VALUES(71,'1º A legado','1º a legado',2026);
            INSERT INTO alunos(id,nome_completo,nome_normalizado,data_nascimento,id_turma) VALUES(81,'Aluno legado','aluno legado','2010-01-01',71);
            INSERT INTO dvas(id,id_aluno,data_vencimento,observacao) VALUES(91,81,'2027-01-01','Documento histórico')");
        $before = [];
        foreach (['alunos', 'turmas', 'dvas', 'alunos_passivo', 'alunos_movimentacoes', 'request_receipts', 'security_audit'] as $table) {
            $before[$table] = $old->query('SELECT * FROM ' . $table)->fetchAll();
        }
        DatabaseInitializer::initialize($old, true);
        DatabaseInitializer::initialize($old, true);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $old->query('SELECT * FROM ' . $table)->fetchAll());
        }
        $sql = "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name LIKE '%vinculos%' ORDER BY name";
        $this->assertSame($this->pdo->query($sql)->fetchAll(), $old->query($sql)->fetchAll());
        $this->assertSame(1, (int) $old->query('SELECT COUNT(*) FROM schema_migrations WHERE version=22')->fetchColumn());
        $this->assertSame(22, (int) $old->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame([], $old->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testFalhaDeRegistroDesfazTabelaIndiceETriggers(): void
    {
        $old = $this->versionTwentyOne();
        $old->exec("CREATE TRIGGER fail_school_migration BEFORE INSERT ON schema_migrations
            WHEN NEW.version=22 BEGIN SELECT RAISE(ABORT,'forced22'); END");
        try {
            (new \ReflectionMethod(DatabaseInitializer::class, 'runMigration'))->invoke(null, $old, 22, file_get_contents(ROOT_PATH . '/database/schema.sql'));
            $this->fail('A migração precisa falhar de forma atômica.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('forced22', $e->getMessage());
            $this->assertSame(21, (int) $old->query('PRAGMA user_version')->fetchColumn());
            $this->assertSame(0, (int) $old->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%vinculos%'")->fetchColumn());
        }
    }
}
