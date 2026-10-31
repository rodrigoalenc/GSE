<?php

declare(strict_types=1);

namespace src\Core;

use PDO;
use Throwable;

final class SqliteTransaction
{
    /**
     * Executa uma operação sob BEGIN IMMEDIATE sem depender de PDO::inTransaction().
     *
     * O driver SQLite pode não refletir corretamente transações iniciadas por SQL
     * manual. Por isso, o estado é controlado explicitamente neste método.
     *
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    public static function immediate(PDO $pdo, callable $operation, bool $checkSession = true): mixed
    {
        $started = false;

        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
            // Read under the write lock: another writer cannot revoke this identity
            // between this check and the business commit. CLI jobs have no HTTP session.
            if ($checkSession && PHP_SAPI !== 'cli' && isset($_SESSION['usuario_id'], $_SESSION['auth_version'])) {
                $identity = $pdo->prepare('SELECT ativo,session_version,tipo FROM usuarios WHERE id=?');
                $identity->execute([(int)$_SESSION['usuario_id']]);
                $row = $identity->fetch(PDO::FETCH_ASSOC);
                if ($row === false || (int)$row['ativo'] !== 1
                    || (int)$row['session_version'] !== (int)$_SESSION['auth_version']
                    || (string)$row['tipo'] !== (string)($_SESSION['usuario_tipo'] ?? '')) {
                    throw new \DomainException('Sessão inválida. Faça login novamente.');
                }
            }
            $result = $operation($pdo);
            $pdo->exec('COMMIT');
            $started = false;

            return $result;
        } catch (Throwable $originalException) {
            if ($started) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $rollbackException) {
                    if (class_exists('\TechnicalLogger', false)) {
                        try {
                            \TechnicalLogger::error('sqlite_transaction_rollback_failed', [
                                'exception' => $rollbackException::class,
                            ]);
                        } catch (Throwable) {
                            // A falha do logger nunca substitui a exceção original da operação.
                        }
                    }
                }
            }

            throw $originalException;
        }
    }
}
