<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';

use src\Core\SqliteTransaction;

class Usuario extends Model
{
    public const PERFIL_ADMINISTRADOR = 'administrador';
    public const PERFIL_FUNCIONARIO = 'funcionario';
    public const TAMANHO_MINIMO_SENHA = PasswordPolicy::MIN_LENGTH;
    public const TAMANHO_MAXIMO_SENHA = PasswordPolicy::MAX_LENGTH;

    private ?string $lastErrorCode = null;

    public function buscarPorEmail(string $email): array|false
    {
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute(['email' => self::normalizarEmail($email)]);

        return $stmt->fetch();
    }

    public function buscarPorId(int $id): array|false
    {
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function listar(string $termo = ''): array
    {
        $termo = trim($termo);
        $columns = 'id, nome, email, tipo, ativo, deve_alterar_senha, recebe_alertas_dva, criado_em, atualizado_em';

        if ($termo === '') {
            return self::$pdo->query("SELECT {$columns} FROM usuarios ORDER BY nome COLLATE NOCASE")->fetchAll();
        }

        $stmt = self::$pdo->prepare(
            "SELECT {$columns} FROM usuarios
             WHERE nome LIKE :termo ESCAPE '\\' OR email LIKE :termo ESCAPE '\\'
             ORDER BY nome COLLATE NOCASE"
        );
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo);
        $stmt->execute(['termo' => '%' . $escaped . '%']);

        return $stmt->fetchAll();
    }

    public function cadastrar(
        string $nome,
        string $email,
        string $senha,
        string $tipo,
        bool $senhaTemporaria = true,
        bool $recebeAlertasDva = false
    ): bool {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        if (!$this->dadosValidos($nome, $email, $tipo) || PasswordPolicy::validate($senha, $nome, $email) !== []) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            $stmt = self::$pdo->prepare(
                'INSERT INTO usuarios
                    (nome, email, senha, tipo, ativo, session_version, deve_alterar_senha,
                     password_changed_at, recebe_alertas_dva)
                 VALUES (:nome, :email, :senha, :tipo, 1, 1, :temporary, :changed_at, :dva_alerts)'
            );

            return $stmt->execute([
                'nome' => $nome,
                'email' => $email,
                'senha' => PasswordPolicy::hash($senha),
                'tipo' => $tipo,
                'temporary' => $senhaTemporaria ? 1 : 0,
                'changed_at' => $senhaTemporaria ? null : gmdate('Y-m-d H:i:s'),
                'dva_alerts' => $tipo === self::PERFIL_ADMINISTRADOR && $recebeAlertasDva ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            $this->lastErrorCode = str_contains(strtolower($exception->getMessage()), 'unique')
                ? 'duplicate_email'
                : 'database_error';
            TechnicalLogger::error('user_create_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function atualizar(
        int $id,
        string $nome,
        string $email,
        string $tipo,
        ?string $novaSenha = null,
        ?bool $recebeAlertasDva = null
    ): bool {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        if (!$this->dadosValidos($nome, $email, $tipo)) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $nome, $email, $tipo, $novaSenha, $recebeAlertasDva): bool {
                $atual = $this->buscarPorId($id);

                if (!$atual) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                if ($this->emailEmUso($email, $id)) {
                    $this->lastErrorCode = 'duplicate_email';

                    return false;
                }

                if ($novaSenha !== null && PasswordPolicy::validate($novaSenha, $nome, $email) !== []) {
                    $this->lastErrorCode = 'invalid_password';

                    return false;
                }

                $privilegeChanged = (string) $atual['tipo'] !== $tipo;
                $sql = 'UPDATE usuarios SET nome = :nome, email = :email, tipo = :tipo,
                        atualizado_em = :updated';
                $params = [
                    'id' => $id,
                    'nome' => $nome,
                    'email' => $email,
                    'tipo' => $tipo,
                    'updated' => gmdate('Y-m-d H:i:s'),
                ];

                if ($privilegeChanged) {
                    $sql .= ', session_version = session_version + 1';
                }

                if ($novaSenha !== null) {
                    $sql .= ', senha = :senha, deve_alterar_senha = 1, password_changed_at = NULL,
                               session_version = session_version + 1';
                    $params['senha'] = PasswordPolicy::hash($novaSenha);
                }

                if ($recebeAlertasDva !== null) {
                    $sql .= ', recebe_alertas_dva = :dva_alerts';
                    $params['dva_alerts'] = $tipo === self::PERFIL_ADMINISTRADOR && $recebeAlertasDva ? 1 : 0;
                } elseif ($tipo !== self::PERFIL_ADMINISTRADOR) {
                    $sql .= ', recebe_alertas_dva = 0';
                }

                $sql .= ' WHERE id = :id';
                $pdo->prepare($sql)->execute($params);

                return true;
            });
        } catch (PDOException $exception) {
            $message = strtolower($exception->getMessage());
            $this->lastErrorCode = str_contains($message, 'last_active_admin')
                ? 'last_active_admin'
                : (str_contains($message, 'unique') ? 'duplicate_email' : 'database_error');
            TechnicalLogger::error('user_update_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function alterarSenha(int $id, string $senhaAtual, string $novaSenha): bool
    {
        $this->lastErrorCode = null;
        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $senhaAtual, $novaSenha): bool {
                $usuario = $this->buscarPorId($id);

                if (!$usuario || !password_verify($senhaAtual, (string) ($usuario['senha'] ?? ''))) {
                    $this->lastErrorCode = 'current_password_invalid';

                    return false;
                }

                if (PasswordPolicy::validate($novaSenha, (string) $usuario['nome'], (string) $usuario['email']) !== []) {
                    $this->lastErrorCode = 'invalid_password';

                    return false;
                }

                if (password_verify($novaSenha, (string) $usuario['senha'])) {
                    $this->lastErrorCode = 'password_reused';

                    return false;
                }

                $statement = $pdo->prepare(
                    'UPDATE usuarios SET senha = :password, deve_alterar_senha = 0,
                        password_changed_at = :changed, atualizado_em = :changed,
                        session_version = session_version + 1 WHERE id = :id'
                );
                $statement->execute([
                    'password' => PasswordPolicy::hash($novaSenha),
                    'changed' => gmdate('Y-m-d H:i:s'),
                    'id' => $id,
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('password_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function definirAtivo(int $id, bool $ativo, ?int $atorId = null): bool
    {
        $this->lastErrorCode = null;

        if (!$ativo && $atorId === $id) {
            $this->lastErrorCode = 'self_deactivation';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $ativo): bool {
                if (!$this->buscarPorId($id)) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }

                $statement = $pdo->prepare(
                    'UPDATE usuarios SET ativo = :active, atualizado_em = :updated,
                        session_version = session_version + 1
                     WHERE id = :id AND ativo <> :active'
                );
                $statement->execute([
                    'active' => $ativo ? 1 : 0,
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'id' => $id,
                ]);

                return true;
            });
        } catch (PDOException $exception) {
            $this->lastErrorCode = str_contains(strtolower($exception->getMessage()), 'last_active_admin')
                ? 'last_active_admin'
                : 'database_error';
            TechnicalLogger::error('user_status_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function excluir(int $id): bool
    {
        return $this->definirAtivo($id, false);
    }

    public function contarAdministradoresAtivos(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE tipo = :tipo AND ativo = 1');
        $stmt->execute(['tipo' => self::PERFIL_ADMINISTRADOR]);

        return (int) $stmt->fetchColumn();
    }

    public function estatisticas(): array
    {
        $resultado = self::$pdo->query(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
                SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) AS inativos,
                SUM(CASE WHEN tipo = 'administrador' AND ativo = 1 THEN 1 ELSE 0 END) AS administradores
             FROM usuarios"
        )->fetch();

        return [
            'total' => (int) ($resultado['total'] ?? 0),
            'ativos' => (int) ($resultado['ativos'] ?? 0),
            'inativos' => (int) ($resultado['inativos'] ?? 0),
            'administradores' => (int) ($resultado['administradores'] ?? 0),
        ];
    }

    public function atualizarSenhaHash(int $id, string $hash): bool
    {
        if (!str_starts_with($hash, '$')) {
            return false;
        }

        $stmt = self::$pdo->prepare(
            'UPDATE usuarios SET senha = :senha, atualizado_em = :updated WHERE id = :id'
        );

        return $stmt->execute(['senha' => $hash, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function emailEmUso(string $email, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE email = :email COLLATE NOCASE';
        $params = ['email' => self::normalizarEmail($email)];

        if ($ignorarId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignorarId;
        }

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function senhaForte(string $senha, string $nome = '', string $email = ''): bool
    {
        return PasswordPolicy::validate($senha, $nome, $email) === [];
    }

    public static function perfilValido(string $tipo): bool
    {
        return in_array($tipo, [self::PERFIL_ADMINISTRADOR, self::PERFIL_FUNCIONARIO], true);
    }

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    private function dadosValidos(string $nome, string $email, string $tipo): bool
    {
        return $nome !== ''
            && mb_strlen($nome, 'UTF-8') <= 150
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($email, 'UTF-8') <= 254
            && self::perfilValido($tipo);
    }
}
