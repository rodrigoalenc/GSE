<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/CertidaoStatus.php';
require_once ROOT_PATH . '/src/Services/CertidaoStorage.php';

use src\Core\SqliteTransaction;
use src\Core\TextNormalizer;

final class Certidao extends Model
{
    public const STATE_SQL = "CASE WHEN c.excluido_em IS NOT NULL THEN 'excluida' WHEN COALESCE(c.arquivado,0) = 1 OR COALESCE(c.status,1) = 0 THEN 'arquivada' ELSE 'corrente' END";
    private readonly CertidaoStatus $dates;

    public function __construct(?CertidaoStatus $dates = null)
    {
        parent::__construct();
        $this->dates = $dates ?? new CertidaoStatus();
        self::$pdo->sqliteCreateFunction('cert_status', fn (?string $date): string => $this->dates->classify($date), 1);
    }

    public static function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) { throw new DomainException('Identificador inválido.'); }
        return $id;
    }

    private function table(string $kind): string
    {
        return match ($kind) {
            'fornecedor' => 'lista_fornecedores', 'tipo' => 'lista_tipos_certidao',
            default => throw new DomainException('Lista inválida.'),
        };
    }

    /**
     * @return list<array<string,mixed>> */
    public function options(string $kind, string $search = '', bool $activeOnly = false): array
    {
        $table = $this->table($kind);
        $rows = self::$pdo->query('SELECT * FROM ' . $table . ($activeOnly ? ' WHERE ativo = 1' : '') . ' ORDER BY nome, id')->fetchAll();
        $key = TextNormalizer::comparisonKey(mb_substr($search, 0, 150));
        return array_values(array_filter($rows, static fn (array $row): bool => $key === '' || str_contains(TextNormalizer::comparisonKey((string) $row['nome']), $key)));
    }

    public function saveOption(string $kind, ?int $id, string $name, bool $active, int $actor): int
    {
        $table = $this->table($kind);
        $name = TextNormalizer::displayName($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || preg_match('/[\p{C}]/u', $name)) { throw new DomainException('Informe um nome de 2 a 150 caracteres.'); }
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($kind, $table, $id, $name, $active, $actor): int {
            $this->actor($actor);
            $found = $id === null;
            foreach ($pdo->query('SELECT id, nome FROM ' . $table)->fetchAll() as $row) {
                if ((int) $row['id'] === $id) { $found = true; continue; }
                if (TextNormalizer::comparisonKey((string) $row['nome']) === TextNormalizer::comparisonKey($name)) { throw new DomainException('Já existe um nome equivalente nesta lista, inclusive entre inativos.'); }
            }
            if (!$found) { throw new DomainException('Opção não encontrada.'); }
            $params = [$name, (int) $active, $actor, gmdate('Y-m-d H:i:s')];
            if ($id === null) {
                $q = $pdo->prepare('INSERT INTO ' . $table . ' (nome, ativo, atualizado_por, atualizado_em) VALUES (?,?,?,?)');
                $q->execute($params);
                $id = (int) $pdo->lastInsertId();
                $action = 'created';
            } else {
                $q = $pdo->prepare('UPDATE ' . $table . ' SET nome=?, ativo=?, atualizado_por=?, atualizado_em=? WHERE id=?');
                $q->execute([...$params, $id]);
                $action = $active ? 'updated' : 'deactivated';
            }
            $this->audit($actor, $kind, $id, $action);
            return $id;
        });
    }

    /**
     * @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        $q = self::$pdo->prepare('SELECT c.*, f.nome AS fornecedor, t.nome AS tipo_certidao, ' . self::STATE_SQL . ' AS estado, cert_status(c.data_vencimento) AS validade FROM certidoes c JOIN lista_fornecedores f ON f.id=c.id_fornecedor JOIN lista_tipos_certidao t ON t.id=c.id_tipo_certidao WHERE c.id=?');
        $q->execute([$id]);
        return $q->fetch();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $state = in_array($filters['estado'] ?? '', ['corrente','arquivada','excluida'], true) ? $filters['estado'] : 'corrente';
        $where = [self::STATE_SQL . ' = ?'];
        $params = [$state];
        foreach (['fornecedor' => 'c.id_fornecedor', 'tipo' => 'c.id_tipo_certidao'] as $key => $column) {
            if (!empty($filters[$key])) { $where[] = $column . ' = ?'; $params[] = self::id($filters[$key]); }
        }
        if (!empty($filters['ano']) && $filters['ano'] !== 'todos') {
            if (preg_match('/^[1-9][0-9]{3}$/D', (string) $filters['ano']) !== 1) { throw new DomainException('Ano inválido.'); }
            $where[] = 'substr(c.data_vencimento,1,4) = ?'; $params[] = $filters['ano'];
        }
        if (!empty($filters['validade'])) {
            if (!isset(CertidaoStatus::LABELS[(string) $filters['validade']])) { throw new DomainException('Situação inválida.'); }
            $where[] = 'cert_status(c.data_vencimento) = ?'; $params[] = $filters['validade'];
        }
        if (!empty($filters['busca'])) {
            $where[] = '(f.nome LIKE ? OR t.nome LIKE ? OR CAST(c.id AS TEXT) = ?)';
            $search = mb_substr((string) $filters['busca'], 0, 150);
            array_push($params, '%' . $search . '%', '%' . $search . '%', $search);
        }
        $from = ' FROM certidoes c JOIN lista_fornecedores f ON f.id=c.id_fornecedor JOIN lista_tipos_certidao t ON t.id=c.id_tipo_certidao WHERE ' . implode(' AND ', $where);
        $q = self::$pdo->prepare('SELECT COUNT(*)' . $from); $q->execute($params);
        $total = (int) $q->fetchColumn();
        $perPage = max(1, min(50, $perPage)); $pages = max(1, (int) ceil($total / $perPage)); $page = max(1, min($page, $pages));
        $q = self::$pdo->prepare('SELECT c.*, f.nome AS fornecedor, t.nome AS tipo_certidao, cert_status(c.data_vencimento) AS validade' . $from . ' ORDER BY f.nome, c.data_vencimento, c.id LIMIT ' . $perPage . ' OFFSET ' . (($page-1)*$perPage));
        $q->execute($params);
        return ['items' => $q->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @return array<string,int> */
    public function summary(): array
    {
        $result = array_fill_keys(array_keys(CertidaoStatus::LABELS), 0);
        foreach (self::$pdo->query('SELECT cert_status(c.data_vencimento) AS validade, COUNT(*) AS total FROM certidoes c WHERE ' . self::STATE_SQL . " = 'corrente' GROUP BY validade")->fetchAll() as $row) { $result[(string) $row['validade']] = (int) $row['total']; }
        return $result;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $upload */
    public function create(array $data, array $upload, int $actor, ?int $previous = null, ?CertidaoStorage $storage = null): int
    {
        $storage ??= new CertidaoStorage();
        return $storage->locked(function () use ($data, $upload, $actor, $previous, $storage): int {
            $pdf = $storage->receive($upload);
            try { return $this->createStored($data, $pdf, $actor, $previous, $storage); }
            catch (Throwable $exception) { $storage->compensate($pdf['key'], self::$pdo); throw $exception; }
        });
    }

    /** Internal boundary for CLI and synthetic tests. Caller holds the storage lock.
     *
     * @param array<string,mixed> $data
     * @param array{key:string,name:string,bytes:int,hash:string} $pdf
     */
    public function createStored(array $data, array $pdf, int $actor, ?int $previous, CertidaoStorage $storage): int
    {
        $path = $storage->path($pdf['key']);
        if (hash_file('sha256', $path) !== $pdf['hash'] || filesize($path) !== $pdf['bytes']) { throw new DomainException('O PDF mudou durante a gravação.'); }
        return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($data, $pdf, $actor, $previous): int {
            $this->actor($actor); $values = $this->validate($data);
            if ($previous !== null) {
                $old = $this->buscarPorId($previous);
                if (!$old || $old['estado'] !== 'corrente' || (int) $old['id_fornecedor'] !== $values[0] || (int) $old['id_tipo_certidao'] !== $values[1]
                    || self::id($data['revisao'] ?? null) !== (int) $old['revisao']) { throw new DomainException('A certidão anterior mudou ou não é compatível com esta renovação.'); }
                $q = $pdo->prepare('UPDATE certidoes SET arquivado=1, status=0, revisao=revisao+1, atualizado_por=?, atualizado_em=? WHERE id=?');
                $q->execute([$actor, gmdate('Y-m-d H:i:s'), $previous]);
                $this->audit($actor, 'certidao', $previous, 'archived_by_renewal');
            }
            $q = $pdo->prepare('INSERT INTO certidoes (id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,observacao,pdf_privado,pdf_nome,pdf_bytes,pdf_sha256,anterior_id,criado_por,atualizado_por,criado_em,atualizado_em,arquivado,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,1)');
            $now = gmdate('Y-m-d H:i:s');
            $q->execute([...$values, $pdf['key'], $pdf['name'], $pdf['bytes'], $pdf['hash'], $previous, $actor, $actor, $now, $now]);
            $id = (int) $pdo->lastInsertId(); $this->audit($actor, 'certidao', $id, $previous === null ? 'created' : 'renewed'); return $id;
        });
    }

    /**
     * @param array<string,mixed> $data */
    public function updateMetadata(int $id, array $data, int $actor): void
    {
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $data, $actor): void {
            $this->actor($actor); $old = $this->buscarPorId($id);
            if (!$old || $old['estado'] !== 'corrente' || self::id($data['revisao'] ?? null) !== (int) $old['revisao']) { throw new DomainException('O registro mudou ou não está corrente. Atualize a página.'); }
            $values = $this->validate($data, $old);
            if ($old['anterior_id'] !== null && ($values[0] !== (int) $old['id_fornecedor'] || $values[1] !== (int) $old['id_tipo_certidao'])) { throw new DomainException('A renovação deve manter fornecedor e tipo do histórico.'); }
            $q = $pdo->prepare('UPDATE certidoes SET id_fornecedor=?,id_tipo_certidao=?,data_emissao=?,data_vencimento=?,observacao=?,atualizado_por=?,atualizado_em=?,revisao=revisao+1 WHERE id=?');
            $q->execute([...$values, $actor, gmdate('Y-m-d H:i:s'), $id]); $this->audit($actor, 'certidao', $id, 'updated');
        });
    }

    public function transition(int $id, string $action, int $revision, int $actor): void
    {
        SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $action, $revision, $actor): void {
            $this->actor($actor); $old = $this->buscarPorId($id);
            if (!$old || (int) $old['revisao'] !== $revision || $old['estado'] === 'excluida' || !in_array($action, ['archive','delete'], true)
                || ($action === 'archive' && $old['estado'] !== 'corrente')) { throw new DomainException('O registro mudou ou a ação não é permitida.'); }
            $q = $pdo->prepare('UPDATE certidoes SET arquivado=1,status=0,excluido_em=?,atualizado_por=?,atualizado_em=?,revisao=revisao+1 WHERE id=?');
            $now = gmdate('Y-m-d H:i:s'); $q->execute([$action === 'delete' ? $now : null, $actor, $now, $id]);
            $this->audit($actor, 'certidao', $id, $action === 'delete' ? 'deleted' : 'archived');
        });
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $old
     * @return array{int,int,string,string,string} */
    private function validate(array $data, ?array $old = null): array
    {
        $f = self::id($data['id_fornecedor'] ?? null); $t = self::id($data['id_tipo_certidao'] ?? null);
        foreach (['lista_fornecedores' => [$f, 'id_fornecedor'], 'lista_tipos_certidao' => [$t, 'id_tipo_certidao']] as $table => [$id, $field]) {
            $q = self::$pdo->prepare('SELECT ativo FROM ' . $table . ' WHERE id=?'); $q->execute([$id]); $active = $q->fetchColumn();
            if ($active === false || ((int) $active !== 1 && ($old === null || (int) $old[$field] !== $id))) { throw new DomainException('Selecione fornecedor e tipo ativos.'); }
        }
        $emission = is_string($data['data_emissao'] ?? null) ? $data['data_emissao'] : '';
        $expiry = is_string($data['data_vencimento'] ?? null) ? $data['data_vencimento'] : '';
        $notes = is_string($data['observacao'] ?? null) ? trim($data['observacao']) : '';
        if (!CertidaoStatus::validDate($emission) || !CertidaoStatus::validDate($expiry) || $expiry < $emission) { throw new DomainException('Informe datas reais; o vencimento não pode anteceder a emissão.'); }
        if (!mb_check_encoding($notes, 'UTF-8') || mb_strlen($notes) > 2000) { throw new DomainException('Observação inválida ou acima de 2.000 caracteres.'); }
        return [$f, $t, $emission, $expiry, $notes];
    }

    private function actor(int $id): void
    {
        $q = self::$pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND ativo=1 AND tipo IN ('administrador','funcionario')"); $q->execute([$id]);
        if ($q->fetchColumn() === false) { throw new DomainException('Usuário sem autorização.'); }
    }

    private function audit(int $actor, string $resource, int $id, string $action): void
    {
        AuditLogger::recordRequired(self::$pdo, $resource . '.' . $action, AuditLogger::SUCCESS, $actor, null, 'Operação de certidões e fornecedores.', $resource, $id);
    }

    /**
     * @return list<array{resource:string,id:int,issue:string}> */
    public function diagnostics(): array
    {
        $issues = [];
        foreach (['fornecedor','tipo'] as $kind) {
            $seen = [];
            foreach ($this->options($kind) as $row) {
                $key = TextNormalizer::comparisonKey((string) $row['nome']);
                if (isset($seen[$key])) { $issues[] = ['resource'=>$kind,'id'=>(int)$row['id'],'issue'=>'Nome Unicode equivalente; não mesclado.']; }
                $seen[$key] = true;
            }
        }
        foreach (self::$pdo->query('SELECT * FROM certidoes')->fetchAll() as $row) {
            $problems = [];
            if (!CertidaoStatus::validDate((string)$row['data_emissao']) || !CertidaoStatus::validDate((string)$row['data_vencimento']) || $row['data_vencimento'] < $row['data_emissao']) { $problems[] = 'Datas inválidas'; }
            if (empty($row['pdf_privado'])) { $problems[] = empty($row['arquivo_pdf']) ? 'PDF pendente' : 'PDF legado requer migração privada'; }
            if (!in_array([$row['arquivado'], $row['status']], [[0,1],[1,0],[null,null]], true)) { $problems[] = 'Flags legadas ambíguas preservadas'; }
            foreach ($problems as $problem) { $issues[] = ['resource'=>'certidao','id'=>(int)$row['id'],'issue'=>$problem]; }
        }
        return $issues;
    }
}
