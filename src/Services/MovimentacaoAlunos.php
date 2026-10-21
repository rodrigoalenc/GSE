<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/RequestReceipt.php';
require_once ROOT_PATH . '/src/Core/TextNormalizer.php';
require_once ROOT_PATH . '/src/Services/MovimentacaoAnual.php';

use src\Core\SqliteTransaction;

final class MovimentacaoAlunos extends Model
{
    use MovimentacaoAnual;
    public const MAX_ALUNOS = 200;
    private ?string $lastErrorCode = null;

    /** @return array<int,string> */
    public static function series(): array
    {
        $labels = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[$i] = $i <= 9 ? $i . 'º ano do ensino fundamental' : ($i - 9) . 'º ano do ensino médio';
        }
        return $labels;
    }

    /** @return list<array<string,mixed>> */
    public function turmas(): array
    {
        return self::$pdo->query('SELECT t.*, s.serie,
            (SELECT COUNT(*) FROM alunos a WHERE a.id_turma=t.id AND a.ativo=1) AS alunos_ativos
            FROM turmas t LEFT JOIN turmas_series s ON s.id_turma=t.id
            ORDER BY t.ativo DESC, t.nome_normalizado, t.ano_letivo DESC, t.id')->fetchAll();
    }

    /** @param array<mixed> $mapping */
    public function definirSeries(array $mapping, int $actor): bool
    {
        return $this->write(function (PDO $pdo) use ($mapping, $actor): bool {
            $this->admin($actor);
            $this->ensure($mapping !== [] && count($mapping) <= 1000, 'invalid_class');
            $classes = array_column($this->turmas(), null, 'id');
            $valid = [];
            foreach ($mapping as $id => $serie) {
                $classId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $this->ensure($classId !== false && isset($classes[$classId]), 'invalid_class');
                $level = $serie === '' ? null : ((is_int($serie) || is_string($serie))
                    ? filter_var($serie, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) : false);
                $this->ensure($level !== false, 'invalid_series');
                $valid[$classId] = $level;
            }
            foreach ($valid as $id => $level) {
                $old = $classes[$id]['serie'] === null ? null : (int) $classes[$id]['serie'];
                if ($old === $level) {
                    continue;
                }
                if ($level === null) {
                    $pdo->prepare('DELETE FROM turmas_series WHERE id_turma=?')->execute([$id]);
                } else {
                    $pdo->prepare('INSERT INTO turmas_series(id_turma,serie) VALUES(?,?)
                        ON CONFLICT(id_turma) DO UPDATE SET serie=excluded.serie')->execute([$id, $level]);
                }
                AuditLogger::recordRequired($pdo, 'class.series_configured', AuditLogger::SUCCESS, $actor, null,
                    sprintf('Série identificada: %s → %s. Nome e ano letivo preservados.', $old ?? 'não informada', $level ?? 'não informada'), 'class', (int) $id);
            }
            return true;
        });
    }

    /** @return array{ano:int,alunos:list<array<string,mixed>>,pendentes:int,liberada:bool,fingerprint:string} */
    public function conferencia(int $year): array
    {
        $query = self::$pdo->prepare("SELECT a.id,a.nome_completo,a.id_turma,a.atualizado_em,t.nome_turma
            FROM alunos a JOIN turmas t ON t.id=a.id_turma JOIN turmas_series s ON s.id_turma=t.id
            WHERE a.ativo=1 AND s.serie=12 AND NOT EXISTS (
                SELECT 1 FROM alunos_movimentacoes m JOIN alunos_movimentacao_lotes l ON l.id=m.lote_id
                WHERE m.aluno_id=a.id AND l.ano=? AND l.tipo='progressao' AND m.destino_serie=12
                AND m.destino_id=a.id_turma AND m.id=(SELECT MAX(last.id) FROM alunos_movimentacoes last WHERE last.aluno_id=a.id)
            ) ORDER BY a.nome_normalizado,a.id");
        $query->execute([$year]);
        $students = $query->fetchAll();
        $pending = (int) self::$pdo->query('SELECT COUNT(*) FROM alunos a
            LEFT JOIN turmas_series s ON s.id_turma=a.id_turma WHERE a.ativo=1 AND s.serie IS NULL')->fetchColumn();
        $saved = self::$pdo->prepare('SELECT alunos_json FROM alunos_conferencias WHERE ano=?');
        $saved->execute([$year]);
        $json = $saved->fetchColumn();
        $reviewed = $json === false ? [] : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        $released = $json !== false && $pending === 0;
        foreach ($students as $student) {
            if (!isset($reviewed[(string) $student['id']]) || (int) $reviewed[(string) $student['id']] !== (int) $student['id_turma']) {
                $released = false;
            }
        }
        return ['ano' => $year, 'alunos' => $students, 'pendentes' => $pending, 'liberada' => $released,
            'fingerprint' => $this->fingerprint([$year, $students, $pending])];
    }

    public function confirmarConferencia(int $year, string $fingerprint, int $actor): bool
    {
        return $this->write(function (PDO $pdo) use ($year, $fingerprint, $actor): bool {
            $this->admin($actor);
            $this->ensure($this->validYear($year), 'invalid_year');
            $state = $this->conferencia($year);
            $this->ensure($state['pendentes'] === 0, 'unclassified_classes');
            $this->ensure(hash_equals($state['fingerprint'], $fingerprint), 'preview_changed');
            if ($state['liberada']) {
                return true;
            }
            $reviewed = [];
            foreach ($state['alunos'] as $student) {
                $reviewed[(int) $student['id']] = (int) $student['id_turma'];
            }
            $pdo->prepare('INSERT INTO alunos_conferencias(ano,alunos_json,fingerprint,confirmado_por) VALUES(?,?,?,?)
                ON CONFLICT(ano) DO UPDATE SET alunos_json=excluded.alunos_json, fingerprint=excluded.fingerprint,
                confirmado_por=excluded.confirmado_por, confirmado_em=CURRENT_TIMESTAMP')
                ->execute([$year, json_encode($reviewed, JSON_THROW_ON_ERROR), $fingerprint, $actor]);
            AuditLogger::recordRequired($pdo, 'student.senior_reviewed', AuditLogger::SUCCESS, $actor, null,
                sprintf('Progressão de %d: concluintes já encaminhados ao passivo; %d aluno(s) do terceiro permanecem ativos por reprovação.', $year, count($reviewed)), 'student_progression');
            return true;
        });
    }

    /** @return list<array<string,mixed>> */
    public function alunos(int $classId = 0, string $search = ''): array
    {
        $query = self::$pdo->prepare("SELECT a.id,a.nome_completo,a.id_turma,t.nome_turma,s.serie
            FROM alunos a LEFT JOIN turmas t ON t.id=a.id_turma LEFT JOIN turmas_series s ON s.id_turma=t.id
            WHERE a.ativo=1 AND (:turma=0 OR a.id_turma=:turma)
            AND a.nome_normalizado LIKE :nome ESCAPE '\\' ORDER BY a.nome_normalizado,a.id");
        $key = \src\Core\TextNormalizer::comparisonKey($search);
        $query->execute(['turma' => $classId, 'nome' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key) . '%']);
        return $query->fetchAll();
    }

    /** @param array<mixed> $ids
     * @return array<string,mixed>|false
     */
    public function preview(array $ids, int $destination, string $type, int $year): array|false
    {
        $this->lastErrorCode = null;
        try {
            return $this->prepare($ids, $destination, $type, $year);
        } catch (DomainException $e) {
            $this->lastErrorCode = $e->getMessage();
            return false;
        }
    }

    /** @param array<string,mixed> $plan */
    public function confirmar(array $plan, int $actor, string $token): int|false
    {
        return $this->write(function (PDO $pdo) use ($plan, $actor, $token): int|false {
            $this->admin($actor);
            $this->ensure(isset($plan['ids'], $plan['destino']['id'], $plan['tipo'], $plan['ano'], $plan['fingerprint'])
                && is_array($plan['ids']), 'invalid_selection');
            return RequestReceipt::run($pdo, $actor, 'student.move', $token, [$plan], function () use ($pdo, $plan, $actor): int {
                $current = $this->prepare($plan['ids'], (int) $plan['destino']['id'], (string) $plan['tipo'], (int) $plan['ano']);
                $this->ensure(hash_equals($current['fingerprint'], (string) $plan['fingerprint']), 'preview_changed');
                $pdo->prepare('INSERT INTO alunos_movimentacao_lotes(tipo,ano,realizado_por) VALUES(?,?,?)')
                    ->execute([$current['tipo'], $current['ano'], $actor]);
                $batch = (int) $pdo->lastInsertId();
                $insert = $pdo->prepare('INSERT INTO alunos_movimentacoes(lote_id,aluno_id,origem_id,destino_id,
                    origem_nome,destino_nome,origem_ano,destino_ano,origem_serie,destino_serie) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $update = $pdo->prepare('UPDATE alunos SET id_turma=?,atualizado_em=? WHERE id=? AND id_turma=? AND ativo=1');
                $target = $current['destino'];
                foreach ($current['alunos'] as $student) {
                    $update->execute([$target['id'], gmdate('Y-m-d H:i:s'), $student['id'], $student['id_turma']]);
                    $this->ensure($update->rowCount() === 1, 'preview_changed');
                    $insert->execute([$batch, $student['id'], $student['id_turma'], $target['id'],
                        $student['nome_turma'], $target['nome_turma'], $student['ano_letivo'], $target['ano_letivo'],
                        $student['serie'], $target['serie']]);
                    AuditLogger::recordRequired($pdo, $current['tipo'] === 'progressao' ? 'student.progressed' : 'student.regressed',
                        AuditLogger::SUCCESS, $actor, null,
                        sprintf('%s de %d: turma %d → %d; lote %d.', $current['tipo'], $current['ano'], $student['id_turma'], $target['id'], $batch),
                        'student', (int) $student['id']);
                }
                return $batch;
            });
        });
    }

    /** @return list<array<string,mixed>> */
    public function historico(int $student): array
    {
        $query = self::$pdo->prepare('SELECT m.*,l.tipo,l.ano,l.realizado_em,gse_name(u.nome,u.id) AS responsavel
            FROM alunos_movimentacoes m JOIN alunos_movimentacao_lotes l ON l.id=m.lote_id
            LEFT JOIN usuarios u ON u.id=l.realizado_por WHERE m.aluno_id=? ORDER BY m.id DESC');
        $query->execute([$student]);
        return $query->fetchAll();
    }

    /** @param array<mixed> $ids
     * @return array<string,mixed>
     */
    private function prepare(array $ids, int $destination, string $type, int $year, bool $annual = false): array
    {
        $this->ensure(in_array($type, ['progressao', 'regressao'], true), 'invalid_operation');
        $this->ensure($this->validYear($year), 'invalid_year');
        $this->ensure(array_is_list($ids) && count($ids) > 0 && count($ids) <= ($annual ? 50000 : self::MAX_ALUNOS), 'invalid_selection');
        $valid = [];
        foreach ($ids as $id) {
            $value = (is_string($id) || is_int($id)) ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $this->ensure($value !== false && !in_array($value, $valid, true), 'invalid_selection');
            $valid[] = $value;
        }
        sort($valid, SORT_NUMERIC);
        $target = null;
        foreach ($this->turmas() as $class) {
            if ((int) $class['id'] === $destination) {
                $target = $class;
            }
        }
        $this->ensure($target !== null && (int) $target['ativo'] === 1 && $target['serie'] !== null, 'invalid_class');
        $review = $this->conferencia($year);
        if ($type === 'progressao') {
            $this->ensure($annual ? $this->estadoAnual($year)['liberada'] : $review['liberada'], 'review_required');
        }
        $query = self::$pdo->prepare('SELECT a.id,a.nome_completo,a.id_turma,a.ativo,a.atualizado_em,
            t.nome_turma,t.ano_letivo,t.ativo AS turma_ativa,s.serie,
            COALESCE((SELECT SUM(CASE WHEN l.tipo=\'progressao\' THEN 1 ELSE -1 END)
                FROM alunos_movimentacoes m JOIN alunos_movimentacao_lotes l ON l.id=m.lote_id
                WHERE m.aluno_id=a.id AND l.ano=?),0) AS saldo,
            (SELECT MAX(m.id) FROM alunos_movimentacoes m WHERE m.aluno_id=a.id) AS ultima_movimentacao,
            EXISTS(SELECT 1 FROM alunos_passivo p WHERE p.aluno_origem_id=a.id AND p.ativo=1) AS no_passivo
            FROM alunos a LEFT JOIN turmas t ON t.id=a.id_turma LEFT JOIN turmas_series s ON s.id_turma=t.id
            WHERE a.id IN (' . implode(',', array_fill(0, count($valid), '?')) . ') ORDER BY a.id');
        $query->execute(array_merge([$year], $valid));
        $students = $query->fetchAll();
        $this->ensure(count($students) === count($valid), 'invalid_selection');
        $delta = $type === 'progressao' ? 1 : -1;
        foreach ($students as $student) {
            $this->ensure((int) $student['ativo'] === 1 && (int) $student['no_passivo'] === 0, 'inactive_student');
            $this->ensure($student['serie'] !== null && (int) $student['turma_ativa'] === 1
                && (int) $student['serie'] + $delta === (int) $target['serie'], 'invalid_series');
            $this->ensure($type !== 'progressao' || (int) $student['saldo'] < 1, 'already_progressed');
        }
        // Counts on the destination change after other pupils move; only its identity is relevant.
        unset($target['alunos_ativos']);
        $fingerprint = $this->fingerprint([$valid, $target, $type, $year, $students, $type === 'progressao' ? $review['liberada'] : null]);
        return ['ids' => $valid, 'alunos' => $students, 'destino' => $target, 'tipo' => $type, 'ano' => $year, 'fingerprint' => $fingerprint];
    }

    private function admin(int $actor): void
    {
        $query = self::$pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND ativo=1 AND tipo='administrador'");
        $query->execute([$actor]);
        $this->ensure($query->fetchColumn() !== false, 'invalid_actor');
    }

    private function validYear(int $year): bool
    {
        return $year >= 2000 && $year <= (int) date('Y') + 5;
    }

    private function ensure(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException($code);
        }
    }

    /** @param array<mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @template T
     * @param callable(PDO):T $operation
     * @return T|false
     */
    private function write(callable $operation): mixed
    {
        $this->lastErrorCode = null;
        try {
            return SqliteTransaction::immediate(self::$pdo, $operation);
        } catch (DomainException $e) {
            $this->lastErrorCode = $e->getMessage();
        } catch (Throwable $e) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('student_movement_failed', ['exception' => $e::class]);
        }
        return false;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function errorMessage(): string
    {
        return match ($this->lastErrorCode) {
            'review_required' => 'Conclua o envio dos concluintes do 3º ano ao Arquivo Passivo, desmarcando os reprovados, antes de progredir.',
            'year_completed' => 'A progressão geral deste ano já foi realizada. Corrija reprovados em Gestão de alunos.',
            'destination_required' => 'Escolha a turma de destino para cada turma de origem. Há mais de uma opção ou falta uma turma da série seguinte.',
            'no_students' => 'Não há alunos disponíveis para essa movimentação.',
            'unclassified_classes' => 'Identifique a série das turmas com alunos ativos. Alunos sem turma precisam ser vinculados a uma turma.',
            'invalid_selection' => 'Selecione de 1 a 200 alunos diferentes e válidos.',
            'invalid_class' => 'Escolha uma turma existente, ativa e com a série identificada.',
            'invalid_series' => 'A turma de destino deve corresponder a exatamente uma série acima ou abaixo, conforme a operação.',
            'inactive_student' => 'O lote contém aluno inativo ou já vinculado ao Arquivo Passivo. Refaça a seleção.',
            'already_progressed' => 'Um aluno selecionado já progrediu neste ano de referência. Confira seu histórico ou corrija com uma regressão.',
            'preview_changed' => 'Os dados mudaram desde a prévia. Confira os alunos e gere uma nova prévia.',
            'invalid_year' => 'Informe um ano de referência válido.',
            'invalid_actor' => 'Somente administradores ativos podem realizar esta operação.',
            default => 'Não foi possível concluir a operação. Nenhum aluno foi movimentado. Gere uma nova prévia.',
        };
    }
}
