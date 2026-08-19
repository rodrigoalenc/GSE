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
    public static function immediate(PDO $pdo, callable $operation): mixed
    {
        $started = false;

        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
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
