<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';

final class Painel extends Model
{
    /**
     * @param array<string,string> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function buscarAlunos(array $filters, int $page = 1, int $perPage = 15, ?DvaStatus $statusService = null, bool $includeHealth = true): array
    {
        $result = (new Aluno())->paginate($filters, $page, max(10, min(50, $perPage)), $statusService, $includeHealth, false);
        $fields = array_fill_keys(['id', 'nome_completo', 'data_nascimento', 'ativo', 'nome_turma', 'data_vencimento', 'no_passivo', 'dva_status'], true);
        $result['items'] = array_map(static fn (array $item): array => array_intersect_key($item, $fields), $result['items']);
        return $result;
    }

    /** @return array<string,int> */
    public function resumo(?DvaStatus $statusService = null): array
    {
        $statusService ??= new DvaStatus();
        $classification = $statusService->sqlExpression();
        $statement = self::$pdo->prepare(
            'SELECT
                SUM(CASE WHEN a.ativo = 1 THEN 1 ELSE 0 END) AS alunos_ativos,
                SUM(CASE WHEN a.ativo = 0 THEN 1 ELSE 0 END) AS alunos_inativos,
                SUM(CASE WHEN a.ativo = 1 AND a.dva_status = \'sem_dva\' THEN 1 ELSE 0 END) AS sem_dva,
                SUM(CASE WHEN a.ativo = 1 AND a.dva_status = \'vencida\' THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN a.ativo = 1 AND a.dva_status = \'vence_hoje\' THEN 1 ELSE 0 END) AS vence_hoje,
                SUM(CASE WHEN a.ativo = 1 AND a.dva_status = \'a_vencer\' THEN 1 ELSE 0 END) AS a_vencer,
                SUM(CASE WHEN a.ativo = 1 AND a.dva_status = \'vigente\' THEN 1 ELSE 0 END) AS vigentes
             FROM (SELECT a.ativo, ' . $classification['sql'] . ' AS dva_status
                   FROM alunos a LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1) a'
        );
        $statement->execute($classification['params']);
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

    /** @return list<array<string,mixed>> */
    public function pendenciasPrioritarias(int $limit = 8, ?DvaStatus $statusService = null): array
    {
        $statusService ??= new DvaStatus();
        $classification = $statusService->sqlExpression();
        $statement = self::$pdo->prepare(
            'SELECT * FROM (
                SELECT a.id, a.nome_completo, t.nome_turma, d.data_vencimento,
                       ' . $classification['sql'] . ' AS dva_status
                FROM alunos a
                LEFT JOIN turmas t ON t.id = a.id_turma
                LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1
                WHERE a.ativo = 1
             ) WHERE dva_status <> \'vigente\'
             ORDER BY CASE
                WHEN dva_status = \'sem_dva\' THEN 0
                WHEN dva_status = \'vencida\' THEN 1
                WHEN dva_status = \'vence_hoje\' THEN 2
                ELSE 3 END,
                data_vencimento, nome_completo COLLATE NOCASE
             LIMIT :limit'
        );
        foreach ($classification['params'] as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function alunosPorStatus(?DvaStatus $statusService = null, int $limitPerStatus = 10): array
    {
        $statusService ??= new DvaStatus();
        $grupos = array_fill_keys(DvaStatus::ALL, []);
        $classification = $statusService->sqlExpression();
        $statement = self::$pdo->prepare(
            'WITH classified AS (
                SELECT a.id, a.nome_completo, a.nome_normalizado, t.nome_turma, d.data_vencimento,
                       ' . $classification['sql'] . ' AS dva_status
                FROM alunos a
                LEFT JOIN turmas t ON t.id = a.id_turma
                LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1
                WHERE a.ativo = 1
             ), ranked AS (
                SELECT *, ROW_NUMBER() OVER (PARTITION BY dva_status ORDER BY nome_normalizado, id) AS position
                FROM classified
             ) SELECT id, nome_completo, nome_turma, data_vencimento, dva_status
               FROM ranked WHERE position <= :limit ORDER BY nome_normalizado, id'
        );
        foreach ($classification['params'] as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(50, $limitPerStatus)), PDO::PARAM_INT);
        $statement->execute();

        foreach ($statement->fetchAll() as $item) {
            $grupos[$item['dva_status']][] = $item;
        }

        return $grupos;
    }
}
