<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Model/Usuario.php';

use src\Core\SqliteTransaction;

/**
 * @phpstan-type Aviso array{id:int,usuario_id:?int,data_aviso:string,titulo:string,descricao:string,tipo:?string,criado_em:string,revisao:int,autor_nome:?string}
 * @phpstan-type Evento array{data_aviso:string,titulo:string,descricao:string,tipo:string}
 */
final class Agenda extends Model
{
    public const TITULO_MAX = 120;
    public const DESCRICAO_MAX = 1000;
    public const PREFIXO_IMPORTACAO = '[Calendário] ';

    private const CONSULTA = 'SELECT a.id, a.usuario_id, a.data_aviso, a.titulo, a.descricao,
                                     a.tipo, a.criado_em, a.revisao, gse_name(u.nome, u.id) AS autor_nome
                              FROM agenda_avisos a LEFT JOIN usuarios u ON u.id = a.usuario_id
                              WHERE a.excluido_em IS NULL';

    /** @return list<Aviso> */
    public function listar(string $inicio, string $fim): array
    {
        $query = self::getConexao()->prepare(self::CONSULTA . '
            AND a.data_aviso BETWEEN ? AND ? ORDER BY a.data_aviso, a.criado_em DESC, a.id DESC');
        $query->execute([$inicio, $fim]);

        return $query->fetchAll();
    }

    public static function dataValida(string $data): bool
    {
        return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $data, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public function cadastrar(int $atorId, string $data, string $titulo, string $descricao): ?int
    {
        [$titulo, $descricao] = self::validarCampos($data, $titulo, $descricao);

        return SqliteTransaction::immediate(self::getConexao(), function (PDO $pdo) use ($atorId, $data, $titulo, $descricao): ?int {
            $this->perfilAtivo($pdo, $atorId);
            $duplicado = $pdo->prepare(
                "SELECT 1 FROM agenda_avisos
                 WHERE usuario_id = ? AND data_aviso = ? AND titulo = ? AND descricao = ? AND tipo IS NULL
                   AND excluido_em IS NULL AND criado_em >= datetime('now', '-2 minutes') LIMIT 1"
            );
            $duplicado->execute([$atorId, $data, $titulo, $descricao]);
            if ($duplicado->fetchColumn() !== false) {
                return null;
            }

            $id = $this->inserir($pdo, $atorId, $data, $titulo, $descricao, null);
            AuditLogger::recordRequired($pdo, 'agenda.notice_created', AuditLogger::SUCCESS, $atorId, $atorId,
                'Aviso cadastrado para ' . $data . '.', 'agenda_notice', $id);

            return $id;
        });
    }

    /** @return Aviso */
    public function buscarParaEdicao(int $atorId, int $id): array
    {
        return $this->buscarParaAlteracao(self::getConexao(), $atorId, $id);
    }

    public function editar(int $atorId, int $id, int $revisao, string $data, string $titulo, string $descricao): bool
    {
        return SqliteTransaction::immediate(self::getConexao(), function (PDO $pdo) use ($atorId, $id, $revisao, $data, $titulo, $descricao): bool {
            $aviso = $this->buscarParaAlteracao($pdo, $atorId, $id);
            $this->conferirRevisao($aviso, $revisao);
            [$titulo, $descricao] = self::validarCampos($data, $titulo, $descricao);
            if ($data === $aviso['data_aviso'] && $titulo === $aviso['titulo'] && $descricao === $aviso['descricao']) {
                return false;
            }

            $update = $pdo->prepare('UPDATE agenda_avisos SET data_aviso = ?, titulo = ?, descricao = ?, revisao = revisao + 1 WHERE id = ?');
            $update->execute([$data, $titulo, $descricao, $id]);
            AuditLogger::recordRequired($pdo, 'agenda.notice_updated', AuditLogger::SUCCESS, $atorId, $aviso['usuario_id'],
                'Aviso atualizado para ' . $data . '.', 'agenda_notice', $id);

            return true;
        });
    }

    public function excluir(int $atorId, int $id, int $revisao): void
    {
        SqliteTransaction::immediate(self::getConexao(), function (PDO $pdo) use ($atorId, $id, $revisao): void {
            $aviso = $this->buscarParaAlteracao($pdo, $atorId, $id);
            $this->conferirRevisao($aviso, $revisao);
            $update = $pdo->prepare('UPDATE agenda_avisos SET excluido_em = CURRENT_TIMESTAMP, revisao = revisao + 1 WHERE id = ?');
            $update->execute([$id]);
            AuditLogger::recordRequired($pdo, 'agenda.notice_deleted', AuditLogger::SUCCESS, $atorId, $aviso['usuario_id'],
                'Aviso excluído da agenda.', 'agenda_notice', $id);
        });
    }

    /**
     * @param list<Evento> $eventos
     * @return array{salvos:int,ignorados:int}
     */
    public function importar(int $atorId, array $eventos): array
    {
        return SqliteTransaction::immediate(self::getConexao(), function (PDO $pdo) use ($atorId, $eventos): array {
            if ($this->perfilAtivo($pdo, $atorId) !== Usuario::PERFIL_ADMINISTRADOR) {
                throw new DomainException('Somente administradores podem importar o calendário escolar.', 403);
            }

            $salvos = 0;
            $duplicado = $pdo->prepare('SELECT 1 FROM agenda_avisos WHERE data_aviso = ? AND titulo = ? AND excluido_em IS NULL LIMIT 1');
            foreach ($eventos as $evento) {
                $data = $evento['data_aviso'];
                [$titulo, $descricao] = self::validarCampos($data, self::PREFIXO_IMPORTACAO . $evento['titulo'], $evento['descricao']);
                $duplicado->execute([$data, $titulo]);
                if ($duplicado->fetchColumn() !== false) {
                    continue;
                }

                $this->inserir($pdo, $atorId, $data, $titulo, $descricao, $evento['tipo']);
                $salvos++;
            }

            $ignorados = count($eventos) - $salvos;
            AuditLogger::recordRequired($pdo, 'agenda.calendar_imported', AuditLogger::SUCCESS, $atorId, null,
                sprintf('Calendário escolar confirmado. Itens salvos: %d. Itens já cadastrados: %d.', $salvos, $ignorados), 'agenda_notice');

            return ['salvos' => $salvos, 'ignorados' => $ignorados];
        });
    }

    /** @return array{string,string} */
    private static function validarCampos(string $data, string $titulo, string $descricao): array
    {
        if (!self::dataValida($data)) {
            throw new DomainException('Informe uma data válida para o aviso.', 422);
        }
        $titulo = preg_replace('/\A\s+|\s+\z/u', '', $titulo) ?? '';
        if ($titulo === '' || mb_strlen($titulo, 'UTF-8') > self::TITULO_MAX || preg_match('/\p{Cc}/u', $titulo) !== 0) {
            throw new DomainException('Informe um título com até ' . self::TITULO_MAX . ' caracteres, em uma única linha.', 422);
        }
        $descricao = str_replace(["\r\n", "\r"], "\n", $descricao);
        if (!mb_check_encoding($descricao, 'UTF-8') || mb_strlen($descricao, 'UTF-8') > self::DESCRICAO_MAX
            || preg_match('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', $descricao) !== 0) {
            throw new DomainException('Informe uma descrição com até ' . self::DESCRICAO_MAX . ' caracteres e sem caracteres de controle.', 422);
        }

        return [$titulo, trim($descricao)];
    }

    private function inserir(PDO $pdo, int $atorId, string $data, string $titulo, string $descricao, ?string $tipo): int
    {
        $insert = $pdo->prepare('INSERT INTO agenda_avisos (usuario_id, data_aviso, titulo, descricao, tipo) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$atorId, $data, $titulo, $descricao, $tipo]);

        return (int) $pdo->lastInsertId();
    }

    /** @return Aviso */
    private function buscarParaAlteracao(PDO $pdo, int $atorId, int $id): array
    {
        $perfil = $this->perfilAtivo($pdo, $atorId);
        $query = $pdo->prepare(self::CONSULTA . ' AND a.id = ?');
        $query->execute([$id]);
        /** @var Aviso|false $aviso */
        $aviso = $query->fetch();
        if ($aviso === false) {
            throw new DomainException('Aviso não encontrado na agenda.', 404);
        }
        if ($aviso['usuario_id'] !== $atorId && $perfil !== Usuario::PERFIL_ADMINISTRADOR) {
            throw new DomainException('Você só pode alterar avisos que cadastrou.', 403);
        }

        return $aviso;
    }

    private function perfilAtivo(PDO $pdo, int $atorId): string
    {
        $query = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ? AND ativo = 1');
        $query->execute([$atorId]);
        $perfil = $query->fetchColumn();
        if (!in_array($perfil, [Usuario::PERFIL_ADMINISTRADOR, Usuario::PERFIL_FUNCIONARIO], true)) {
            throw new DomainException('Acesso indisponível. Entre novamente no sistema.', 403);
        }

        return $perfil;
    }

    /** @param Aviso $aviso */
    private function conferirRevisao(array $aviso, int $revisao): void
    {
        if ($aviso['revisao'] !== $revisao) {
            throw new DomainException('Este aviso foi alterado. Abra o aviso novamente e confira os dados atuais.', 409);
        }
    }
}
