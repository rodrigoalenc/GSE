<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';

use src\Core\SqliteTransaction;

final class Turma extends Model
{
    private ?string $lastErrorCode = null;

    /** @return list<array<string,mixed>> */
    public function listar(string $search = '', ?bool $active = null): array
    {
        $conditions = [];
        $params = [];
        $search = self::normalizeName($search);

        if ($search !== '') {
            $conditions[] = "t.nome_turma LIKE :search ESCAPE '\\'";
            $params['search'] = '%' . self::escapeLike($search) . '%';
        }

        if ($active !== null) {
            $conditions[] = 't.ativo = :active';
            $params['active'] = $active ? 1 : 0;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $statement = self::$pdo->prepare(
            "SELECT t.id, t.nome_turma, t.ano_letivo, t.ativo, t.criado_em, t.atualizado_em,
                    SUM(CASE WHEN a.ativo = 1 THEN 1 ELSE 0 END) AS alunos_ativos
             FROM turmas t
             LEFT JOIN alunos a ON a.id_turma = t.id
             {$where}
             GROUP BY t.id
             ORDER BY t.ativo DESC, t.ano_letivo DESC, t.nome_turma COLLATE NOCASE"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function ativas(): array
    {
        return $this->listar('', true);
    }

    /** @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        if ($id < 1) {
            return false;
        }

        $statement = self::$pdo->prepare(
            'SELECT id, nome_turma, ano_letivo, ativo, criado_em, atualizado_em FROM turmas WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch();
    }

    public function cadastrar(string $name, int $schoolYear): int|false
    {
        $this->lastErrorCode = null;
        $name = self::normalizeName($name);

        if (!self::validData($name, $schoolYear)) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            $now = gmdate('Y-m-d H:i:s');
            $statement = self::$pdo->prepare(
                'INSERT INTO turmas (nome_turma, ano_letivo, ativo, criado_em, atualizado_em)
                 VALUES (:name, :year, 1, :now, :now)'
            );
            $statement->execute(['name' => $name, 'year' => $schoolYear, 'now' => $now]);

            return (int) self::$pdo->lastInsertId();
        } catch (PDOException $exception) {
            $this->lastErrorCode = str_contains(strtolower($exception->getMessage()), 'unique')
                ? 'duplicate_class'
                : 'database_error';
            TechnicalLogger::error('class_create_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function atualizar(int $id, string $name, int $schoolYear): bool
    {
        $this->lastErrorCode = null;
        $name = self::normalizeName($name);

        if ($id < 1 || !self::validData($name, $schoolYear)) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            $statement = self::$pdo->prepare(
                'UPDATE turmas SET nome_turma = :name, ano_letivo = :year, atualizado_em = :now WHERE id = :id'
            );
            $statement->execute([
                'name' => $name,
                'year' => $schoolYear,
                'now' => gmdate('Y-m-d H:i:s'),
                'id' => $id,
            ]);

            if ($statement->rowCount() === 0 && !$this->buscarPorId($id)) {
                $this->lastErrorCode = 'not_found';

                return false;
            }

            return true;
        } catch (PDOException $exception) {
            $this->lastErrorCode = str_contains(strtolower($exception->getMessage()), 'unique')
                ? 'duplicate_class'
                : 'database_error';
            TechnicalLogger::error('class_update_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function definirAtiva(int $id, bool $active): bool
    {
        $this->lastErrorCode = null;

        if ($id < 1) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $active): bool {
                $class = $this->buscarPorId($id);

                if (!$class) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if (!$active) {
                    $students = $pdo->prepare('SELECT COUNT(*) FROM alunos WHERE id_turma = :id AND ativo = 1');
                    $students->execute(['id' => $id]);

                    if ((int) $students->fetchColumn() > 0) {
                        $this->lastErrorCode = 'active_students';

                        return false;
                    }
                }

                $statement = $pdo->prepare(
                    'UPDATE turmas SET ativo = :active, atualizado_em = :now WHERE id = :id AND ativo <> :active'
                );
                $statement->execute([
                    'active' => $active ? 1 : 0,
                    'now' => gmdate('Y-m-d H:i:s'),
                    'id' => $id,
                ]);

                return true;
            });
        } catch (PDOException $exception) {
            $this->lastErrorCode = str_contains(strtolower($exception->getMessage()), 'class_has_active_students')
                ? 'active_students'
                : 'database_error';
            TechnicalLogger::error('class_status_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public static function normalizeName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    }

    private static function validData(string $name, int $schoolYear): bool
    {
        $currentYear = (int) date('Y');

        return $name !== ''
            && mb_strlen($name, 'UTF-8') <= 100
            && preg_match('/[\x00-\x1f\x7f]/u', $name) !== 1
            && $schoolYear >= 2000
            && $schoolYear <= $currentYear + 5;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
