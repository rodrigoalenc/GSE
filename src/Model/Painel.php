<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Painel extends Model
{
    public function getTotalAlunos()
    {
        try {
            return self::$pdo->query("SELECT COUNT(id) FROM alunos")->fetchColumn();
        } catch (Exception $e) {
            error_log("Erro no Painel (getTotalAlunos): " . $e->getMessage());
            return 0;
        }
    }

    public function getTotalSemDva()
    {
        try {
            return self::$pdo->query("SELECT COUNT(id) FROM alunos WHERE id NOT IN (SELECT id_aluno FROM dvas)")->fetchColumn();
        } catch (Exception $e) {
            error_log("Erro no Painel (getTotalSemDva): " . $e->getMessage());
            return 0;
        }
    }

    public function getDvasVencidas()
    {
        try {
            $hoje = date('Y-m-d');
            $sql = "SELECT a.nome_completo, t.nome_turma, d.data_vencimento, d.id AS dva_id, a.id AS aluno_id 
                    FROM dvas d 
                    JOIN alunos a ON d.id_aluno = a.id 
                    LEFT JOIN turmas t ON a.id_turma = t.id 
                    WHERE d.data_vencimento < ? ORDER BY d.data_vencimento ASC";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$hoje]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Painel (getDvasVencidas): " . $e->getMessage());
            return [];
        }
    }

    public function getDvasAVencer()
    {
        try {
            $hoje = date('Y-m-d');
            $sql = "SELECT a.nome_completo, t.nome_turma, d.data_vencimento, d.id AS dva_id, a.id AS aluno_id 
                    FROM dvas d 
                    JOIN alunos a ON d.id_aluno = a.id 
                    LEFT JOIN turmas t ON a.id_turma = t.id 
                    WHERE d.data_vencimento >= ? AND d.data_vencimento <= date('now', '+30 days') 
                    ORDER BY d.data_vencimento ASC";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$hoje]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Painel (getDvasAVencer): " . $e->getMessage());
            return [];
        }
    }

    public function getDvasVigentes()
    {
        try {
            $sql = "SELECT a.nome_completo, t.nome_turma, d.data_vencimento, d.id AS dva_id, a.id AS aluno_id 
                    FROM dvas d 
                    JOIN alunos a ON d.id_aluno = a.id 
                    LEFT JOIN turmas t ON a.id_turma = t.id 
                    WHERE d.data_vencimento > date('now', '+30 days') 
                    ORDER BY a.nome_completo";
            return self::$pdo->query($sql)->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Painel (getDvasVigentes): " . $e->getMessage());
            return [];
        }
    }

    public function getListaAlunosSemDva()
    {
        try {
            $sql = "SELECT a.id, a.nome_completo, t.nome_turma 
                FROM alunos a
                LEFT JOIN turmas t ON a.id_turma = t.id
                WHERE a.id NOT IN (SELECT id_aluno FROM dvas)
                ORDER BY a.nome_completo ASC";

            return self::$pdo->query($sql)->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Painel (getListaAlunosSemDva): " . $e->getMessage());
            return [];
        }
    }
}
