<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/DataProtection.php';
require_once ROOT_PATH . '/src/Core/ActionBudget.php';

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
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE email_lookup = :email LIMIT 1');
        $stmt->execute(['email' => DataProtection::emailIndex($email)]);

        $row = $stmt->fetch();
        return $row === false ? false : DataProtection::user($row);
    }

    /** @return array<string,mixed>|false */
    public function buscarPorId(int $id): array|false
    {
        $stmt = self::$pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row === false ? false : DataProtection::user($row);
    }

    /** @return list<array<string,mixed>> */
    public function listar(string $termo = '', int $page = 1): array
    {
        $stmt = self::$pdo->prepare("SELECT id, nome, email, tipo, ativo, deve_alterar_senha,
            recebe_alertas_dva, criado_em, atualizado_em FROM usuarios
            WHERE gse_name(nome,id) LIKE :term ESCAPE '\\' OR gse_email(email,id) LIKE :term ESCAPE '\\'
            ORDER BY gse_name(nome,id) COLLATE NOCASE, id LIMIT 50 OFFSET :offset");
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($termo), 0, 254));
        $stmt->bindValue(':term', '%' . $escaped . '%');
        $stmt->bindValue(':offset', (max(1, min($page, 100)) - 1) * 50, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([DataProtection::class, 'user'], $stmt->fetchAll());
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
        ?int $actorId = null,
        #[SensitiveParameter] ?string $senhaAtual = null,
        ?int $sessionVersion = null
    ): bool {
        $this->lastErrorCode = null;
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        if (!$this->dadosValidos($nome, $email, $tipo)) {
            $this->lastErrorCode = 'invalid_data';

            return false;
        }

        $ownPasswordChange = $novaSenha !== null && $actorId === $id;
        $before = $this->buscarPorId($id);
        $ownSensitiveChange = $actorId === $id && ($novaSenha !== null
            || ($before && self::normalizarEmail((string) $before['email']) !== $email));
        $verified = null;
        if ($ownSensitiveChange) {
            $verified = $this->confirmCurrentPassword($id, $senhaAtual ?? '', $sessionVersion);
            if ($verified === false) { return false; }
        }
        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $nome, $email, $tipo, $novaSenha, $recebeAlertasDva, $actorId, $ownPasswordChange, $verified): bool {
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

                $emailChanged = self::normalizarEmail((string) $atual['email']) !== $email;
                if ($actorId === $id && ($emailChanged || $novaSenha !== null)) {
                    if (!$verified || (int) $atual['ativo'] !== 1 || $atual['senha'] !== $verified['senha']
                        || (int) $atual['session_version'] !== (int) $verified['session_version']) {
                        $this->lastErrorCode = 'current_password_invalid';
                        return false;
                    }
                }
                if ($ownPasswordChange && $novaSenha !== null) {
                    if (PasswordPolicy::verify($novaSenha, (string) $atual['senha'])) {
                        $this->lastErrorCode = 'password_reused';
                        return false;
                    }
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
                $sql = 'UPDATE usuarios SET nome = :nome, email = :email, email_lookup = :email_lookup, tipo = :tipo,
                        atualizado_em = :updated, recebe_alertas_dva = :dva_alerts';
                $params = [
                    'id' => $id,
                    'nome' => DataProtection::encrypt($nome, 'usuarios.nome', $id),
                    'email' => DataProtection::encrypt($email, 'usuarios.email', $id),
                    'email_lookup' => DataProtection::emailIndex($email),
                    'tipo' => $tipo,
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'dva_alerts' => $alertsAfter,
                ];

                if ($privilegeChanged || $novaSenha !== null || $emailChanged) {
                    $sql .= ', session_version = session_version + 1';
                }

                if ($novaSenha !== null) {
                    $sql .= ', senha = :senha, deve_alterar_senha = :temporary, password_changed_at = :password_changed';
                    $params['senha'] = PasswordPolicy::hash($novaSenha);
                    $params['temporary'] = $ownPasswordChange ? 0 : 1;
                    $params['password_changed'] = $ownPasswordChange ? gmdate('Y-m-d H:i:s') : null;
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
                    AuditLogger::recordRequired($pdo, $ownPasswordChange ? 'password.changed' : 'password.reset', AuditLogger::SUCCESS, $actorId, $id,
                        $ownPasswordChange ? 'Senha alterada pelo próprio usuário.' : 'Senha temporária definida pelo administrador.', 'user', $id);
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
        $before = $this->confirmCurrentPassword($id, $senhaAtual);
        if ($before === false) { return false; }
        try {
            if (PasswordPolicy::validate($novaSenha, (string) $before['nome'], (string) $before['email']) !== []) {
                $this->lastErrorCode = 'invalid_password';
                return false;
            }
            if (PasswordPolicy::verify($novaSenha, (string) $before['senha'])) {
                $this->lastErrorCode = 'password_reused';
                return false;
            }
            $hash = PasswordPolicy::hash($novaSenha);
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $before, $hash): bool {
                $current = $this->buscarPorId($id);
                if (!$current || (int) $current['ativo'] !== 1 || $current['senha'] !== $before['senha']
                    || $current['session_version'] !== $before['session_version'] || $current['tipo'] !== $before['tipo']) {
                    $this->lastErrorCode = 'session_invalid';
                    return false;
                }
                $pdo->prepare('UPDATE usuarios SET senha=?, deve_alterar_senha=0,
                    password_changed_at=?, atualizado_em=?, session_version=session_version+1 WHERE id=?')
                    ->execute([$hash, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
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
        $verified = $this->confirmCurrentPassword($id, $senhaAtual, $sessionVersion);
        if ($verified === false) { return false; }
        try {
            return SqliteTransaction::immediate(self::$pdo, function (PDO $pdo) use ($id, $sessionVersion, $nome, $email, $verified): array|false {
                $usuario = $this->buscarPorId($id);
                if (!$usuario || (int) $usuario['ativo'] !== 1 || (int) $usuario['deve_alterar_senha'] === 1
                    || !self::perfilValido((string) $usuario['tipo']) || (int) $usuario['session_version'] !== $sessionVersion) {
                    $this->lastErrorCode = 'session_invalid';
                    return false;
                }
                if ($usuario['senha'] !== $verified['senha'] || $usuario['session_version'] !== $verified['session_version']) {
                    $this->lastErrorCode = 'current_password_invalid';
                    return false;
                }
                if ($this->emailEmUso($email, $id)) {
                    $this->lastErrorCode = 'duplicate_email';
                    return false;
                }

                $emailChanged = self::normalizarEmail((string) $usuario['email']) !== $email;
                $statement = $pdo->prepare('UPDATE usuarios SET nome = :name, email = :email, email_lookup = :lookup,
                    atualizado_em = :now, session_version = session_version + :invalidate WHERE id = :id');
                $statement->execute([
                    'name' => DataProtection::encrypt($nome, 'usuarios.nome', $id), 'email' => DataProtection::encrypt($email, 'usuarios.email', $id),
                    'lookup' => DataProtection::emailIndex($email), 'now' => gmdate('Y-m-d H:i:s'),
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

    /**
     * Shared reauthentication for profile, administrative self-edit and password routes.
     * @return array<string,mixed>|false
     */
    private function confirmCurrentPassword(int $id, #[SensitiveParameter] string $password, ?int $sessionVersion = null): array|false
    {
        // All entry points share one budget, so changing routes cannot reset it.
        ActionBudget::consume(self::$pdo, $id, 'identity');
        $current = $this->buscarPorId($id);
        if (!$current || !PasswordPolicy::verify($password, (string) $current['senha'])) {
            $this->lastErrorCode = 'current_password_invalid';
            return false;
        }
        if ((int) $current['ativo'] !== 1 || ($sessionVersion !== null && (int) $current['session_version'] !== $sessionVersion)) {
            $this->lastErrorCode = 'session_invalid';
            return false;
        }
        return $current;
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
        if (password_get_info($hash)['algoName'] !== 'argon2id') {
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
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE email_lookup = :email';
        $params = ['email' => DataProtection::emailIndex($email)];

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
        $id = (int) $pdo->query("SELECT MAX(COALESCE((SELECT seq FROM sqlite_sequence WHERE name='usuarios'),0), COALESCE((SELECT MAX(id) FROM usuarios),0))+1")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios
                (id, nome, email, email_lookup, senha, tipo, ativo, session_version, deve_alterar_senha,
                 password_changed_at, recebe_alertas_dva)
             VALUES (:id, :nome, :email, :lookup, :senha, :tipo, 1, 1, :temporary, :changed_at, :dva_alerts)'
        );
        $stmt->execute([
            'id' => $id, 'nome' => DataProtection::encrypt($nome, 'usuarios.nome', $id),
            'email' => DataProtection::encrypt($email, 'usuarios.email', $id), 'lookup' => DataProtection::emailIndex($email), 'senha' => PasswordPolicy::hash($senha), 'tipo' => $tipo,
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
