<?php

declare(strict_types=1);

require_once __DIR__ . '/DataProtection.php';
require_once __DIR__ . '/SqliteTransaction.php';
require_once __DIR__ . '/AuditLogger.php';

/** Redacts optional operational data only; never deletes educational records. */
final class PrivacyRetention
{
    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function plan(PDO $pdo, array $policy): array
    {
        $policy = self::policy($policy);
        $where = 'ativo=0 AND inativado_em IS NOT NULL AND inativado_em<=?';
        $parameters = [$policy['inactive_before'] . ' 23:59:59'];
        $nonEmpty = [];
        if (in_array('student_contacts', $policy['categories'], true)) {
            $nonEmpty[] = "(COALESCE(trim(telefone_aluno),'')<>'' OR COALESCE(trim(telefone_responsavel),'')<>'')";
        }
        if (in_array('dva_notes', $policy['categories'], true)) {
            $nonEmpty[] = "EXISTS(SELECT 1 FROM dvas d WHERE d.id_aluno=alunos.id AND COALESCE(trim(d.observacao),'')<>'')";
        }
        $where .= ' AND (' . implode(' OR ', $nonEmpty) . ')';
        if ($policy['student_ids'] !== []) {
            $where .= ' AND id IN (' . implode(',', array_fill(0, count($policy['student_ids']), '?')) . ')';
            array_push($parameters, ...$policy['student_ids']);
        }
        if ($policy['hold_student_ids'] !== []) {
            $where .= ' AND id NOT IN (' . implode(',', array_fill(0, count($policy['hold_student_ids']), '?')) . ')';
            array_push($parameters, ...$policy['hold_student_ids']);
        }
        $statement = $pdo->prepare('SELECT id, nome_completo, nome_normalizado, data_nascimento, id_turma,
            revisao, ativo, inativado_em, telefone_aluno, telefone_responsavel FROM alunos WHERE ' . $where . ' ORDER BY id LIMIT 1001');
        $statement->execute($parameters);
        $rows = [];
        $key = DataProtection::keyring()['index'];
        foreach ($statement as $row) {
            if (count($rows) >= 1000) { throw new DomainException('Mais de 1.000 registros: divida a política em lotes com data de corte mais restrita.'); }
            $context = hash_init('sha256', HASH_HMAC, $key);
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR));
            $notes = $pdo->prepare('SELECT id, data_vencimento, ativo, observacao, atualizado_em FROM dvas WHERE id_aluno=? ORDER BY id');
            $notes->execute([(int) $row['id']]);
            foreach ($notes as $note) { hash_update($context, json_encode($note, JSON_THROW_ON_ERROR)); }
            $rows[] = ['id' => (int) $row['id'], 'fingerprint' => hash_final($context)];
        }
        $plan = ['version' => 2, 'database_binding' => self::databaseBinding($pdo), 'created_at' => time(), 'policy' => $policy, 'students' => $rows];
        $plan['confirmation'] = self::signature($plan);
        return $plan;
    }

    /** @param array<string,mixed> $plan */
    public static function apply(PDO $pdo, array $plan, string $confirmation, int $actor, int $reviewer): int
    {
        $unsigned = $plan;
        unset($unsigned['confirmation']);
        if (!is_string($plan['confirmation'] ?? null) || !hash_equals(self::signature($unsigned), $plan['confirmation'])
            || !hash_equals($plan['confirmation'], $confirmation) || ($plan['version'] ?? null) !== 2
            || !is_string($plan['database_binding'] ?? null) || !hash_equals(self::databaseBinding($pdo), $plan['database_binding'])
            || !is_int($plan['created_at'] ?? null) || $plan['created_at'] > time() || $plan['created_at'] < time() - 86400
            || !is_array($plan['policy'] ?? null) || !is_array($plan['students'] ?? null) || $actor < 1 || $actor === $reviewer) {
            throw new DomainException('Plano inválido, expirado ou sem dupla conferência. Gere outra prévia.');
        }
        return \src\Core\SqliteTransaction::immediate($pdo, static function (PDO $pdo) use ($plan, $actor, $reviewer): int {
            $admins = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id IN (?,?) AND ativo=1 AND tipo='administrador'");
            $admins->execute([$actor, $reviewer]);
            if ((int) $admins->fetchColumn() !== 2) { throw new DomainException('A execução exige dois administradores ativos distintos.'); }
            $current = self::plan($pdo, $plan['policy']);
            if ($current['students'] !== $plan['students']) { throw new DomainException('Os registros mudaram após a prévia. Nenhum dado foi alterado.'); }
            $categories = $current['policy']['categories'];
            $contacts = $pdo->prepare("UPDATE alunos SET telefone_aluno='', telefone_responsavel='', revisao=revisao+1, atualizado_em=? WHERE id=? AND ativo=0");
            $notes = $pdo->prepare("UPDATE dvas SET observacao=NULL WHERE id_aluno=?");
            foreach ($current['students'] as $student) {
                if (in_array('student_contacts', $categories, true)) { $contacts->execute([gmdate('Y-m-d H:i:s'), $student['id']]); }
                if (in_array('dva_notes', $categories, true)) { $notes->execute([$student['id']]); }
            }
            $count = count($current['students']);
            AuditLogger::recordRequired($pdo, 'privacy.operational_data_redacted', AuditLogger::SUCCESS, $actor, $reviewer,
                'Política=' . $current['policy']['reference'] . '; categorias=' . implode(',', $categories) . '; alunos=' . $count,
                'privacy', null);
            return $count;
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{reference:string,approval_reference:string,review_reference:string,legal_basis:string,inactive_before:string,categories:list<string>,hold_student_ids:list<int>,student_ids:list<int>}
     */
    private static function policy(array $input): array
    {
        $allowed = ['reference','approval_reference','review_reference','legal_basis','inactive_before','categories','hold_student_ids','student_ids'];
        if (array_diff(array_keys($input), $allowed) !== []) { throw new DomainException('A política contém campos desconhecidos. Confira o modelo antes de gerar a prévia.'); }
        foreach (['reference', 'approval_reference', 'review_reference', 'legal_basis'] as $key) {
            if (!is_string($input[$key] ?? null) || trim($input[$key]) === '' || strlen($input[$key]) > 300 || preg_match('/[\x00-\x1f]/', $input[$key])) {
                throw new DomainException('Política institucional incompleta: informe fundamento, aprovação e revisão independentes.');
            }
        }
        if ($input['approval_reference'] === $input['review_reference']) { throw new DomainException('A revisão deve ter referência independente da aprovação.'); }
        $cutoff = $input['inactive_before'] ?? '';
        $date = is_string($cutoff) ? DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff) : false;
        if ($date === false || $date->format('Y-m-d') !== $cutoff || $cutoff > gmdate('Y-m-d')) { throw new DomainException('Data de corte inválida.'); }
        $categories = $input['categories'] ?? [];
        if (!is_array($categories) || !array_is_list($categories) || $categories === []) { throw new DomainException('Selecione categorias operacionais aprovadas.'); }
        foreach ($categories as $category) {
            if (!in_array($category, ['student_contacts', 'dva_notes'], true)) { throw new DomainException('Não é permitido eliminar identidade, DVA documental, vínculo ou arquivo escolar por esta rotina.'); }
        }
        $holds = $input['hold_student_ids'] ?? [];
        if (!is_array($holds) || !array_is_list($holds) || count($holds) > 1000) { throw new DomainException('Lista de impedimentos inválida: limite de 1.000 identificadores.'); }
        foreach ($holds as $id) { if (!is_int($id) || $id < 1) { throw new DomainException('Impedimento inválido.'); } }
        $selected = $input['student_ids'] ?? [];
        if (!is_array($selected) || !array_is_list($selected) || count($selected) > 1000) { throw new DomainException('Seleção inválida: limite de 1.000 identificadores únicos.'); }
        foreach ($selected as $id) { if (!is_int($id) || $id < 1) { throw new DomainException('Identificador selecionado inválido.'); } }
        if (count(array_unique($selected)) !== count($selected)) { throw new DomainException('A seleção não pode repetir identificadores.'); }
        sort($selected);
        $categories = array_values(array_unique($categories)); sort($categories);
        $holds = array_values(array_unique($holds)); sort($holds);
        return ['reference' => $input['reference'], 'approval_reference' => $input['approval_reference'], 'review_reference' => $input['review_reference'],
            'legal_basis' => $input['legal_basis'], 'inactive_before' => $cutoff, 'categories' => $categories, 'hold_student_ids' => $holds, 'student_ids' => $selected];
    }

    /** @param array<string,mixed> $value */
    private static function signature(array $value): string
    {
        return hash_hmac('sha256', 'privacy-retention-v2:' . json_encode($value, JSON_THROW_ON_ERROR), DataProtection::keyring()['index']);
    }

    private static function databaseBinding(PDO $pdo): string
    {
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if ($database['name'] !== 'main') { continue; }
            $path = realpath((string) $database['file']);
            if ($path === false || !is_file($path)) { break; }
            $path = str_replace('\\', '/', $path);
            if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); }
            return hash_hmac('sha256', 'privacy-database:' . $path, DataProtection::keyring()['index']);
        }
        throw new DomainException('A prévia exige uma base persistente identificável.');
    }
}
