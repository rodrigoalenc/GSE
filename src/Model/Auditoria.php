<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/AuditPresentation.php';

final class Auditoria extends Model
{
    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        [$where, $params] = $this->where($filters);
        $count = self::$pdo->prepare("SELECT COUNT(*) FROM security_audit audit {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $statement = self::$pdo->prepare(
            "SELECT audit.id, audit.occurred_at, audit.action, audit.result, audit.actor_user_id,
                    audit.target_user_id, audit.resource_type, audit.resource_id, audit.ip_address,
                    audit.request_id, audit.description, gse_name(actor.nome, actor.id) AS actor_name,
                    gse_name(target.nome, target.id) AS target_name
             FROM security_audit audit
             LEFT JOIN usuarios actor ON actor.id = audit.actor_user_id
             LEFT JOIN usuarios target ON target.id = audit.target_user_id
             {$where}
             ORDER BY audit.occurred_at DESC, audit.id DESC LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{actions:array<string,string>,resources:array<string,string>} */
    public function filterOptions(): array
    {
        $actions = AuditPresentation::actionLabels();
        $resources = AuditPresentation::resourceLabels();

        // Mantém eventos históricos e novos códigos disponíveis para consulta.
        foreach (self::$pdo->query('SELECT DISTINCT action FROM security_audit')->fetchAll(PDO::FETCH_COLUMN) as $action) {
            $action = (string) $action;
            if (!isset($actions[$action])) {
                $actions[$action] = AuditPresentation::actionLabel($action) . ' (' . $action . ')';
            }
        }

        foreach (self::$pdo->query('SELECT DISTINCT resource_type FROM security_audit WHERE resource_type IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $resource) {
            $resource = (string) $resource;
            if (!isset($resources[$resource])) {
                $resources[$resource] = AuditPresentation::resourceLabel($resource) . ' (' . $resource . ')';
            }
        }

        $collator = new Collator('pt_BR');
        $collator->asort($actions);
        $collator->asort($resources);

        return ['actions' => $actions, 'resources' => $resources];
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 500): array
    {
        $statement = self::$pdo->prepare(
            "WITH recent AS (
                SELECT * FROM security_audit ORDER BY occurred_at DESC, id DESC LIMIT :limit
             )
             SELECT audit.*, gse_name(actor.nome, actor.id) AS actor_name,
                    gse_name(target.nome, target.id) AS target_name,
                    CASE audit.resource_type
                        WHEN 'student' THEN student.nome_completo
                        WHEN 'dva' THEN dva_student.nome_completo
                        WHEN 'class' THEN school_class.nome_turma
                        WHEN 'user' THEN gse_name(resource_user.nome, resource_user.id)
                        WHEN 'passive_record' THEN passive.nome_completo
                        WHEN 'certidao' THEN document_type.nome || ' - ' || supplier.nome
                        WHEN 'fornecedor' THEN option_supplier.nome
                        WHEN 'tipo' THEN option_type.nome
                        WHEN 'contrato' THEN contract.titulo
                        WHEN 'produto' THEN product.nome_produto
                    END AS resource_name,
                    passive.caixa AS resource_box,
                    COALESCE(sheet.id_pedido, product.id_pedido) AS resource_parent_id,
                    COALESCE(sheet.numero_pagina, product.numero_pagina) AS resource_number,
                    document.id_fornecedor AS resource_supplier_id,
                    document.id_tipo_certidao AS resource_document_type_id
             FROM recent audit
             LEFT JOIN usuarios actor ON actor.id = audit.actor_user_id
             LEFT JOIN usuarios target ON target.id = audit.target_user_id
             LEFT JOIN alunos student ON audit.resource_type = 'student' AND student.id = audit.resource_id
             LEFT JOIN dvas dva ON audit.resource_type = 'dva' AND dva.id = audit.resource_id
             LEFT JOIN alunos dva_student ON dva_student.id = dva.id_aluno
             LEFT JOIN turmas school_class ON audit.resource_type = 'class' AND school_class.id = audit.resource_id
             LEFT JOIN usuarios resource_user ON audit.resource_type = 'user' AND resource_user.id = audit.resource_id
             LEFT JOIN alunos_passivo passive ON audit.resource_type = 'passive_record' AND passive.id = audit.resource_id
             LEFT JOIN certidoes document ON audit.resource_type = 'certidao' AND document.id = audit.resource_id
             LEFT JOIN lista_tipos_certidao document_type ON document_type.id = document.id_tipo_certidao
             LEFT JOIN lista_fornecedores supplier ON supplier.id = document.id_fornecedor
             LEFT JOIN lista_fornecedores option_supplier ON audit.resource_type = 'fornecedor' AND option_supplier.id = audit.resource_id
             LEFT JOIN lista_tipos_certidao option_type ON audit.resource_type = 'tipo' AND option_type.id = audit.resource_id
             LEFT JOIN pedidos contract ON audit.resource_type = 'contrato' AND contract.id = audit.resource_id
             LEFT JOIN pedido_paginas sheet ON audit.resource_type = 'folha' AND sheet.id = audit.resource_id
             LEFT JOIN pedido_produtos product ON audit.resource_type = 'produto' AND product.id = audit.resource_id
             ORDER BY audit.occurred_at DESC, audit.id DESC"
        );
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public static function manualRetentionDays(): int
    {
        return max(365, Config::int('AUDIT_RETENTION_DAYS', 365, 90, 3650));
    }

    public function clearOld(int $actorId, ?int $now = null, ?int $sessionVersion = null): int
    {
        $now ??= time();

        return src\Core\SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($actorId, $now, $sessionVersion): int {
            $actor = $pdo->prepare("SELECT session_version FROM usuarios WHERE id = ? AND ativo = 1 AND tipo = 'administrador'");
            $actor->execute([$actorId]);
            $currentVersion = $actor->fetchColumn();
            if ($currentVersion === false || ($sessionVersion !== null && (int) $currentVersion !== $sessionVersion)) {
                throw new DomainException('Somente administradores ativos podem limpar os logs.');
            }

            $days = self::manualRetentionDays();
            $statement = $pdo->prepare('DELETE FROM security_audit WHERE occurred_at < :cutoff');
            $statement->execute(['cutoff' => gmdate('Y-m-d', $now - ($days * 86400)) . ' 00:00:00']);
            $deleted = $statement->rowCount();
            AuditLogger::recordRequired($pdo, 'audit.cleaned', AuditLogger::SUCCESS, $actorId, null,
                'Realizou limpeza de logs (>' . $days . ' dias).', 'audit');

            return $deleted;
        });
    }

    public function cleanup(int $retentionDays, ?int $now = null): int
    {
        $retentionDays = max(90, min(3650, $retentionDays));
        $now ??= time();
        $statement = self::$pdo->prepare('DELETE FROM security_audit WHERE occurred_at < :cutoff');
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', $now - ($retentionDays * 86400))]);

        return $statement->rowCount();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        $action = trim((string) ($filters['action'] ?? ''));
        $result = trim((string) ($filters['result'] ?? ''));
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $resourceType = trim((string) ($filters['resource_type'] ?? ''));

        if ($action !== '' && preg_match('/^[a-z0-9_.-]{1,80}$/i', $action) === 1) {
            $conditions[] = 'audit.action = :action';
            $params['action'] = $action;
        }

        if (in_array($result, ['success', 'failure', 'blocked'], true)) {
            $conditions[] = 'audit.result = :result';
            $params['result'] = $result;
        }

        if ($resourceType !== '' && preg_match('/^[a-z0-9_.-]{1,50}$/i', $resourceType) === 1) {
            $conditions[] = 'audit.resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }

        if (self::validDate($from)) {
            $conditions[] = 'audit.occurred_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }

        if (self::validDate($to)) {
            $conditions[] = 'audit.occurred_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
