<?php

declare(strict_types=1);

final class DataProtection
{
    /** @return array{active:string,keys:array<string,string>,index:string} */
    public static function keyring(): array
    {
        try {
            if (!extension_loaded('sodium')) { throw new RuntimeException(); }
            $path = (string) ($_ENV['GSE_KEYRING_PATH'] ?? getenv('GSE_KEYRING_PATH') ?: '');
            $real = realpath($path);
            $root = realpath(defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2));
            if ($real === false || !is_file($real) || !is_readable($real) || is_link($path)
                || preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $path) !== 1 || $root === false || self::within($real, $root)
                || filesize($real) > 65536) { throw new RuntimeException(); }
            $database = (string) ($_ENV['DB_PATH'] ?? getenv('DB_PATH') ?: '');
            $database = str_replace('\\','/',$database);
            if (preg_match('~^(?:[A-Za-z]:/|/)~',$database) !== 1) { $database = $root . '/' . $database; }
            $databaseReal = realpath($database);
            if ($databaseReal !== false && self::within($real, dirname($databaseReal) . '/backups')) {
                throw new RuntimeException();
            }
            if (DIRECTORY_SEPARATOR === '/' && ((fileperms($real) & 0077) !== 0
                || (fileperms(dirname($real)) & 0077) !== 0)) { throw new RuntimeException(); }
            for ($parent = dirname($path); $parent !== dirname($parent); $parent = dirname($parent)) {
                if (is_link($parent)) { throw new RuntimeException(); }
            }
            $data = json_decode((string) file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_string($data['active'] ?? null)
                || !is_array($data['keys'] ?? null) || !is_string($data['index_key'] ?? null)) { throw new RuntimeException(); }
            $keys = [];
            foreach ($data['keys'] as $id => $encoded) {
                if (!is_string($id) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,31}\z/D', $id) !== 1 || !is_string($encoded)) { throw new RuntimeException(); }
                $key = base64_decode($encoded, true);
                if ($key === false || strlen($key) !== 32) { throw new RuntimeException(); }
                $keys[$id] = $key;
            }
            $index = base64_decode($data['index_key'], true);
            if (!isset($keys[$data['active']]) || $index === false || strlen($index) !== 32 || in_array($index, $keys, true)) { throw new RuntimeException(); }
            return ['active' => $data['active'], 'keys' => $keys, 'index' => $index];
        } catch (Throwable) {
            throw new RuntimeException('Chaves de proteção indisponíveis ou inválidas.');
        }
    }

    private static function within(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    public static function encrypt(#[SensitiveParameter] string $value, string $field, int $id): string
    {
        $ring = self::keyring();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $prefix = 'gse1:' . $ring['active'] . ':';
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $prefix . $field . ':' . $id, $nonce, $ring['keys'][$ring['active']]);
        return $prefix . base64_encode($nonce . $cipher);
    }

    public static function decrypt(#[SensitiveParameter] string $value, string $field, int $id): string
    {
        try {
            $parts = explode(':', $value, 3);
            $ring = self::keyring();
            if (count($parts) !== 3 || $parts[0] !== 'gse1' || !isset($ring['keys'][$parts[1]])) { throw new RuntimeException(); }
            $raw = base64_decode($parts[2], true);
            if ($raw === false || strlen($raw) < 40) { throw new RuntimeException(); }
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, 24), 'gse1:' . $parts[1] . ':' . $field . ':' . $id,
                substr($raw, 0, 24), $ring['keys'][$parts[1]]);
            if ($plain === false || !mb_check_encoding($plain, 'UTF-8')) { throw new RuntimeException(); }
            return $plain;
        } catch (Throwable) {
            throw new RuntimeException('Não foi possível abrir os dados protegidos.');
        }
    }

    public static function emailIndex(#[SensitiveParameter] string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), self::keyring()['index']);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function user(array $row): array
    {
        foreach (['nome', 'email'] as $field) {
            if (isset($row[$field])) { $row[$field] = self::decrypt((string) $row[$field], 'usuarios.' . $field, (int) $row['id']); }
        }
        return $row;
    }

    public static function register(PDO $pdo): void
    {
        // Decrypted sort expressions must never spill personal values into temp files.
        $pdo->exec('PRAGMA temp_store=MEMORY');
        $pdo->sqliteCreateFunction('gse_email', static fn (string $value, int $id): string => self::decrypt($value, 'usuarios.email', $id), 2);
        $pdo->sqliteCreateFunction('gse_name', static fn (?string $value, ?int $id): ?string => $value === null || $id === null
            ? null : self::decrypt($value, 'usuarios.nome', $id), 2);
    }
}
