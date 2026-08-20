<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';

final class Painel extends Model
{
    public function resumo(?DvaStatus $statusService = null): array
    {
        $statusService ??= new DvaStatus();
        $statement = self::$pdo->prepare(
            'SELECT
                SUM(CASE WHEN a.ativo = 1 THEN 1 ELSE 0 END) AS alunos_ativos,
                SUM(CASE WHEN a.ativo = 0 THEN 1 ELSE 0 END) AS alunos_inativos,
                SUM(CASE WHEN a.ativo = 1 AND d.id IS NULL THEN 1 ELSE 0 END) AS sem_dva,
                SUM(CASE WHEN a.ativo = 1 AND d.data_vencimento < :today THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN a.ativo = 1 AND d.data_vencimento = :today THEN 1 ELSE 0 END) AS vence_hoje,
                SUM(CASE WHEN a.ativo = 1 AND d.data_vencimento > :today
                          AND d.data_vencimento <= :warning_limit THEN 1 ELSE 0 END) AS a_vencer,
                SUM(CASE WHEN a.ativo = 1 AND d.data_vencimento > :warning_limit THEN 1 ELSE 0 END) AS vigentes
             FROM alunos a
             LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1'
        );
        $statement->execute([
            'today' => $statusService->today(),
            'warning_limit' => $statusService->warningLimit(),
        ]);
        $row = $statement->fetch() ?: [];

        return [
            'alunos_ativos' => (int) ($row['alunos_ativos'] ?? 0),
            'alunos_inativos' => (int) ($row['alunos_inativos'] ?? 0),
            'sem_dva' => (int) ($row['sem_dva'] ?? 0),
            'vencidas' => (int) ($row['vencidas'] ?? 0),
            'vence_hoje' => (int) ($row['vence_hoje'] ?? 0),
            'a_vencer' => (int) ($row['a_vencer'] ?? 0),
            'vigentes' => (int) ($row['vigentes'] ?? 0),
        ];
    }

    public function pendenciasPrioritarias(int $limit = 8, ?DvaStatus $statusService = null): array
    {
        $statusService ??= new DvaStatus();
        $statement = self::$pdo->prepare(
            'SELECT a.id, a.nome_completo, t.nome_turma, d.data_vencimento
             FROM alunos a
             LEFT JOIN turmas t ON t.id = a.id_turma
             LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1
             WHERE a.ativo = 1 AND (d.id IS NULL OR d.data_vencimento <= :warning_limit)
             ORDER BY CASE
                WHEN d.id IS NULL THEN 0
                WHEN d.data_vencimento < :today THEN 1
                WHEN d.data_vencimento = :today THEN 2
                ELSE 3 END,
                d.data_vencimento, a.nome_completo COLLATE NOCASE
             LIMIT :limit'
        );
        $statement->bindValue(':warning_limit', $statusService->warningLimit());
        $statement->bindValue(':today', $statusService->today());
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();

        foreach ($items as &$item) {
            $item['dva_status'] = $statusService->classify($item['data_vencimento'] ?: null);
        }
        unset($item);

        return $items;
    }
}
