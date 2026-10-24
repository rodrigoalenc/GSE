<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/RequestReceipt.php';
require_once ROOT_PATH . '/src/Core/SerieTurma.php';

use src\Core\SqliteTransaction;
use src\Core\SerieTurma;

final class VinculoEscolar extends Model
{
    private ?string $lastErrorCode = null;

    /** @return array<string,mixed>|false */
    public function previewTransferencia(int $student, int $destination, int $actor): array|false
    {
        return $this->readPlan('transferencia', $student, $destination, $actor);
    }

    /** @return array<string,mixed>|false */
    public function previewRetorno(int $passive, int $destination, int $actor): array|false
    {
        return $this->readPlan('retorno', $passive, $destination, $actor);
    }

    /** @return array<string,mixed>|false */
    private function readPlan(string $type, int $id, int $destination, int $actor): array|false
    {
        $this->lastErrorCode = null;
        try {
            $this->admin($actor);
            return $this->prepare($type, $id, $destination, $actor);
        } catch (DomainException $e) {
            $this->lastErrorCode = $e->getMessage();
            return false;
        }
    }

    /** @param array<string,mixed> $plan */
    public function confirmar(array $plan, int $actor, string $token): int|false
    {
        $this->lastErrorCode = null;
        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($plan, $actor, $token): int|false {
                $this->admin($actor);
                $this->ensure(in_array($plan['tipo'] ?? null, ['transferencia', 'retorno'], true)
                    && ($plan['ator'] ?? null) === $actor && is_array($plan['aluno'] ?? null)
                    && is_array($plan['destino'] ?? null) && is_string($plan['fingerprint'] ?? null), 'invalid_preview');
                $snapshot = $plan;
                unset($snapshot['fingerprint']);
                $this->ensure(hash_equals($this->digest($snapshot), $plan['fingerprint']), 'preview_changed');
                return RequestReceipt::run($pdo, $actor, 'student.school_link', $token, [$plan], function () use ($pdo, $plan, $actor): int {
                    $id = $plan['tipo'] === 'retorno' ? (int) ($plan['passivo']['id'] ?? 0) : (int) ($plan['aluno']['id'] ?? 0);
                    $current = $this->prepare($plan['tipo'], $id, (int) ($plan['destino']['id'] ?? 0), $actor);
                    $this->ensure(hash_equals($current['fingerprint'], $plan['fingerprint']), 'preview_changed');
                    return $this->save($pdo, $current, $actor);
                });
            });
        } catch (DomainException $e) {
            $this->lastErrorCode = $e->getMessage();
        } catch (Throwable $e) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('school_link_failed', ['exception' => $e::class]);
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function turmas(): array
    {
        $rows = self::$pdo->query('SELECT t.id,t.nome_turma,t.ano_letivo,t.ativo,t.revisao,s.serie
            FROM turmas t LEFT JOIN turmas_series s ON s.id_turma=t.id ORDER BY t.nome_normalizado,t.ano_letivo DESC,t.id')->fetchAll();
        return array_map($this->identifySeries(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function historico(int $student): array
    {
        $query = self::$pdo->prepare('SELECT h.*,gse_name(u.nome,u.id) AS responsavel FROM alunos_vinculos_historico h
            LEFT JOIN usuarios u ON u.id=h.realizado_por WHERE h.aluno_id=? ORDER BY h.id DESC');
        $query->execute([$student]);
        return $query->fetchAll();
    }

    /** @return array<string,mixed> */
    private function prepare(string $type, int $id, int $destination, int $actor): array
    {
        $this->ensure($id > 0 && $destination > 0, 'invalid_selection');
        $passive = null;
        $studentId = $id;
        if ($type === 'retorno') {
            $query = self::$pdo->prepare('SELECT id,aluno_origem_id,nome_completo,caixa,numero,ativo,revisao
                FROM alunos_passivo WHERE id=?');
            $query->execute([$id]);
            $passive = $query->fetch();
            $this->ensure(is_array($passive) && (int) $passive['ativo'] === 1
                && $passive['aluno_origem_id'] !== null, 'invalid_passive');
            $studentId = (int) $passive['aluno_origem_id'];
        }
        $query = self::$pdo->prepare('SELECT a.id,a.nome_completo,a.data_nascimento,a.id_turma,a.ativo,a.revisao,
            EXISTS(SELECT 1 FROM alunos_passivo p WHERE p.aluno_origem_id=a.id AND p.ativo=1) AS no_passivo
            FROM alunos a WHERE a.id=?');
        $query->execute([$studentId]);
        $student = $query->fetch();
        $this->ensure(is_array($student), 'invalid_selection');
        $classes = array_column($this->turmas(), null, 'id');
        $target = $classes[$destination] ?? null;
        $this->ensure($target !== null && (int) $target['ativo'] === 1 && $target['serie'] !== null
            && $this->validYear($target['ano_letivo']), 'invalid_class');
        $source = $classes[(int) $student['id_turma']] ?? null;
        if ($type === 'transferencia') {
            $this->ensure((int) $student['ativo'] === 1 && (int) $student['no_passivo'] === 0, 'inactive_student');
            $this->ensure($source !== null && (int) $source['ativo'] === 1 && $source['serie'] !== null
                && (int) $source['id'] !== $destination && $source['serie'] === $target['serie'], 'invalid_series');
            $this->ensure($this->validYear($source['ano_letivo']) && (int) $source['ano_letivo'] === (int) $target['ano_letivo'], 'invalid_year');
        } else {
            $this->ensure((int) $student['ativo'] === 0 && (int) $student['no_passivo'] === 1, 'invalid_passive');
        }
        $plan = ['tipo' => $type, 'ator' => $actor, 'aluno' => $student, 'origem' => $source, 'destino' => $target, 'passivo' => $passive];
        $plan['fingerprint'] = $this->digest($plan);
        return $plan;
    }

    /** @param array<string,mixed> $plan */
    private function save(PDO $pdo, array $plan, int $actor): int
    {
        $student = $plan['aluno'];
        $source = $plan['origem'];
        $target = $plan['destino'];
        $passive = $plan['passivo'];
        $now = gmdate('Y-m-d H:i:s');
        if ($passive !== null) {
            // The active archive link disappears before the student is reactivated.
            // Any later failure rolls back both records, the history and the receipt.
            $update = $pdo->prepare('UPDATE alunos_passivo SET ativo=0,atualizado_em=?,atualizado_por=?,inativado_em=?,inativado_por=?
                WHERE id=? AND revisao=? AND ativo=1 AND aluno_origem_id=?');
            $update->execute([$now, $actor, $now, $actor, $passive['id'], $passive['revisao'], $student['id']]);
            $this->ensure($update->rowCount() === 1, 'preview_changed');
        }
        $update = $pdo->prepare('UPDATE alunos SET id_turma=?,ativo=1,atualizado_em=?
            WHERE id=? AND revisao=? AND ativo=?');
        $update->execute([$target['id'], $now, $student['id'], $student['revisao'], $student['ativo']]);
        $this->ensure($update->rowCount() === 1, 'preview_changed');
        $pdo->prepare('INSERT INTO alunos_vinculos_historico(tipo,aluno_id,passivo_id,origem_id,origem_nome,origem_ano,origem_serie,
            destino_id,destino_nome,destino_ano,destino_serie,realizado_por) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$plan['tipo'], $student['id'], $passive['id'] ?? null, $source['id'] ?? null, $source['nome_turma'] ?? null,
                $source['ano_letivo'] ?? null, $source['serie'] ?? null, $target['id'], $target['nome_turma'], $target['ano_letivo'], $target['serie'], $actor]);
        $history = (int) $pdo->lastInsertId();
        AuditLogger::recordRequired($pdo, $passive === null ? 'student.transferred' : 'student.returned_from_passive', AuditLogger::SUCCESS,
            $actor, null, $passive === null
                ? sprintf('Transferência na mesma série: turma %d → %d; ano %d; histórico %d.', $source['id'], $target['id'], $target['ano_letivo'], $history)
                : sprintf('Retorno do Arquivo Passivo %d: aluno reativado na turma %d; ano %d; histórico %d.', $passive['id'], $target['id'], $target['ano_letivo'], $history),
            'student', (int) $student['id']);
        if ($passive !== null) {
            AuditLogger::recordRequired($pdo, 'passive.student_returned', AuditLogger::SUCCESS, $actor, null,
                sprintf('Vínculo passivo encerrado por retorno do aluno %d à escola; histórico %d.', $student['id'], $history), 'passive_record', (int) $passive['id']);
        }
        return $history;
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function identifySeries(array $row): array
    {
        $row['serie'] = $row['serie'] === null ? SerieTurma::identificar((string) $row['nome_turma']) : (int) $row['serie'];
        return $row;
    }

    private function admin(int $actor): void
    {
        $query = self::$pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND ativo=1 AND tipo='administrador'");
        $query->execute([$actor]);
        $this->ensure($query->fetchColumn() !== false, 'invalid_actor');
    }

    private function validYear(mixed $year): bool
    {
        return $year !== null && (int) $year >= 2000 && (int) $year <= (int) date('Y') + 5;
    }

    private function ensure(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException($code);
        }
    }

    /** @param array<mixed> $data */
    private function digest(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function errorMessage(): string
    {
        return match ($this->lastErrorCode) {
            'invalid_actor' => 'Somente administradores ativos podem realizar esta operação.',
            'invalid_class' => 'Escolha uma turma ativa, com série identificada e ano letivo válido.',
            'invalid_series' => 'A transferência exige turmas diferentes e ativas da mesma série. Confira a identificação das séries.',
            'invalid_year' => 'As duas turmas devem ter o mesmo ano letivo informado e válido.',
            'inactive_student' => 'A transferência exige aluno ativo e sem vínculo ativo no Arquivo Passivo.',
            'invalid_passive' => 'O retorno exige registro ativo no Arquivo Passivo vinculado a um aluno inativo.',
            'invalid_selection' => 'O aluno ou registro informado não foi encontrado.',
            'invalid_preview', 'preview_changed' => 'Os dados mudaram ou a prévia não é válida. Confira a situação atual e gere uma nova prévia.',
            default => 'Não foi possível concluir a operação. Nenhum vínculo foi alterado. Gere uma nova prévia.',
        };
    }
}
