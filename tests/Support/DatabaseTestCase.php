<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    private string $databaseFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        \Model::setConexao(null);

        if ($this->databaseFile !== '' && is_file($this->databaseFile)) {
            @unlink($this->databaseFile);
        }

        foreach ([$this->databaseFile . '-wal', $this->databaseFile . '-shm'] as $sqliteSidecar) {
            if ($sqliteSidecar !== '' && is_file($sqliteSidecar)) {
                @unlink($sqliteSidecar);
            }
        }

        parent::tearDown();
    }

    protected function insertTurma(string $nome = '1 Ano A'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO turmas (nome_turma) VALUES (?)');
        $stmt->execute([$nome]);

        return (int) $this->pdo->lastInsertId();
    }

    protected function insertUsuario(string $nome = 'Usuario Teste'): int
    {
        $email = strtolower(str_replace(' ', '.', $nome)) . '@teste.local';
        $stmt = $this->pdo->prepare('INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nome, $email, password_hash('123456', PASSWORD_DEFAULT), 'admin']);

        return (int) $this->pdo->lastInsertId();
    }

    protected function insertAlunoComDva(string $nome, string $vencimento, ?int $idTurma = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO alunos (nome_completo, data_nascimento, id_turma) VALUES (?, ?, ?)');
        $stmt->execute([$nome, '2010-05-10', $idTurma]);
        $idAluno = (int) $this->pdo->lastInsertId();

        $stmtDva = $this->pdo->prepare('INSERT INTO dvas (id_aluno, data_vencimento) VALUES (?, ?)');
        $stmtDva->execute([$idAluno, $vencimento]);

        return $idAluno;
    }

    private function createTestDatabase(): PDO
    {
        $this->databaseFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcc_phpunit_' . uniqid('', true) . '.sqlite';
        $_ENV['DB_PATH'] = $this->databaseFile;
        putenv("DB_PATH={$this->databaseFile}");

        \Model::setConexao(null);

        $pdo = \Model::getConexao();
        $pdo->exec((string) file_get_contents(ROOT_PATH . '/database/schema.sql'));

        return $pdo;
    }
}
