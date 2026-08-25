<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/TextNormalizer.php';

use src\Core\SqliteTransaction;
use src\Core\TextNormalizer;

final class Passivo extends Model
{
    public const NAME_MAX_LENGTH = 150;
    public const BOX_MAX_LENGTH = 50;
    public const NUMBER_MAX_LENGTH = 40;
    public const SEARCH_MAX_LENGTH = 100;
    public const PAGE_MAX = 10000;

    private ?string $lastErrorCode = null;

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, min(self::PAGE_MAX, $page));
        $perPage = max(10, min(100, $perPage));
        [$where, $params] = $this->where($filters);
        $count = self::$pdo->prepare("SELECT COUNT(*) FROM alunos_passivo p {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, min(self::PAGE_MAX, (int) ceil($total / $perPage)));
        $page = min($page, $pages);
        $orders = [
            'nome' => 'p.nome_normalizado ASC, p.id ASC',
            'numero' => "CASE WHEN p.numero GLOB '[0-9]*' AND p.numero NOT GLOB '*[^0-9]*' THEN 0 ELSE 1 END,
                         CAST(p.numero AS INTEGER) ASC, p.numero_normalizado ASC, p.nome_normalizado ASC, p.id ASC",
            'caixa' => 'p.caixa_normalizada ASC, p.numero_normalizado ASC, p.nome_normalizado ASC, p.id ASC',
            'recente' => 'p.criado_em DESC, p.id DESC',
        ];
        $orderKey = (string) ($filters['ordem'] ?? 'nome');
        $orderBy = $orders[$orderKey] ?? $orders['nome'];
        $statement = self::$pdo->prepare(
            "SELECT p.id, p.aluno_origem_id, p.nome_completo, p.data_nascimento,
                    p.numero, p.caixa, p.ativo, p.localizacao_pendente,
                    p.criado_em, p.atualizado_em, p.inativado_em, p.restaurado_em
             FROM alunos_passivo p {$where}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset"
        );
        $this->bind($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @return array{caixas:int,registros:int,inativos:int,pendentes:int} */
    public function resumo(): array
    {
        $row = self::$pdo->query(
            'SELECT COUNT(DISTINCT CASE WHEN ativo = 1 AND caixa_normalizada IS NOT NULL THEN caixa_normalizada END) AS caixas,
                    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS registros,
                    SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) AS inativos,
                    SUM(CASE WHEN ativo = 1 AND localizacao_pendente = 1 THEN 1 ELSE 0 END) AS pendentes
             FROM alunos_passivo'
        )->fetch();

        return [
            'caixas' => (int) ($row['caixas'] ?? 0),
            'registros' => (int) ($row['registros'] ?? 0),
            'inativos' => (int) ($row['inativos'] ?? 0),
            'pendentes' => (int) ($row['pendentes'] ?? 0),
        ];
    }

    /** @return list<array{caixa:string,caixa_normalizada:string,total:int}> */
    public function caixas(): array
    {
        $rows = self::$pdo->query(
            'SELECT MIN(caixa) AS caixa, caixa_normalizada, COUNT(*) AS total
             FROM alunos_passivo
             WHERE ativo = 1 AND caixa_normalizada IS NOT NULL
             GROUP BY caixa_normalizada
             ORDER BY caixa_normalizada, MIN(id)'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'caixa' => (string) $row['caixa'],
            'caixa_normalizada' => (string) $row['caixa_normalizada'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /** @return array{anterior:?string,proxima:?string,lista:list<string>} */
    public function navegacaoCaixas(string $caixa): array
    {
        $boxes = array_column($this->caixas(), 'caixa');
        $currentKey = $this->safeSearchKey($caixa);
        $index = false;

        foreach ($boxes as $position => $box) {
            if ($this->safeSearchKey((string) $box) === $currentKey) {
                $index = $position;
                break;
            }
        }

        if ($index === false) {
            return ['anterior' => null, 'proxima' => null, 'lista' => []];
        }

        $start = max(0, $index - 3);

        return [
            'anterior' => $index > 0 ? (string) $boxes[$index - 1] : null,
            'proxima' => $index < count($boxes) - 1 ? (string) $boxes[$index + 1] : null,
            'lista' => array_map('strval', array_slice($boxes, $start, 7)),
        ];
    }

    /** @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        if ($id < 1) {
            return false;
        }

        $statement = self::$pdo->prepare(
            'SELECT p.*, a.nome_completo AS aluno_origem_nome,
                    uc.nome AS criado_por_nome, ua.nome AS atualizado_por_nome,
                    ui.nome AS inativado_por_nome, ur.nome AS restaurado_por_nome
             FROM alunos_passivo p
             LEFT JOIN alunos a ON a.id = p.aluno_origem_id
             LEFT JOIN usuarios uc ON uc.id = p.criado_por
             LEFT JOIN usuarios ua ON ua.id = p.atualizado_por
             LEFT JOIN usuarios ui ON ui.id = p.inativado_por
             LEFT JOIN usuarios ur ON ur.id = p.restaurado_por
             WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch();
    }

    /** @param array<string,mixed> $data */
    public function cadastrar(array $data, int $actorId): int|false
    {
        $this->lastErrorCode = null;
        $normalized = $this->validarDados($data);

        if ($normalized === false || !$this->validActor($actorId)) {
            $this->lastErrorCode ??= 'invalid_actor';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($normalized, $actorId): int|false {
                if ($this->locationConflict($pdo, $normalized['caixa_normalizada'], $normalized['numero_normalizado'])) {
                    $this->lastErrorCode = 'location_conflict';
                    AuditLogger::recordRequired(
                        $pdo, 'passive.conflict', AuditLogger::BLOCKED, $actorId, null,
                        'Conflito de localizacao impediu o cadastro.', 'passive_record'
                    );

                    return false;
                }

                $id = $this->insert($pdo, $normalized, $actorId, null);
                AuditLogger::recordRequired(
                    $pdo, 'passive.created', AuditLogger::SUCCESS, $actorId, null,
                    'Registro do arquivo passivo criado.', 'passive_record', $id
                );

                return $id;
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_create_failed', $exception);

            return false;
        }
    }

    /** @param array<string,mixed> $data */
    public function atualizar(int $id, array $data, int $actorId): bool
    {
        $this->lastErrorCode = null;
        $normalized = $this->validarDados($data);

        if ($id < 1 || $normalized === false || !$this->validActor($actorId)) {
            $this->lastErrorCode ??= 'invalid_actor';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $normalized, $actorId): bool {
                $existing = $pdo->prepare('SELECT id FROM alunos_passivo WHERE id = :id');
                $existing->execute(['id' => $id]);

                if ($existing->fetchColumn() === false) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if ($this->locationConflict($pdo, $normalized['caixa_normalizada'], $normalized['numero_normalizado'], $id)) {
                    $this->lastErrorCode = 'location_conflict';
                    AuditLogger::recordRequired(
                        $pdo, 'passive.conflict', AuditLogger::BLOCKED, $actorId, null,
                        'Conflito de localizacao impediu a atualizacao.', 'passive_record', $id
                    );

                    return false;
                }

                $statement = $pdo->prepare(
                    'UPDATE alunos_passivo
                     SET nome_completo = :name, nome_normalizado = :normalized_name,
                         data_nascimento = :birth_date, numero = :number,
                         numero_normalizado = :normalized_number, caixa = :box,
                         caixa_normalizada = :normalized_box, localizacao_pendente = 0,
                         atualizado_em = :updated_at, atualizado_por = :updated_by
                     WHERE id = :id'
                );
                $statement->execute([
                    'name' => $normalized['nome_completo'],
                    'normalized_name' => $normalized['nome_normalizado'],
                    'birth_date' => $normalized['data_nascimento'],
                    'number' => $normalized['numero'],
                    'normalized_number' => $normalized['numero_normalizado'],
                    'box' => $normalized['caixa'],
                    'normalized_box' => $normalized['caixa_normalizada'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'updated_by' => $actorId,
                    'id' => $id,
                ]);

                if ($statement->rowCount() !== 1) {
                    return false;
                }

                AuditLogger::recordRequired(
                    $pdo, 'passive.updated', AuditLogger::SUCCESS, $actorId, null,
                    'Registro do arquivo passivo atualizado.', 'passive_record', $id
                );

                return true;
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_update_failed', $exception);

            return false;
        }
    }

    public function definirAtivo(int $id, bool $active, int $actorId): bool
    {
        $this->lastErrorCode = null;

        if ($id < 1 || !$this->validActor($actorId)) {
            $this->lastErrorCode = 'invalid_actor';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $active, $actorId): bool {
                $statement = $pdo->prepare('SELECT * FROM alunos_passivo WHERE id = :id');
                $statement->execute(['id' => $id]);
                $row = $statement->fetch();

                if (!is_array($row)) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if ((int) $row['ativo'] === ($active ? 1 : 0)) {
                    return true;
                }

                if ($active) {
                    if ($row['aluno_origem_id'] !== null && $this->originConflict($pdo, (int) $row['aluno_origem_id'], $id)) {
                        $this->lastErrorCode = 'origin_conflict';

                        return false;
                    }

                    if ($this->locationConflict(
                        $pdo,
                        $row['caixa_normalizada'] === null ? null : (string) $row['caixa_normalizada'],
                        $row['numero_normalizado'] === null ? null : (string) $row['numero_normalizado'],
                        $id
                    )) {
                        $this->lastErrorCode = 'location_conflict';

                        return false;
                    }
                }

                $now = gmdate('Y-m-d H:i:s');
                $update = $pdo->prepare(
                    'UPDATE alunos_passivo
                     SET ativo = :active, atualizado_em = :now, atualizado_por = :actor,
                         inativado_em = :deactivated_at, inativado_por = :deactivated_by,
                         restaurado_em = :restored_at, restaurado_por = :restored_by
                     WHERE id = :id'
                );
                $update->execute([
                    'active' => $active ? 1 : 0,
                    'now' => $now,
                    'actor' => $actorId,
                    'deactivated_at' => $active ? $row['inativado_em'] : $now,
                    'deactivated_by' => $active ? $row['inativado_por'] : $actorId,
                    'restored_at' => $active ? $now : $row['restaurado_em'],
                    'restored_by' => $active ? $actorId : $row['restaurado_por'],
                    'id' => $id,
                ]);

                if ($update->rowCount() !== 1) {
                    return false;
                }

                AuditLogger::recordRequired(
                    $pdo, $active ? 'passive.reactivated' : 'passive.deactivated',
                    AuditLogger::SUCCESS, $actorId, null,
                    $active ? 'Registro do arquivo passivo restaurado.' : 'Registro do arquivo passivo inativado.',
                    'passive_record', $id
                );

                return true;
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_status_failed', $exception);

            return false;
        }
    }

    /** @param array<string,mixed> $location */
    public function arquivarAluno(int $studentId, array $location, int $actorId): int|false
    {
        $this->lastErrorCode = null;
        $normalizedLocation = $this->validarDados([
            'nome_completo' => 'Nome temporario',
            'data_nascimento' => '',
            'numero' => $location['numero'] ?? '',
            'caixa' => $location['caixa'] ?? '',
        ]);

        if ($studentId < 1 || $normalizedLocation === false || !$this->validActor($actorId)) {
            $this->lastErrorCode ??= 'invalid_actor';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($studentId, $normalizedLocation, $actorId): int|false {
                $studentQuery = $pdo->prepare(
                    'SELECT id, nome_completo, data_nascimento, ativo FROM alunos WHERE id = :id LIMIT 1'
                );
                $studentQuery->execute(['id' => $studentId]);
                $student = $studentQuery->fetch();

                if (!is_array($student)) {
                    $this->lastErrorCode = 'student_not_found';

                    return false;
                }

                if ((int) $student['ativo'] !== 0) {
                    $this->lastErrorCode = 'active_student';

                    return false;
                }

                if ($this->originConflict($pdo, $studentId)) {
                    $this->lastErrorCode = 'origin_conflict';
                    AuditLogger::recordRequired(
                        $pdo, 'passive.conflict', AuditLogger::BLOCKED, $actorId, null,
                        'Vinculo ativo duplicado de aluno foi bloqueado.', 'student', $studentId
                    );

                    return false;
                }

                if ($this->locationConflict($pdo, $normalizedLocation['caixa_normalizada'], $normalizedLocation['numero_normalizado'])) {
                    $this->lastErrorCode = 'location_conflict';
                    AuditLogger::recordRequired(
                        $pdo, 'passive.conflict', AuditLogger::BLOCKED, $actorId, null,
                        'Conflito de localizacao impediu o arquivamento do aluno.', 'student', $studentId
                    );

                    return false;
                }

                $normalized = $normalizedLocation;
                $normalized['nome_completo'] = TextNormalizer::displayName((string) $student['nome_completo']);
                $normalized['nome_normalizado'] = TextNormalizer::searchKey($normalized['nome_completo']);
                $normalized['data_nascimento'] = (string) $student['data_nascimento'];

                $id = $this->insert($pdo, $normalized, $actorId, $studentId);
                AuditLogger::recordRequired(
                    $pdo, 'passive.student_archived', AuditLogger::SUCCESS, $actorId, null,
                    'Aluno inativo vinculado ao arquivo passivo.', 'passive_record', $id
                );

                return $id;
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_student_archive_failed', $exception);

            return false;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{nome_completo:string,nome_normalizado:string,data_nascimento:?string,numero:?string,numero_normalizado:?string,caixa:string,caixa_normalizada:string}|false
     */
    public function validarDados(array $data): array|false
    {
        $this->lastErrorCode = null;

        try {
            $name = TextNormalizer::displayName((string) ($data['nome_completo'] ?? $data['nome'] ?? ''));
            $box = TextNormalizer::displayName((string) ($data['caixa'] ?? ''));
            $number = TextNormalizer::displayName((string) ($data['numero'] ?? ''));
        } catch (Throwable) {
            $this->lastErrorCode = 'invalid_utf8';

            return false;
        }

        if ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
            $this->lastErrorCode = 'invalid_name';

            return false;
        }

        if ($box === '' || mb_strlen($box, 'UTF-8') > self::BOX_MAX_LENGTH
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $box) !== 1) {
            $this->lastErrorCode = 'invalid_box';

            return false;
        }

        if ($number !== '' && (mb_strlen($number, 'UTF-8') > self::NUMBER_MAX_LENGTH
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $number) !== 1)) {
            $this->lastErrorCode = 'invalid_number';

            return false;
        }

        $birthDate = trim((string) ($data['data_nascimento'] ?? ''));

        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format('Y-m-d') !== $birthDate) {
                $this->lastErrorCode = 'invalid_birth_date';

                return false;
            }

            if ($date > new DateTimeImmutable('today')) {
                $this->lastErrorCode = 'future_birth_date';

                return false;
            }
        }

        return [
            'nome_completo' => $name,
            'nome_normalizado' => TextNormalizer::searchKey($name),
            'data_nascimento' => $birthDate === '' ? null : $birthDate,
            'numero' => $number === '' ? null : $number,
            'numero_normalizado' => $number === '' ? null : TextNormalizer::searchKey($number),
            'caixa' => $box,
            'caixa_normalizada' => TextNormalizer::searchKey($box),
        ];
    }

    /**
     * @param list<array{line:int,nome_completo:string,data_nascimento:string,numero:string,caixa:string}> $rows
     * @return array{valid_rows:list<array<string,mixed>>,errors:list<array{line:int,message:string}>,valid:int,invalid:int,duplicate:int,conflict:int}
     */
    public function analisarImportacao(array $rows): array
    {
        $validRows = [];
        $errors = [];
        $seenPeople = [];
        $seenLocations = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'conflict' => 0];

        foreach ($rows as $row) {
            $normalized = $this->validarDados($row);

            if ($normalized === false) {
                $counts['invalid']++;
                $errors[] = ['line' => $row['line'], 'message' => $this->validationMessage($this->lastErrorCode)];
                continue;
            }

            $personKey = $normalized['nome_normalizado'] . "\0" . ($normalized['data_nascimento'] ?? '');
            $locationKey = $normalized['numero_normalizado'] === null
                ? null
                : $normalized['caixa_normalizada'] . "\0" . $normalized['numero_normalizado'];

            if (isset($seenPeople[$personKey]) || $this->databaseDuplicate(self::$pdo, $normalized)) {
                $counts['duplicate']++;
                $errors[] = ['line' => $row['line'], 'message' => 'Registro duplicado no arquivo ou no acervo atual.'];
                continue;
            }

            if ($locationKey !== null && (isset($seenLocations[$locationKey])
                || $this->locationConflict(self::$pdo, $normalized['caixa_normalizada'], $normalized['numero_normalizado']))) {
                $counts['conflict']++;
                $errors[] = ['line' => $row['line'], 'message' => 'A caixa e a posicao informadas ja estao ocupadas.'];
                continue;
            }

            $seenPeople[$personKey] = true;

            if ($locationKey !== null) {
                $seenLocations[$locationKey] = true;
            }

            $normalized['line'] = $row['line'];
            $validRows[] = $normalized;
            $counts['valid']++;
        }

        return [
            'valid_rows' => $validRows,
            'errors' => array_slice($errors, 0, 50),
            'valid' => $counts['valid'],
            'invalid' => $counts['invalid'],
            'duplicate' => $counts['duplicate'],
            'conflict' => $counts['conflict'],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    public function importarLote(array $rows, int $actorId): int|false
    {
        $this->lastErrorCode = null;

        if ($rows === [] || !$this->validActor($actorId)) {
            $this->lastErrorCode = $rows === [] ? 'empty_import' : 'invalid_actor';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($rows, $actorId): int|false {
                foreach ($rows as $row) {
                    if ($this->databaseDuplicate($pdo, $row)) {
                        $this->lastErrorCode = 'duplicate_changed';

                        return false;
                    }

                    if ($this->locationConflict($pdo, (string) $row['caixa_normalizada'], $row['numero_normalizado'] === null ? null : (string) $row['numero_normalizado'])) {
                        $this->lastErrorCode = 'location_conflict';

                        return false;
                    }
                }

                foreach ($rows as $row) {
                    $this->insert($pdo, $row, $actorId, null);
                }

                AuditLogger::recordRequired(
                    $pdo, 'passive.import_completed', AuditLogger::SUCCESS, $actorId, null,
                    sprintf('Importacao aditiva concluida com %d registros.', count($rows)), 'passive_import'
                );

                return count($rows);
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_import_failed', $exception);

            return false;
        }
    }

    /** @return array{caixa:string,assignments:list<array{id:int,nome:string,numero:string}>}|false */
    public function previewEnumeracao(string $box): array|false
    {
        $this->lastErrorCode = null;
        $boxKey = $this->validatedBoxKey($box);

        if ($boxKey === false) {
            return false;
        }

        $all = self::$pdo->prepare(
            'SELECT id, nome_completo, numero, numero_normalizado
             FROM alunos_passivo WHERE ativo = 1 AND caixa_normalizada = :box
             ORDER BY nome_normalizado, id'
        );
        $all->execute(['box' => $boxKey['key']]);
        $rows = $all->fetchAll();

        if ($rows === []) {
            $this->lastErrorCode = 'box_not_found';

            return false;
        }

        $used = [];
        $maximum = 0;

        foreach ($rows as $row) {
            $number = trim((string) ($row['numero'] ?? ''));

            if ($number === '') {
                continue;
            }

            $key = (string) $row['numero_normalizado'];

            if (isset($used[$key])) {
                $this->lastErrorCode = 'location_conflict';

                return false;
            }

            $used[$key] = true;

            if (preg_match('/^[1-9][0-9]*$/', $number) === 1) {
                $maximum = max($maximum, (int) $number);
            }
        }

        $assignments = [];
        $next = $maximum + 1;

        foreach ($rows as $row) {
            if (trim((string) ($row['numero'] ?? '')) !== '') {
                continue;
            }

            while (isset($used[(string) $next])) {
                $next++;
            }

            $assignments[] = ['id' => (int) $row['id'], 'nome' => (string) $row['nome_completo'], 'numero' => (string) $next];
            $used[(string) $next] = true;
            $next++;
        }

        return ['caixa' => $boxKey['display'], 'assignments' => $assignments];
    }

    /** @param list<array{id:int,nome:string,numero:string}> $assignments */
    public function aplicarEnumeracao(string $box, array $assignments, int $actorId): int|false
    {
        $this->lastErrorCode = null;
        $boxKey = $this->validatedBoxKey($box);

        if ($boxKey === false || !$this->validActor($actorId)) {
            $this->lastErrorCode ??= 'invalid_actor';

            return false;
        }

        if ($assignments === []) {
            return 0;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($boxKey, $assignments, $actorId): int|false {
                $select = $pdo->prepare(
                    "SELECT numero FROM alunos_passivo
                     WHERE id = :id AND ativo = 1 AND caixa_normalizada = :box"
                );
                $update = $pdo->prepare(
                    'UPDATE alunos_passivo SET numero = :number, numero_normalizado = :normalized,
                         atualizado_em = :now, atualizado_por = :actor
                     WHERE id = :id'
                );
                $seen = [];

                foreach ($assignments as $assignment) {
                    $id = $assignment['id'];
                    $number = $assignment['numero'];

                    if ($id < 1 || preg_match('/^[1-9][0-9]*$/', $number) !== 1 || isset($seen[$number])) {
                        $this->lastErrorCode = 'preview_changed';

                        return false;
                    }

                    $select->execute(['id' => $id, 'box' => $boxKey['key']]);
                    $current = $select->fetchColumn();

                    if ($current === false || trim((string) $current) !== ''
                        || $this->locationConflict($pdo, $boxKey['key'], $number, $id)) {
                        $this->lastErrorCode = 'preview_changed';

                        return false;
                    }

                    $seen[$number] = true;
                }

                $now = gmdate('Y-m-d H:i:s');

                foreach ($assignments as $assignment) {
                    $update->execute([
                        'number' => $assignment['numero'],
                        'normalized' => $assignment['numero'],
                        'now' => $now,
                        'actor' => $actorId,
                        'id' => $assignment['id'],
                    ]);
                }

                AuditLogger::recordRequired(
                    $pdo, 'passive.enumerated', AuditLogger::SUCCESS, $actorId, null,
                    sprintf('Enumeracao concluida para %d registros.', count($assignments)), 'passive_box'
                );

                return count($assignments);
            });
        } catch (Throwable $exception) {
            $this->databaseFailure('passive_enumeration_failed', $exception);

            return false;
        }
    }

    /** @return list<array{numero:?string,nome_completo:string}>|false */
    public function listarParaTxt(string $box): array|false
    {
        $boxKey = $this->validatedBoxKey($box);

        if ($boxKey === false) {
            return false;
        }

        $statement = self::$pdo->prepare(
            "SELECT numero, nome_completo FROM alunos_passivo
             WHERE ativo = 1 AND caixa_normalizada = :box
             ORDER BY CASE WHEN numero GLOB '[0-9]*' AND numero NOT GLOB '*[^0-9]*' THEN 0 ELSE 1 END,
                      CAST(numero AS INTEGER), numero_normalizado, nome_normalizado, id"
        );
        $statement->execute(['box' => $boxKey['key']]);
        $rows = $statement->fetchAll();

        return $rows === [] ? false : array_map(static fn (array $row): array => [
            'numero' => $row['numero'] === null ? null : (string) $row['numero'],
            'nome_completo' => (string) $row['nome_completo'],
        ], $rows);
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function validationMessage(?string $code): string
    {
        return match ($code) {
            'invalid_utf8' => 'O texto informado nao possui UTF-8 valido.',
            'invalid_name' => 'Informe um nome com ate 150 caracteres.',
            'invalid_box' => 'Informe uma caixa valida com ate 50 caracteres.',
            'invalid_number' => 'Informe uma posicao valida com ate 40 caracteres.',
            'invalid_birth_date' => 'Informe uma data real no formato AAAA-MM-DD.',
            'future_birth_date' => 'A data de nascimento nao pode estar no futuro.',
            'location_conflict' => 'A caixa e a posicao informadas ja estao ocupadas.',
            'origin_conflict' => 'Este aluno ja possui um registro ativo no Arquivo Passivo.',
            'active_student' => 'Somente alunos inativos podem ser enviados ao Arquivo Passivo.',
            'student_not_found', 'not_found' => 'O registro solicitado nao foi encontrado.',
            'box_not_found' => 'A caixa informada nao existe ou nao possui registros ativos.',
            'preview_changed', 'duplicate_changed' => 'O acervo mudou depois da previa. Gere uma nova previa.',
            'empty_import' => 'A previa nao possui linhas validas para importar.',
            default => 'Nao foi possivel concluir a operacao. Tente novamente.',
        };
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{string,array<string,mixed>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        $active = (string) ($filters['ativo'] ?? '1');

        if (in_array($active, ['0', '1'], true)) {
            $conditions[] = 'p.ativo = :active';
            $params['active'] = (int) $active;
        }

        $box = mb_substr((string) ($filters['caixa'] ?? ''), 0, self::BOX_MAX_LENGTH + 1, 'UTF-8');

        if ($box !== '') {
            $conditions[] = 'p.caixa_normalizada = :box';
            $params['box'] = $this->safeSearchKey($box);
        }

        $query = mb_substr((string) ($filters['q'] ?? ''), 0, self::SEARCH_MAX_LENGTH, 'UTF-8');

        if ($query !== '') {
            $queryKey = $this->safeSearchKey($query);

            if ($queryKey === '' && trim($query) !== '') {
                $conditions[] = '0 = 1';
            } elseif ($queryKey !== '') {
                $conditions[] = "(p.nome_normalizado LIKE :query ESCAPE '\\'
                                  OR p.numero_normalizado LIKE :query ESCAPE '\\')";
                $params['query'] = '%' . $this->escapeLike($queryKey) . '%';
            }
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param array<string,mixed> $data */
    private function insert(PDO $pdo, array $data, int $actorId, ?int $studentId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            'INSERT INTO alunos_passivo
                (aluno_origem_id, nome_completo, nome_normalizado, data_nascimento,
                 numero, numero_normalizado, caixa, caixa_normalizada, ativo,
                 localizacao_pendente, criado_em, atualizado_em, criado_por, atualizado_por)
             VALUES
                (:student_id, :name, :normalized_name, :birth_date,
                 :number, :normalized_number, :box, :normalized_box, 1, 0,
                 :now, :now, :actor, :actor)'
        );
        $statement->execute([
            'student_id' => $studentId,
            'name' => $data['nome_completo'],
            'normalized_name' => $data['nome_normalizado'],
            'birth_date' => $data['data_nascimento'],
            'number' => $data['numero'],
            'normalized_number' => $data['numero_normalizado'],
            'box' => $data['caixa'],
            'normalized_box' => $data['caixa_normalizada'],
            'now' => $now,
            'actor' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    private function databaseDuplicate(PDO $pdo, array $data): bool
    {
        $statement = $pdo->prepare(
            "SELECT 1 FROM alunos_passivo
             WHERE ativo = 1 AND nome_normalizado = :name
               AND COALESCE(data_nascimento, '') = COALESCE(:birth_date, '') LIMIT 1"
        );
        $statement->execute(['name' => $data['nome_normalizado'], 'birth_date' => $data['data_nascimento']]);

        return $statement->fetchColumn() !== false;
    }

    private function locationConflict(PDO $pdo, ?string $boxKey, ?string $numberKey, ?int $excludeId = null): bool
    {
        if ($boxKey === null || $numberKey === null || $numberKey === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM alunos_passivo
                WHERE ativo = 1 AND caixa_normalizada = :box AND numero_normalizado = :number';
        $params = ['box' => $boxKey, 'number' => $numberKey];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    private function originConflict(PDO $pdo, int $studentId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM alunos_passivo WHERE ativo = 1 AND aluno_origem_id = :student_id';
        $params = ['student_id' => $studentId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    private function validActor(int $actorId): bool
    {
        if ($actorId < 1) {
            return false;
        }

        $statement = self::$pdo->prepare('SELECT 1 FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1');
        $statement->execute(['id' => $actorId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{display:string,key:string}|false */
    private function validatedBoxKey(string $box): array|false
    {
        try {
            $display = TextNormalizer::displayName($box);
        } catch (Throwable) {
            $this->lastErrorCode = 'invalid_box';

            return false;
        }

        if ($display === '' || mb_strlen($display, 'UTF-8') > self::BOX_MAX_LENGTH
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $display) !== 1) {
            $this->lastErrorCode = 'invalid_box';

            return false;
        }

        return ['display' => $display, 'key' => TextNormalizer::searchKey($display)];
    }

    /** @param array<string,mixed> $params */
    private function bind(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function safeSearchKey(string $value): string
    {
        try {
            return TextNormalizer::searchKey($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function databaseFailure(string $event, Throwable $exception): void
    {
        $this->lastErrorCode ??= 'database_error';

        if (class_exists('TechnicalLogger', false)) {
            TechnicalLogger::error($event, ['exception' => $exception::class]);
        }
    }
}
