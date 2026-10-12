<?php

declare(strict_types=1);

require_once __DIR__ . '/DataProtection.php';

final class ProtectedBackup
{
    public const MAGIC = "GSEB\x01";
    private const CHUNK = 1048576;

    public static function encrypt(string $source, string $target): void
    {
        $ring = DataProtection::keyring();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($ring['keys'][$ring['active']]);
        $metadata = self::MAGIC . chr(strlen($ring['active'])) . $ring['active'] . $header;
        $in = fopen($source, 'rb');
        if ($in === false) { throw new RuntimeException('Snapshot indisponível.'); }
        $out = null;
        $created = false;
        try {
            $out = fopen($target, 'xb');
            if ($out === false) { throw new RuntimeException('Destino do backup indisponível.'); }
            $created = true;
            if (DIRECTORY_SEPARATOR === '/' && !chmod($target, 0600)) { throw new RuntimeException('Permissões do backup inválidas.'); }
            self::write($out, $metadata);
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) { throw new RuntimeException('Falha ao ler snapshot.'); }
                if ($chunk !== '') {
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $metadata);
                    self::write($out, pack('N', strlen($cipher)) . $cipher);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $metadata, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            self::write($out, pack('N', strlen($final)) . $final);
            if (!fflush($out) || !fsync($out)) { throw new RuntimeException('Falha ao gravar backup.'); }
        } catch (Throwable $exception) {
            if (is_resource($out)) { fclose($out); $out = null; }
            if ($created) { @unlink($target); }
            throw $exception;
        } finally {
            fclose($in);
            if (is_resource($out)) { fclose($out); }
        }
    }

    public static function decrypt(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) { throw new RuntimeException('Backup indisponível.'); }
        $out = null;
        $created = false;
        try {
            $out = fopen($target, 'xb');
            if ($out === false) { throw new RuntimeException('Destino de restauração deve ser novo.'); }
            $created = true;
            if (DIRECTORY_SEPARATOR === '/' && !chmod($target, 0600)) { throw new RuntimeException('Permissões inválidas.'); }
            self::read($in, $out);
        } catch (Throwable $exception) {
            if (is_resource($out)) { fclose($out); $out = null; }
            if ($created) { @unlink($target); }
            throw $exception;
        } finally {
            fclose($in);
            if (is_resource($out)) { fclose($out); }
        }
    }

    /**
     * @param resource $in
     * @param resource|null $out
     */
    public static function read($in, $out = null): void
    {
        try {
            $magic = self::exact($in, 5);
            $lengthByte = self::exact($in, 1);
            $length = ord($lengthByte);
            if ($magic !== self::MAGIC || $length < 1 || $length > 32) { throw new RuntimeException(); }
            $id = self::exact($in, $length);
            $header = self::exact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $metadata = $magic . $lengthByte . $id . $header;
            $ring = DataProtection::keyring();
            if (!isset($ring['keys'][$id])) { throw new RuntimeException(); }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $ring['keys'][$id]);
            while (true) {
                $size = unpack('Nlength', self::exact($in, 4));
                if ($size === false || $size['length'] < 17 || $size['length'] > self::CHUNK + 17) { throw new RuntimeException(); }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::exact($in, $size['length']), $metadata);
                if ($result === false) { throw new RuntimeException(); }
                [$plain, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plain !== '' || fread($in, 1) !== '') { throw new RuntimeException(); }
                    return;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $plain === '') { throw new RuntimeException(); }
                if ($out !== null) { self::write($out, $plain); }
            }
        } catch (Throwable) {
            throw new RuntimeException('Backup protegido inválido, adulterado ou incompleto.');
        }
    }

    /** @param resource $stream */
    private static function exact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') { throw new RuntimeException('Backup incompleto.'); }
            $data .= $chunk;
        }
        return $data;
    }

    /** @param resource $stream */
    private static function write($stream, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if ($written === false || $written === 0) { throw new RuntimeException('Falha ao gravar backup.'); }
            $data = substr($data, $written);
        }
    }
}
