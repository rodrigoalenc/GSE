<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Aluno extends Model
{
    public function contarTotal($termo = '')
    {
        if ($termo) {
            $sql = "SELECT COUNT(id) FROM alunos WHERE nome_completo LIKE ?";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute(["%$termo%"]);
            return $stmt->fetchColumn();
        } else {
            return self::$pdo->query("SELECT COUNT(id) FROM alunos")->fetchColumn();
        }
    }

    public function listar($limite, $offset, $termo = '')
    {
        if ($termo) {
            $sql = "SELECT a.*, t.nome_turma 
                    FROM alunos a 
                    LEFT JOIN turmas t ON a.id_turma = t.id 
                    WHERE a.nome_completo LIKE ? 
                    ORDER BY a.nome_completo ASC 
                    LIMIT ? OFFSET ?";
            $stmt = self::$pdo->prepare($sql);
            $stmt->bindValue(1, "%$termo%");
            $stmt->bindValue(2, $limite, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = "SELECT a.*, t.nome_turma 
                    FROM alunos a 
                    LEFT JOIN turmas t ON a.id_turma = t.id 
                    ORDER BY a.nome_completo ASC 
                    LIMIT ? OFFSET ?";
            $stmt = self::$pdo->prepare($sql);
            $stmt->bindValue(1, $limite, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    public function existeAluno($nome, $dataNasc)
    {
        $sql = "SELECT id FROM alunos WHERE nome_completo = :nome AND data_nascimento = :data";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindValue(':nome', trim($nome));
        $stmt->bindValue(':data', $dataNasc);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function cadastrar($nome, $dataNasc, $idTurma, $dataDva, $tel_aluno, $tel_responsavel)
    {
        try {
            $idUsuarioLogado = $_SESSION['usuario_id'] ?? null;
            self::$pdo->beginTransaction();

            $sql = "INSERT INTO alunos (nome_completo, data_nascimento, id_turma, telefone_aluno, telefone_responsavel) VALUES (?, ?, ?, ?, ?)";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$nome, $dataNasc, $idTurma, $tel_aluno, $tel_responsavel ?: null]);
            $idAluno = self::$pdo->lastInsertId();

            if (!empty($dataDva)) {
                $sqlDva = "INSERT INTO dvas (id_aluno, id_usuario_registro, data_vencimento) VALUES (?, ?, ?)";
                $stmtDva = self::$pdo->prepare($sqlDva);
                $stmtDva->execute([$idAluno, $idUsuarioLogado, $dataDva]);
            }

            self::$pdo->commit();
            return $idAluno;
        } catch (Exception $e) {
            self::$pdo->rollBack();
            error_log("Erro no Model Aluno (cadastrar): " . $e->getMessage());
            return false;
        }
    }

    public function buscarPorId($id)
    {
        $sql = "SELECT a.*, d.data_vencimento as data_dva, d.observacao as obs_dva, t.nome_turma
                FROM alunos a 
                LEFT JOIN dvas d ON a.id = d.id_aluno 
                LEFT JOIN turmas t ON a.id_turma = t.id
                WHERE a.id = ?";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function atualizar($id, $nome, $dataNasc, $idTurma, $dataDva, $tel_aluno, $tel_responsavel)
    {
        try {
            $idUsuarioLogado = $_SESSION['usuario_id'] ?? null;
            self::$pdo->beginTransaction();

            $sqlAluno = "UPDATE alunos SET nome_completo = ?, data_nascimento = ?, id_turma = ?, telefone_aluno = ?, telefone_responsavel = ? WHERE id = ?";
            $stmt = self::$pdo->prepare($sqlAluno);
            $stmt->execute([$nome, $dataNasc, $idTurma ?: null, $tel_aluno, $tel_responsavel ?: null, $id]);

            $stmtCheck = self::$pdo->prepare("SELECT id FROM dvas WHERE id_aluno = ?");
            $stmtCheck->execute([$id]);
            $temDva = $stmtCheck->fetch();

            if ($dataDva) {
                if ($temDva) {
                    $sqlDva = "UPDATE dvas SET data_vencimento = ?, id_usuario_registro = ? WHERE id_aluno = ?";
                    self::$pdo->prepare($sqlDva)->execute([$dataDva, $idUsuarioLogado, $id]);
                } else {
                    $sqlDva = "INSERT INTO dvas (id_aluno, id_usuario_registro, data_vencimento) VALUES (?, ?, ?)";
                    self::$pdo->prepare($sqlDva)->execute([$id, $idUsuarioLogado, $dataDva]);
                }
            }

            self::$pdo->commit();
            return true;
        } catch (Exception $e) {
            self::$pdo->rollBack();
            error_log("Erro no Model Aluno (atualizar ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    public function excluir($id)
    {
        try {
            $stmtNome = self::$pdo->prepare("SELECT nome_completo FROM alunos WHERE id = ?");
            $stmtNome->execute([$id]);
            $nome = $stmtNome->fetchColumn();

            if ($nome) {

                $stmtDel = self::$pdo->prepare("DELETE FROM alunos WHERE id = ?");
                $stmtDel->execute([$id]);
                return $nome;
            }
            return false;
        } catch (Exception $e) {
            error_log("Erro no Model Aluno (excluir ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    public function listarSemDva()
    {
        $sql = "SELECT a.id, a.nome_completo, t.nome_turma
                FROM alunos a 
                LEFT JOIN turmas t ON a.id_turma = t.id 
                WHERE a.id NOT IN (SELECT id_aluno FROM dvas)
                ORDER BY a.nome_completo ASC";
        return self::$pdo->query($sql)->fetchAll();
    }

    public function getAniversariantesDoMes($mes)
    {
        $sql = "SELECT a.id, a.nome_completo, a.data_nascimento, t.nome_turma 
                FROM alunos a 
                LEFT JOIN turmas t ON a.id_turma = t.id
                WHERE strftime('%m', a.data_nascimento) = ? 
                ORDER BY strftime('%d', a.data_nascimento) ASC";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$mes]);
        return $stmt->fetchAll();
    }

    public function getAniversariantesHoje($dia, $mes)
    {
        $sql = "SELECT a.nome_completo, a.data_nascimento, t.nome_turma 
                FROM alunos a 
                LEFT JOIN turmas t ON a.id_turma = t.id
                WHERE strftime('%d', a.data_nascimento) = ? 
                AND strftime('%m', a.data_nascimento) = ?";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$dia, $mes]);
        return $stmt->fetchAll();
    }
}
