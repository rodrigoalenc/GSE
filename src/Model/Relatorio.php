<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Relatorio extends Model
{
    private function assegurarConexao()
    {
        if (!self::$pdo) {
            parent::__construct();
        }
    }

    public function getTurmas()
    {
        try {
            $this->assegurarConexao();
            return self::$pdo->query("SELECT * FROM turmas ORDER BY nome_turma ASC")->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Model Relatorio (getTurmas): " . $e->getMessage());
            return [];
        }
    }

    public function buscarDados($id_turma, $status)
    {
        try {
            $this->assegurarConexao();

            $sql = "SELECT a.nome_completo, a.data_nascimento, t.nome_turma, d.data_vencimento 
                    FROM alunos a 
                    LEFT JOIN turmas t ON a.id_turma = t.id
                    LEFT JOIN dvas d ON a.id = d.id_aluno 
                    WHERE 1=1";

            $params = [];

            if (!empty($id_turma)) {
                $sql .= " AND t.id = ?";
                $params[] = $id_turma;
            }

            if ($status == 'sem_dva') {
                $sql .= " AND d.id IS NULL";
            } elseif ($status == 'vencida') {
                $sql .= " AND d.data_vencimento < date('now') AND d.id IS NOT NULL";
            } elseif ($status == 'avencer') {
                $sql .= " AND d.data_vencimento >= date('now') 
                          AND d.data_vencimento <= date('now', '+30 days')";
            } elseif ($status == 'vigente') {
                $sql .= " AND d.data_vencimento > date('now', '+30 days')";
            }

            $sql .= " ORDER BY t.nome_turma ASC, a.nome_completo ASC";

            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Model Relatorio (buscarDados): " . $e->getMessage());
            return [];
        }
    }
}
