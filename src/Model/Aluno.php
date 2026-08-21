<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/TextNormalizer.php';
require_once ROOT_PATH . '/src/Model/Dva.php';

use src\Core\SqliteTransaction;
use src\Core\TextNormalizer;

final class Aluno extends Model
{
    public const NAME_MAX_LENGTH = 150;

    private ?string $lastErrorCode = null;

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 20, ?DvaStatus $statusService = null): array
    {
        $statusService ??= new DvaStatus();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        [$where, $params] = $this->where($filters, $statusService);
        $joins = 'LEFT JOIN turmas t ON t.id = a.id_turma
                  LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1';
        $count = self::$pdo->prepare("SELECT COUNT(*) FROM alunos a {$joins} {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $statement = self::$pdo->prepare(
            "SELECT a.id, a.nome_completo, a.data_nascimento, a.id_turma,
                    a.telefone_aluno, a.telefone_responsavel, a.ativo,
                    a.criado_em, a.atualizado_em, a.inativado_em, a.inativado_por,
                    t.nome_turma, t.ano_letivo, t.ativo AS turma_ativa,
                    d.id AS dva_id, d.data_vencimento, d.observacao AS dva_observacao
             FROM alunos a {$joins} {$where}
             ORDER BY a.nome_normalizado, a.id
             LIMIT :limit OFFSET :offset"
        );
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();

        foreach ($items as &$item) {
            $item['dva_status'] = $statusService->classify($item['data_vencimento'] ?: null);
            $item['dva_dias_restantes'] = $statusService->daysRemaining($item['data_vencimento'] ?: null);
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $filters */
    public function contar(array $filters = [], ?DvaStatus $statusService = null): int
    {
        $statusService ??= new DvaStatus();
        [$where, $params] = $this->where($filters, $statusService);
        $statement = self::$pdo->prepare(
            "SELECT COUNT(*) FROM alunos a
             LEFT JOIN turmas t ON t.id = a.id_turma
             LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1 {$where}"
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        if ($id < 1) {
            return false;
        }

        $statement = self::$pdo->prepare(
            'SELECT a.id, a.nome_completo, a.nome_normalizado, a.data_nascimento, a.id_turma,
                    a.telefone_aluno, a.telefone_responsavel, a.ativo,
                    a.criado_em, a.atualizado_em, a.inativado_em, a.inativado_por,
                    t.nome_turma, t.ano_letivo, t.ativo AS turma_ativa,
                    d.id AS dva_id, d.data_vencimento, d.observacao AS dva_observacao,
                    d.id_usuario_registro AS dva_usuario_id, d.criado_em AS dva_criado_em
             FROM alunos a
             LEFT JOIN turmas t ON t.id = a.id_turma
             LEFT JOIN dvas d ON d.id_aluno = a.id AND d.ativo = 1
             WHERE a.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $initialDva
     */
    public function cadastrar(array $data, int $actorId, ?array $initialDva = null, bool $confirmDuplicate = false): int|false
    {
        $this->lastErrorCode = null;
        $normalized = $this->validateAndNormalize($data);

        if ($normalized === false || $actorId < 1) {
            $this->lastErrorCode ??= 'invalid_data';

            return false;
        }

        $dva = null;

        if ($initialDva !== null && trim((string) ($initialDva['data_vencimento'] ?? '')) !== '') {
            $dva = Dva::validateData(
                (string) ($initialDva['data_vencimento'] ?? ''),
                isset($initialDva['observacao']) ? (string) $initialDva['observacao'] : null
            );

            if ($dva === false) {
                $this->lastErrorCode = 'invalid_dva';

                return false;
            }
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($normalized, $actorId, $dva, $confirmDuplicate): int|false {
                $actor = $pdo->prepare('SELECT 1 FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1');
                $actor->execute(['id' => $actorId]);

                if ($actor->fetchColumn() === false) {
                    $this->lastErrorCode = 'invalid_actor';

                    return false;
                }

                if (!$this->activeClassExists((int) $normalized['id_turma'], $pdo)) {
                    $this->lastErrorCode = 'invalid_class';

                    return false;
                }

                if (!$confirmDuplicate && $this->duplicateExists(
                    $pdo,
                    $normalized['nome_completo'],
                    $normalized['data_nascimento']
                )) {
                    $this->lastErrorCode = 'possible_duplicate';

                    return false;
                }

                $now = gmdate('Y-m-d H:i:s');
                $statement = $pdo->prepare(
                    'INSERT INTO alunos
                        (nome_completo, nome_normalizado, data_nascimento, id_turma, telefone_aluno, telefone_responsavel,
                         ativo, criado_em, atualizado_em)
                     VALUES (:name, :normalized_name, :birth, :class, :student_phone, :guardian_phone, 1, :now, :now)'
                );
                $statement->execute([
                    'name' => $normalized['nome_completo'],
                    'normalized_name' => $normalized['nome_normalizado'],
                    'birth' => $normalized['data_nascimento'],
                    'class' => $normalized['id_turma'],
                    'student_phone' => $normalized['telefone_aluno'],
                    'guardian_phone' => $normalized['telefone_responsavel'],
                    'now' => $now,
                ]);
                $studentId = (int) $pdo->lastInsertId();

                if ($dva !== null) {
                    $dvaStatement = $pdo->prepare(
                        'INSERT INTO dvas
                            (id_aluno, id_usuario_registro, data_vencimento, observacao, ativo, criado_em, atualizado_em)
                         VALUES (:student, :actor, :expiration, :observation, 1, :now, :now)'
                    );
                    $dvaStatement->execute([
                        'student' => $studentId,
                        'actor' => $actorId,
                        'expiration' => $dva['expiration_date'],
                        'observation' => $dva['observation'],
                        'now' => $now,
                    ]);
                }

                return $studentId;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('student_create_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /** @param array<string,mixed> $data */
    public function atualizar(int $id, array $data, bool $confirmDuplicate = false): bool
    {
        $this->lastErrorCode = null;
        $normalized = $this->validateAndNormalize($data);

        if ($id < 1 || $normalized === false) {
            $this->lastErrorCode ??= 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $normalized, $confirmDuplicate): bool {
                $current = $this->buscarPorId($id);

                if (!$current) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if (!$this->activeClassExists((int) $normalized['id_turma'], $pdo)
                    && (int) $current['id_turma'] !== (int) $normalized['id_turma']) {
                    $this->lastErrorCode = 'invalid_class';

                    return false;
                }

                if (!$confirmDuplicate && $this->duplicateExists(
                    $pdo,
                    $normalized['nome_completo'],
                    $normalized['data_nascimento'],
                    $id
                )) {
                    $this->lastErrorCode = 'possible_duplicate';

                    return false;
                }

                $statement = $pdo->prepare(
                    'UPDATE alunos SET nome_completo = :name, nome_normalizado = :normalized_name,
                        data_nascimento = :birth,
                        id_turma = :class, telefone_aluno = :student_phone,
                        telefone_responsavel = :guardian_phone, atualizado_em = :now
                     WHERE id = :id'
                );
                $statement->execute([
                    'name' => $normalized['nome_completo'],
                    'normalized_name' => $normalized['nome_normalizado'],
                    'birth' => $normalized['data_nascimento'],
                    'class' => $normalized['id_turma'],
                    'student_phone' => $normalized['telefone_aluno'],
                    'guardian_phone' => $normalized['telefone_responsavel'],
                    'now' => gmdate('Y-m-d H:i:s'),
                    'id' => $id,
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('student_update_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function definirAtivo(int $id, bool $active, int $actorId): bool
    {
        $this->lastErrorCode = null;

        if ($id < 1 || $actorId < 1) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $active, $actorId): bool {
                $student = $this->buscarPorId($id);

                if (!$student) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if ($active && !$this->activeClassExists((int) $student['id_turma'], $pdo)) {
                    $this->lastErrorCode = 'invalid_class';

                    return false;
                }

                $statement = $pdo->prepare(
                    'UPDATE alunos SET ativo = :active, atualizado_em = :now,
                        inativado_em = :deactivated_at, inativado_por = :deactivated_by
                     WHERE id = :id AND ativo <> :active'
                );
                $now = gmdate('Y-m-d H:i:s');
                $statement->execute([
                    'active' => $active ? 1 : 0,
                    'now' => $now,
                    'deactivated_at' => $active ? null : $now,
                    'deactivated_by' => $active ? null : $actorId,
                    'id' => $id,
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('student_status_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function possivelDuplicidade(string $name, string $birthDate, ?int $ignoreId = null): bool
    {
        return $this->duplicateExists(self::$pdo, $name, $birthDate, $ignoreId);
    }

    private function duplicateExists(PDO $pdo, string $name, string $birthDate, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM alunos
                WHERE nome_normalizado = :name AND data_nascimento = :birth';
        $params = ['name' => TextNormalizer::comparisonKey($name), 'birth' => trim($birthDate)];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore';
            $params['ignore'] = $ignoreId;
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function aniversariantesDoDia(?DateTimeImmutable $today = null, int $limit = 10): array
    {
        $today ??= new DateTimeImmutable((new DvaStatus())->today());
        $statement = self::$pdo->prepare(
            "SELECT a.id, a.nome_completo, a.data_nascimento, t.nome_turma
             FROM alunos a LEFT JOIN turmas t ON t.id = a.id_turma
             WHERE a.ativo = 1 AND strftime('%m-%d', a.data_nascimento) = :month_day
             ORDER BY a.nome_normalizado LIMIT :limit"
        );
        $statement->bindValue(':month_day', $today->format('m-d'));
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function aniversariantesDoMes(?DateTimeImmutable $today = null, int $limit = 10): array
    {
        $today ??= new DateTimeImmutable((new DvaStatus())->today());
        $statement = self::$pdo->prepare(
            "SELECT a.id, a.nome_completo, a.data_nascimento, t.nome_turma
             FROM alunos a LEFT JOIN turmas t ON t.id = a.id_turma
             WHERE a.ativo = 1 AND strftime('%m', a.data_nascimento) = :month
               AND strftime('%d', a.data_nascimento) >= :day
             ORDER BY strftime('%d', a.data_nascimento), a.nome_normalizado
             LIMIT :limit"
        );
        $statement->bindValue(':month', $today->format('m'));
        $statement->bindValue(':day', $today->format('d'));
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|false */
    public function perfil(int $id, ?DvaStatus $statusService = null): array|false
    {
        $student = $this->buscarPorId($id);

        if (!$student) {
            return false;
        }

        $statusService ??= new DvaStatus();
        $student['dva_status'] = $statusService->classify($student['data_vencimento'] ?: null);
        $student['dva_dias_restantes'] = $statusService->daysRemaining($student['data_vencimento'] ?: null);

        return ['student' => $student, 'dva_history' => (new Dva())->historicoDoAluno($id)];
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public static function normalizeName(string $name): string
    {
        return TextNormalizer::displayName($name);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|false
     */
    private function validateAndNormalize(array $data): array|false
    {
        try {
            $name = TextNormalizer::displayName((string) ($data['nome_completo'] ?? ''));
            $comparisonName = TextNormalizer::comparisonKey($name);
        } catch (RuntimeException) {
            $this->lastErrorCode = 'invalid_name';

            return false;
        }

        if ($name === ''
            || mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH
            || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1) {
            $this->lastErrorCode = 'invalid_name';

            return false;
        }

        $birth = trim((string) ($data['data_nascimento'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth, new DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $birth) {
            $this->lastErrorCode = 'invalid_birth_date';

            return false;
        }

        if ($birth > (new DvaStatus())->today()) {
            $this->lastErrorCode = 'future_birth_date';

            return false;
        }

        $classId = filter_var($data['id_turma'] ?? null, FILTER_VALIDATE_INT);

        if ($classId === false || (int) $classId < 1) {
            $this->lastErrorCode = 'invalid_class';

            return false;
        }

        $studentPhone = self::normalizePhone((string) ($data['telefone_aluno'] ?? ''));
        $guardianPhone = self::normalizePhone((string) ($data['telefone_responsavel'] ?? ''));

        if ($studentPhone === false || $guardianPhone === false) {
            $this->lastErrorCode = 'invalid_phone';

            return false;
        }

        return [
            'nome_completo' => $name,
            'nome_normalizado' => $comparisonName,
            'data_nascimento' => $birth,
            'id_turma' => (int) $classId,
            'telefone_aluno' => $studentPhone,
            'telefone_responsavel' => $guardianPhone,
        ];
    }

    private static function normalizePhone(string $phone): string|false
    {
        $digits = preg_replace('/\D+/u', '', trim($phone)) ?? '';

        if ($digits === '') {
            return '';
        }

        return in_array(strlen($digits), [10, 11], true) ? $digits : false;
    }

    private function activeClassExists(int $classId, ?PDO $pdo = null): bool
    {
        $statement = ($pdo ?? self::$pdo)->prepare('SELECT 1 FROM turmas WHERE id = :id AND ativo = 1 LIMIT 1');
        $statement->execute(['id' => $classId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,int|string>}
     */
    private function where(array $filters, DvaStatus $statusService): array
    {
        $conditions = [];
        $params = [];
        try {
            $search = mb_substr(
                TextNormalizer::comparisonKey((string) ($filters['q'] ?? '')),
                0,
                100,
                'UTF-8'
            );
        } catch (RuntimeException) {
            $search = '';
        }

        if ($search !== '') {
            $conditions[] = "a.nome_normalizado LIKE :search ESCAPE '\\'";
            $params['search'] = '%' . self::escapeLike($search) . '%';
        }

        $classId = filter_var($filters['turma'] ?? null, FILTER_VALIDATE_INT);

        if ($classId !== false && (int) $classId > 0) {
            $conditions[] = 'a.id_turma = :class';
            $params['class'] = (int) $classId;
        }

        $active = (string) ($filters['ativo'] ?? '');

        if (in_array($active, ['0', '1'], true)) {
            $conditions[] = 'a.ativo = :active';
            $params['active'] = (int) $active;
        }

        $dvaFilter = $statusService->filter((string) ($filters['dva'] ?? ''));

        if ($dvaFilter['sql'] !== '') {
            $conditions[] = $dvaFilter['sql'];
            $params = array_merge($params, $dvaFilter['params']);
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param array<string,int|string> $params */
    private function bind(PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
