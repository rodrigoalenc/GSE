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

    /** @return array<string,mixed>|false */
    public function buscarPorEmail(string $email): array|false
    {
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute(['email' => self::normalizarEmail($email)]);

        return $stmt->fetch();
    }

    /** @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    /** @return list<array<string,mixed>> */
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
        bool $recebeAlertasDva = false,
        ?int $actorId = null
    ): bool {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        if (!$this->dadosValidos($nome, $email, $tipo) || PasswordPolicy::validate($senha, $nome, $email) !== []) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($nome, $email, $senha, $tipo, $senhaTemporaria, $recebeAlertasDva, $actorId): bool {
                $this->insertAuditedUser($pdo, $nome, $email, $senha, $tipo, $senhaTemporaria, $recebeAlertasDva, $actorId, 'user.created');
                return true;
            });
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $this->lastErrorCode = $exception instanceof PDOException && str_contains($message, 'unique') && str_contains($message, 'usuarios.email')
                ? 'duplicate_email'
                : 'database_error';
            TechnicalLogger::error('user_create_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public function criarAdministradorInicial(string $nome, string $email, string $senha, bool $recebeAlertasDva = false): array|false
    {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);
        if (!$this->dadosValidos($nome, $email, self::PERFIL_ADMINISTRADOR) || PasswordPolicy::validate($senha, $nome, $email) !== []) {
            $this->lastErrorCode = 'invalid_data';
            return false;
        }
        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($nome, $email, $senha, $recebeAlertasDva): array|false {
                if ($this->contarAdministradoresAtivos() > 0) {
                    $this->lastErrorCode = 'active_admin_exists';
                    return false;
                }
                $id = $this->insertAuditedUser($pdo, $nome, $email, $senha, self::PERFIL_ADMINISTRADOR, true,
                    $recebeAlertasDva, null, 'user.initial_admin_created');
                return $this->buscarPorId($id);
            });
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $this->lastErrorCode = $exception instanceof PDOException && str_contains($message, 'unique') && str_contains($message, 'usuarios.email')
                ? 'duplicate_email' : 'database_error';
            TechnicalLogger::error('initial_admin_create_failed', ['exception' => $exception::class]);
            return false;
        }
    }

    public function atualizar(
        int $id,
        string $nome,
        string $email,
        string $tipo,
        ?string $novaSenha = null,
        ?bool $recebeAlertasDva = null,
        ?int $actorId = null
    ): bool {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        if (!$this->dadosValidos($nome, $email, $tipo)) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $nome, $email, $tipo, $novaSenha, $recebeAlertasDva, $actorId): bool {
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
                $identityChanged = (string) $atual['nome'] !== $nome || (string) $atual['email'] !== $email;
                $alertsAfter = $tipo === self::PERFIL_ADMINISTRADOR
                    ? ($recebeAlertasDva === null ? (int) $atual['recebe_alertas_dva'] : ($recebeAlertasDva ? 1 : 0))
                    : 0;
                $alertsChanged = (int) $atual['recebe_alertas_dva'] !== $alertsAfter;
                if (!$identityChanged && !$privilegeChanged && $novaSenha === null && !$alertsChanged) {
                    return true;
                }
                $sql = 'UPDATE usuarios SET nome = :nome, email = :email, tipo = :tipo,
                        atualizado_em = :updated, recebe_alertas_dva = :dva_alerts';
                $params = [
                    'id' => $id,
                    'nome' => $nome,
                    'email' => $email,
                    'tipo' => $tipo,
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'dva_alerts' => $alertsAfter,
                ];

                if ($privilegeChanged || $novaSenha !== null) {
                    $sql .= ', session_version = session_version + 1';
                }

                if ($novaSenha !== null) {
                    $sql .= ', senha = :senha, deve_alterar_senha = 1, password_changed_at = NULL';
                    $params['senha'] = PasswordPolicy::hash($novaSenha);
                }

                $sql .= ' WHERE id = :id';
                $pdo->prepare($sql)->execute($params);

                if ($identityChanged) {
                    AuditLogger::recordRequired($pdo, 'user.identity_updated', AuditLogger::SUCCESS, $actorId, $id,
                        'Nome ou e-mail da conta alterado.', 'user', $id);
                }
                if ($privilegeChanged) {
                    AuditLogger::recordRequired($pdo, 'user.role_updated', AuditLogger::SUCCESS, $actorId, $id,
                        'Perfil de acesso alterado.', 'user', $id);
                }
                if ($novaSenha !== null) {
                    AuditLogger::recordRequired($pdo, 'password.reset', AuditLogger::SUCCESS, $actorId, $id,
                        'Senha temporária definida pelo administrador.', 'user', $id);
                }
                if ($alertsChanged) {
                    $this->recordDvaAlertsChange($pdo, $actorId, $id, $alertsAfter === 1);
                }
                return true;
            });
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $this->lastErrorCode = $exception instanceof PDOException && str_contains($message, 'last_active_admin')
                ? 'last_active_admin'
                : ($exception instanceof PDOException && str_contains($message, 'unique') && str_contains($message, 'usuarios.email') ? 'duplicate_email' : 'database_error');
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

                AuditLogger::recordRequired($pdo, 'password.changed', AuditLogger::SUCCESS, $id, $id,
                    'Senha alterada pelo próprio usuário.', 'user', $id);
                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('password_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    /** @return array{nome:string,email_alterado:bool}|false */
    public function atualizarPerfilProprio(int $id, int $sessionVersion, string $nome, string $email, string $senhaAtual): array|false
    {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);
        if (preg_match('//u', $nome) !== 1 || preg_match('//u', $email) !== 1
            || $nome === '' || mb_strlen($nome, 'UTF-8') > 150
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email, 'UTF-8') > 254) {
            $this->lastErrorCode = 'invalid_data';
            return false;
        }
        if ($senhaAtual === '' || preg_match('//u', $senhaAtual) !== 1 || str_contains($senhaAtual, "\0")
            || mb_strlen($senhaAtual, 'UTF-8') > PasswordPolicy::MAX_LENGTH) {
            $this->lastErrorCode = 'current_password_invalid';
            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $sessionVersion, $nome, $email, $senhaAtual): array|false {
                $usuario = $this->buscarPorId($id);
                if (!$usuario || (int) $usuario['ativo'] !== 1 || (int) $usuario['deve_alterar_senha'] === 1
                    || !self::perfilValido((string) $usuario['tipo']) || (int) $usuario['session_version'] !== $sessionVersion) {
                    $this->lastErrorCode = 'session_invalid';
                    return false;
                }
                if (!password_verify($senhaAtual, (string) $usuario['senha'])) {
                    $this->lastErrorCode = 'current_password_invalid';
                    return false;
                }
                if ($this->emailEmUso($email, $id)) {
                    $this->lastErrorCode = 'duplicate_email';
                    return false;
                }

                $emailChanged = self::normalizarEmail((string) $usuario['email']) !== $email;
                $statement = $pdo->prepare('UPDATE usuarios SET nome = :name, email = :email,
                    atualizado_em = :now, session_version = session_version + :invalidate WHERE id = :id');
                $statement->execute([
                    'name' => $nome, 'email' => $email, 'now' => gmdate('Y-m-d H:i:s'),
                    'invalidate' => $emailChanged ? 1 : 0, 'id' => $id,
                ]);
                AuditLogger::recordRequired(
                    $pdo, 'user.profile_updated', AuditLogger::SUCCESS, $id, $id,
                    'Dados pessoais atualizados pelo próprio usuário.', 'user', $id
                );
                return ['nome' => $nome, 'email_alterado' => $emailChanged];
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = $exception instanceof PDOException && str_contains(strtolower($exception->getMessage()), 'unique')
                ? 'duplicate_email' : 'database_error';
            TechnicalLogger::error('user_profile_update_failed', ['exception' => $exception::class]);
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
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $ativo, $atorId): bool {
                $usuario = $this->buscarPorId($id);
                if (!$usuario) {
                    $this->lastErrorCode = 'not_found';

                    return false;
                }
                if (((int) $usuario['ativo'] === 1) === $ativo) {
                    return true;
                }

                $statement = $pdo->prepare(
                    'UPDATE usuarios SET ativo = :active, atualizado_em = :updated,
                        session_version = session_version + 1,
                        recebe_alertas_dva = CASE WHEN :active = 1 THEN recebe_alertas_dva ELSE 0 END
                     WHERE id = :id AND ativo <> :active'
                );
                $statement->execute([
                    'active' => $ativo ? 1 : 0,
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'id' => $id,
                ]);

                AuditLogger::recordRequired($pdo, $ativo ? 'user.activated' : 'user.deactivated', AuditLogger::SUCCESS,
                    $atorId, $id, $ativo ? 'Conta ativada.' : 'Conta inativada.', 'user', $id);
                if (!$ativo && (int) $usuario['recebe_alertas_dva'] !== 0) {
                    $this->recordDvaAlertsChange($pdo, $atorId, $id, false);
                }
                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = $exception instanceof PDOException && str_contains(strtolower($exception->getMessage()), 'last_active_admin')
                ? 'last_active_admin'
                : 'database_error';
            TechnicalLogger::error('user_status_change_failed', ['exception' => $exception::class]);

            return false;
        }
    }

    public function excluir(int $id, ?int $actorId = null): bool
    {
        return $this->definirAtivo($id, false, $actorId);
    }

    public function contarAdministradoresAtivos(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE tipo = :tipo AND ativo = 1');
        $stmt->execute(['tipo' => self::PERFIL_ADMINISTRADOR]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
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

    public function atualizarSenhaHash(int $id, string $hash, ?string $expectedHash = null): bool
    {
        $this->lastErrorCode = null;
        if (!str_starts_with($hash, '$')) {
            $this->lastErrorCode = 'invalid_data';
            return false;
        }

        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $hash, $expectedHash): bool {
                $usuario = $this->buscarPorId($id);
                if (!$usuario) {
                    $this->lastErrorCode = 'not_found';
                    return false;
                }
                $expectedHash ??= (string) $usuario['senha'];
                if ((string) $usuario['senha'] !== $expectedHash) {
                    $this->lastErrorCode = 'rehash_stale';
                    return false;
                }
                if ((string) $usuario['senha'] === $hash) {
                    return true;
                }
                $stmt = $pdo->prepare('UPDATE usuarios SET senha = :senha, atualizado_em = :updated WHERE id = :id AND senha = :expected');
                $stmt->execute(['senha' => $hash, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id, 'expected' => $expectedHash]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('O hash de senha mudou durante a autenticação.');
                }
                AuditLogger::recordRequired($pdo, 'password.rehashed', AuditLogger::SUCCESS, $id, $id,
                    'Proteção do hash de senha atualizada na autenticação.', 'user', $id);
                return true;
            });
        } catch (Throwable $exception) {
            $this->lastErrorCode = 'database_error';
            TechnicalLogger::error('password_rehash_failed', ['exception' => $exception::class]);
            return false;
        }
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

    private function insertAuditedUser(PDO $pdo, string $nome, string $email, string $senha, string $tipo,
        bool $senhaTemporaria, bool $recebeAlertasDva, ?int $actorId, string $action): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios
                (nome, email, senha, tipo, ativo, session_version, deve_alterar_senha,
                 password_changed_at, recebe_alertas_dva)
             VALUES (:nome, :email, :senha, :tipo, 1, 1, :temporary, :changed_at, :dva_alerts)'
        );
        $stmt->execute([
            'nome' => $nome, 'email' => $email, 'senha' => PasswordPolicy::hash($senha), 'tipo' => $tipo,
            'temporary' => $senhaTemporaria ? 1 : 0, 'changed_at' => $senhaTemporaria ? null : gmdate('Y-m-d H:i:s'),
            'dva_alerts' => $tipo === self::PERFIL_ADMINISTRADOR && $recebeAlertasDva ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        $description = $action === 'user.initial_admin_created'
            ? 'Primeiro administrador criado com senha temporária.'
            : ($senhaTemporaria ? 'Conta criada com senha temporária.' : 'Conta criada.');
        AuditLogger::recordRequired($pdo, $action, AuditLogger::SUCCESS, $actorId, $id, $description, 'user', $id);
        return $id;
    }

    private function recordDvaAlertsChange(PDO $pdo, ?int $actorId, int $targetId, bool $enabled): void
    {
        AuditLogger::recordRequired($pdo, 'user.dva_alerts_updated', AuditLogger::SUCCESS, $actorId, $targetId,
            $enabled ? 'Recebimento de alertas de DVA habilitado.' : 'Recebimento de alertas de DVA desabilitado.',
            'user', $targetId);
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
