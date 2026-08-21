<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';

use src\Core\SqliteTransaction;

final class Dva extends Model
{
    public const OBSERVATION_MAX_LENGTH = 1000;

    private ?string $lastErrorCode = null;

    /** @return array<string,mixed>|false */
    public function atualDoAluno(int $studentId): array|false
    {
        if ($studentId < 1) {
            return false;
        }

        $statement = self::$pdo->prepare(
            'SELECT d.id, d.id_aluno, d.id_usuario_registro, d.data_vencimento,
                    d.observacao, d.ativo, d.criado_em, d.atualizado_em, d.substituido_em,
                    u.nome AS usuario_registro
             FROM dvas d
             LEFT JOIN usuarios u ON u.id = d.id_usuario_registro
             WHERE d.id_aluno = :student AND d.ativo = 1
             LIMIT 1'
        );
        $statement->execute(['student' => $studentId]);

        return $statement->fetch();
    }

    /** @return list<array<string,mixed>> */
    public function historicoDoAluno(int $studentId): array
    {
        if ($studentId < 1) {
            return [];
        }

        $statement = self::$pdo->prepare(
            'SELECT d.id, d.id_aluno, d.id_usuario_registro, d.data_vencimento,
                    d.observacao, d.ativo, d.criado_em, d.atualizado_em, d.substituido_em,
                    u.nome AS usuario_registro
             FROM dvas d
             LEFT JOIN usuarios u ON u.id = d.id_usuario_registro
             WHERE d.id_aluno = :student
             ORDER BY d.ativo DESC, COALESCE(d.criado_em, \'\') DESC, d.id DESC'
        );
        $statement->execute(['student' => $studentId]);

        return $statement->fetchAll();
    }

    /** @return array{id:int, renewed:bool}|false */
    public function registrar(int $studentId, string $expirationDate, ?string $observation, int $actorId): array|false
    {
        $this->lastErrorCode = null;
        $normalized = self::validateData($expirationDate, $observation);

        if ($studentId < 1 || $actorId < 1 || $normalized === false) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($studentId, $normalized, $actorId): array|false {
                $student = $pdo->prepare('SELECT ativo FROM alunos WHERE id = :id LIMIT 1');
                $student->execute(['id' => $studentId]);
                $studentActive = $student->fetchColumn();

                if ($studentActive === false) {
                    $this->lastErrorCode = 'student_not_found';

                    return false;
                }

                if ((int) $studentActive !== 1) {
                    $this->lastErrorCode = 'inactive_student';

                    return false;
                }

                $actor = $pdo->prepare('SELECT 1 FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1');
                $actor->execute(['id' => $actorId]);

                if ($actor->fetchColumn() === false) {
                    $this->lastErrorCode = 'invalid_actor';

                    return false;
                }

                $current = $pdo->prepare('SELECT id FROM dvas WHERE id_aluno = :student AND ativo = 1 LIMIT 1');
                $current->execute(['student' => $studentId]);
                $currentId = $current->fetchColumn();
                $now = gmdate('Y-m-d H:i:s');

                if ($currentId !== false) {
                    $archive = $pdo->prepare(
                        'UPDATE dvas SET ativo = 0, substituido_em = :now, atualizado_em = :now
                         WHERE id = :id AND ativo = 1'
                    );
                    $archive->execute(['now' => $now, 'id' => (int) $currentId]);

                    if ($archive->rowCount() !== 1) {
                        throw new RuntimeException('Falha ao arquivar a DVA atual.');
                    }
                }

                $insert = $pdo->prepare(
                    'INSERT INTO dvas
                        (id_aluno, id_usuario_registro, data_vencimento, observacao, ativo, criado_em, atualizado_em)
                     VALUES (:student, :actor, :expiration, :observation, 1, :now, :now)'
                );
                $insert->execute([
                    'student' => $studentId,
                    'actor' => $actorId,
                    'expiration' => $normalized['expiration_date'],
                    'observation' => $normalized['observation'],
                    'now' => $now,
                ]);

                return ['id' => (int) $pdo->lastInsertId(), 'renewed' => $currentId !== false];
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('dva_write_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /** @return array{expiration_date:string, observation:?string}|false */
    public static function validateData(string $expirationDate, ?string $observation): array|false
    {
        $expirationDate = trim($expirationDate);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate, new DateTimeZone('UTC'));
        $observation = trim((string) $observation);

        if ($parsed === false
            || $parsed->format('Y-m-d') !== $expirationDate
            || mb_strlen($observation, 'UTF-8') > self::OBSERVATION_MAX_LENGTH
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $observation) === 1) {
            return false;
        }

        return [
            'expiration_date' => $expirationDate,
            'observation' => $observation === '' ? null : $observation,
        ];
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }
}
