<?php

declare(strict_types=1);

require_once __DIR__ . '/ProtectedBackup.php';
require_once __DIR__ . '/BackupCleanupException.php';

/** Internal snapshot lifecycle; fault injection is limited to PHP test subclasses. */
class BackupSnapshot
{
    /** @param callable(string):void $snapshot */
    public function create(string $target, callable $snapshot): void
    {
        $operation = bin2hex(random_bytes(16));
        $directory = dirname($target) . '/.snapshot-' . $operation;
        $path = $directory . '/snapshot.sqlite';
        $manifest = $directory . '/identity.json';
        $directoryIdentity = $fileIdentity = $manifestIdentity = null;
        $handle = null;
        $original = null;
        $encrypted = false;
        $phase = 'snapshot';
        try {
            $this->privateParent(dirname($target));
            if (!@mkdir($directory, 0700)) {
                throw new BackupWriteException('Destino temporário indisponível.');
            }
            $directoryIdentity = lstat($directory);
            $handle = @fopen($path, 'x+b');
            if ($handle === false) { throw new BackupWriteException('Reserva do snapshot indisponível.'); }
            $fileIdentity = fstat($handle);
            if (DIRECTORY_SEPARATOR === '/' && !@chmod($path, 0600)) {
                throw new BackupWriteException('Proteção do snapshot indisponível.');
            }
            $fileIdentity = fstat($handle);
            if ($fileIdentity === false || $directoryIdentity === false) { throw new BackupWriteException('Identidade indisponível.'); }
            // A private receipt permits offline identification without logging paths or data.
            $receipt = json_encode(['operation' => $operation, 'file' => $fileIdentity,
                'directory' => $directoryIdentity, 'backup' => basename($target)], JSON_THROW_ON_ERROR);
            $receiptHandle = @fopen($manifest, 'xb');
            if ($receiptHandle === false) { throw new BackupWriteException('Registro do snapshot indisponível.'); }
            try {
                $manifestIdentity = fstat($receiptHandle);
                if (DIRECTORY_SEPARATOR === '/' && !@chmod($manifest, 0600)) {
                    throw new BackupWriteException('Proteção do registro do snapshot falhou.');
                }
                $manifestIdentity = fstat($receiptHandle);
                if (@fwrite($receiptHandle, $receipt) !== strlen($receipt) || !@fflush($receiptHandle) || !@fsync($receiptHandle)) {
                    throw new BackupWriteException('Gravação do registro do snapshot falhou.');
                }
            } finally { fclose($receiptHandle); }
            $snapshot($path);
            $this->assertOwned($path, $fileIdentity);
            $phase = 'encryption';
            $this->encrypt($path, $target);
            $encrypted = true;
        } catch (Throwable $exception) {
            $original = $exception;
            $phase = $exception instanceof BackupWriteException ? 'write' : $phase;
            $this->log('backup_operation_failed', $operation, $phase, $encrypted, $exception);
        } finally {
            // Windows requires closing our reservation before unlink; on POSIX this handle
            // also prevents inode reuse while the snapshot is being built and encrypted.
            if (is_resource($handle)) { fclose($handle); }
        }

        $cleanup = null;
        try {
            if (is_array($directoryIdentity)) {
                $this->assertOwned($directory, $directoryIdentity);
                if (is_array($fileIdentity)) { $this->removeOwned($path, $fileIdentity); }
                if (is_array($manifestIdentity)) { $this->removeOwned($manifest, $manifestIdentity); }
                if (!@rmdir($directory)) { throw new BackupCleanupException(); }
            }
        } catch (Throwable $exception) {
            $cleanup = new BackupCleanupException();
            $this->log('backup_plaintext_cleanup_failed', $operation, $phase, $encrypted, $exception);
        }
        // An encryption/snapshot error remains the exact original throwable, even if
        // cleanup also failed. A completed encrypted backup is never compensated here.
        if ($original !== null) { throw $original; }
        if ($cleanup !== null) { throw $cleanup; }
    }

    protected function encrypt(string $source, string $target): void
    {
        ProtectedBackup::encrypt($source, $target);
    }

    protected function remove(string $path): bool
    {
        return @unlink($path);
    }

    /** @param array<string|int,mixed> $identity */
    private function removeOwned(string $path, array $identity): void
    {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) { return; }
        $this->assertOwned($path, $identity);
        $this->remove($path);
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) { throw new BackupCleanupException(); }
    }

    /** @param array<string|int,mixed> $identity */
    private function assertOwned(string $path, array $identity): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if ($current === false || is_link($path)) { throw new BackupCleanupException(); }
        foreach (['dev', 'ino', 'uid', 'mode', 'nlink'] as $field) {
            if ($identity[$field] !== $current[$field]) { throw new BackupCleanupException(); }
        }
    }

    private function privateParent(string $directory): void
    {
        $canonical = realpath($directory);
        $public = realpath((defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/public');
        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', $path));
        if ($canonical === false || $public === false
            || str_starts_with($normalize($canonical) . '/', $normalize($public) . '/')) {
            throw new BackupWriteException('Armazenamento privado indisponível.');
        }
        for ($parent = $directory; dirname($parent) !== $parent; $parent = dirname($parent)) {
            if (is_link($parent)) { throw new BackupWriteException('Armazenamento privado indisponível.'); }
        }
        if (DIRECTORY_SEPARATOR === '/' && !chmod($directory, 0700)) {
            throw new BackupWriteException('Permissões privadas indisponíveis.');
        }
    }

    private function log(string $event, string $operation, string $phase, bool $encrypted, Throwable $exception): void
    {
        try {
            $this->record($event, ['operation' => $operation, 'phase' => $phase,
                'encrypted' => $encrypted ? 'yes' : 'no', 'exception' => $exception::class]);
        } catch (Throwable) {
            // Logging must never skip cleanup or replace an operation's original error.
            // A storage outage may also prevent this minimal SAPI fallback from persisting.
            try { @error_log($event . ' operation=' . $operation . ' phase=' . $phase . ' encrypted=' . ($encrypted ? 'yes' : 'no')); }
            catch (Throwable) { /* Preserve the operation and cleanup outcomes. */ }
        }
    }

    /** @param array<string,string> $context */
    protected function record(string $event, array $context): void
    {
        if (class_exists(TechnicalLogger::class, false)) { TechnicalLogger::error($event, $context); }
    }
}
