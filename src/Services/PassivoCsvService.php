<?php

declare(strict_types=1);

use src\Core\TextNormalizer;

final class PassivoCsvService
{
    public const MAX_FILE_SIZE = 2_097_152;
    public const MAX_ROWS = 5_000;
    public const PREVIEW_TTL = 900;
    public const MAX_ERRORS = 50;

    private ?string $lastErrorCode = null;

    /**
     * @param array<string,mixed> $file
     * @return array{token:string,valid:int,invalid:int,duplicate:int,conflict:int,errors:list<array{line:int,message:string}>,expires_at:int}|false
     */
    public function previewUpload(array $file, int $actorId, Passivo $model): array|false
    {
        $this->lastErrorCode = null;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->lastErrorCode = 'invalid_upload';

            return false;
        }

        return $this->createPreview((string) $file['tmp_name'], $actorId, $model, true);
    }

    /**
     * Ponto de teste para validar o parser sem simular o transporte HTTP.
     *
     * @return array{token:string,valid:int,invalid:int,duplicate:int,conflict:int,errors:list<array{line:int,message:string}>,expires_at:int}|false
     */
    public function previewTrustedFile(string $path, int $actorId, Passivo $model): array|false
    {
        $this->lastErrorCode = null;

        return $this->createPreview($path, $actorId, $model, false);
    }

    public function confirm(string $token, int $actorId, Passivo $model): int|false
    {
        $this->lastErrorCode = null;
        $this->cleanupExpired();

        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $this->lastErrorCode = 'invalid_token';

            return false;
        }

        $previews = $_SESSION['passivo_import_previews'] ?? [];
        $preview = is_array($previews) && isset($previews[$token]) && is_array($previews[$token])
            ? $previews[$token]
            : null;
        unset($_SESSION['passivo_import_previews'][$token]);

        if (!is_array($preview)
            || (int) ($preview['actor_id'] ?? 0) !== $actorId
            || (int) ($preview['expires_at'] ?? 0) < time()) {
            $this->removePreviewFile(is_array($preview) ? ($preview['path'] ?? null) : null);
            $this->lastErrorCode = 'invalid_token';

            return false;
        }

        $path = is_string($preview['path'] ?? null) ? $preview['path'] : '';

        try {
            if (!$this->isRegularPrivateFile($path)
                || !hash_equals((string) ($preview['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                $this->lastErrorCode = 'preview_changed';

                return false;
            }

            $analysis = $this->analyze($path, $model);

            if ($analysis === false) {
                $this->lastErrorCode ??= 'empty_import';

                return false;
            }

            $analysisHash = (string) ($preview['analysis_sha256'] ?? '');

            if (preg_match('/^[a-f0-9]{64}$/', $analysisHash) !== 1
                || !hash_equals($analysisHash, $this->analysisFingerprint($analysis))) {
                $this->lastErrorCode = 'duplicate_changed';

                return false;
            }

            if ($analysis['valid_rows'] === []) {
                $this->lastErrorCode = 'empty_import';

                return false;
            }

            $result = $model->importarLote($analysis['valid_rows'], $actorId);

            if ($result === false) {
                $this->lastErrorCode = $model->lastErrorCode();
            }

            return $result;
        } finally {
            $this->removePreviewFile($path);
        }
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function errorMessage(): string
    {
        return match ($this->lastErrorCode) {
            'invalid_upload' => 'Selecione um arquivo regular enviado pelo formulário.',
            'file_too_large' => 'O CSV ultrapassa o limite de 2 MiB.',
            'too_many_rows' => 'O CSV ultrapassa o limite de 5.000 linhas de dados.',
            'invalid_mime' => 'O conteúdo enviado não foi reconhecido como texto CSV.',
            'invalid_utf8' => 'O CSV precisa estar codificado em UTF-8 válido.',
            'invalid_header' => 'Use exatamente o cabeçalho Nome;Data;Numero;Caixa.',
            'empty_file' => 'O CSV está vazio.',
            'invalid_token' => 'A prévia expirou, pertence a outra sessão ou já foi utilizada.',
            'preview_changed' => 'O arquivo da prévia foi alterado. Envie-o novamente.',
            'empty_import' => 'Não existem linhas válidas para importar.',
            'location_conflict', 'duplicate_changed' => 'O acervo mudou depois da prévia. Gere uma nova prévia.',
            default => 'Não foi possível processar o CSV com segurança.',
        };
    }

    /**
     * @return array{token:string,valid:int,invalid:int,duplicate:int,conflict:int,errors:list<array{line:int,message:string}>,expires_at:int}|false
     */
    private function createPreview(string $source, int $actorId, Passivo $model, bool $uploaded): array|false
    {
        $this->cleanupExpired();

        if ($actorId < 1 || !$this->isRegularPrivateFile($source)) {
            $this->lastErrorCode = 'invalid_upload';

            return false;
        }

        $size = filesize($source);

        if ($size === false || $size < 1) {
            $this->lastErrorCode = 'empty_file';

            return false;
        }

        if ($size > self::MAX_FILE_SIZE) {
            $this->lastErrorCode = 'file_too_large';

            return false;
        }

        if (!$this->validMime($source)) {
            $this->lastErrorCode = 'invalid_mime';

            return false;
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-passive-imports';

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->lastErrorCode = 'storage_failed';

            return false;
        }

        $destination = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.csv';
        $stored = $uploaded ? move_uploaded_file($source, $destination) : copy($source, $destination);

        if (!$stored) {
            $this->lastErrorCode = 'storage_failed';

            return false;
        }

        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($directory, 0700);
            @chmod($destination, 0600);
        }

        $analysis = $this->analyze($destination, $model);

        if ($analysis === false) {
            $this->removePreviewFile($destination);

            return false;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + self::PREVIEW_TTL;
        $_SESSION['passivo_import_previews'][$token] = [
            'path' => $destination,
            'sha256' => (string) hash_file('sha256', $destination),
            'analysis_sha256' => $this->analysisFingerprint($analysis),
            'actor_id' => $actorId,
            'expires_at' => $expiresAt,
        ];

        return [
            'token' => $token,
            'valid' => $analysis['valid'],
            'invalid' => $analysis['invalid'],
            'duplicate' => $analysis['duplicate'],
            'conflict' => $analysis['conflict'],
            'errors' => $analysis['errors'],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{valid_rows:list<array<string,mixed>>,errors:list<array{line:int,message:string}>,valid:int,invalid:int,duplicate:int,conflict:int}|false
     */
    private function analyze(string $path, Passivo $model): array|false
    {
        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            $this->lastErrorCode = 'empty_file';

            return false;
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if ($content === '' || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            $this->lastErrorCode = 'invalid_utf8';

            return false;
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            $this->lastErrorCode = 'storage_failed';

            return false;
        }

        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, 0, ';', '"', '');

        if (!is_array($header) || count($header) !== 4) {
            fclose($stream);
            $this->lastErrorCode = 'invalid_header';

            return false;
        }

        try {
            $normalizedHeader = array_map(static fn (mixed $value): string => TextNormalizer::searchKey((string) $value), $header);
        } catch (Throwable) {
            fclose($stream);
            $this->lastErrorCode = 'invalid_header';

            return false;
        }

        if ($normalizedHeader !== ['nome', 'data', 'numero', 'caixa']) {
            fclose($stream);
            $this->lastErrorCode = 'invalid_header';

            return false;
        }

        $rows = [];
        $fileErrors = [];
        $line = 1;
        $invalid = 0;

        while (($columns = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $line++;

            if ($line > self::MAX_ROWS + 1) {
                fclose($stream);
                $this->lastErrorCode = 'too_many_rows';

                return false;
            }

            if ($columns === [null] || (count($columns) === 1 && trim((string) $columns[0]) === '')) {
                $invalid++;
                $fileErrors[] = ['line' => $line, 'message' => 'Linha vazia.'];
                continue;
            }

            if (count($columns) !== 4) {
                $invalid++;
                $fileErrors[] = ['line' => $line, 'message' => 'A linha deve possuir exatamente quatro colunas.'];
                continue;
            }

            $birthDate = trim((string) $columns[1]);

            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $birthDate, $match) === 1) {
                $birthDate = $match[3] . '-' . $match[2] . '-' . $match[1];
            }

            $rows[] = [
                'line' => $line,
                'nome_completo' => (string) $columns[0],
                'data_nascimento' => $birthDate,
                'numero' => (string) $columns[2],
                'caixa' => (string) $columns[3],
            ];
        }

        fclose($stream);

        if ($line === 1) {
            $this->lastErrorCode = 'empty_file';

            return false;
        }

        $analysis = $model->analisarImportacao($rows);
        $analysis['invalid'] += $invalid;
        $analysis['errors'] = array_slice(array_merge($fileErrors, $analysis['errors']), 0, self::MAX_ERRORS);

        return $analysis;
    }

    private function cleanupExpired(): void
    {
        $this->cleanupExpiredDirectory();
        $previews = $_SESSION['passivo_import_previews'] ?? [];

        if (!is_array($previews)) {
            $_SESSION['passivo_import_previews'] = [];

            return;
        }

        foreach ($previews as $token => $preview) {
            if (!is_array($preview) || (int) ($preview['expires_at'] ?? 0) < time()) {
                $this->removePreviewFile(is_array($preview) ? ($preview['path'] ?? null) : null);
                unset($_SESSION['passivo_import_previews'][$token]);
            }
        }
    }

    private function cleanupExpiredDirectory(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-passive-imports';

        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.csv') ?: [];
        $expiration = time() - self::PREVIEW_TTL;

        foreach ($files as $file) {
            $modified = filemtime($file);

            if (is_file($file) && !is_link($file) && $modified !== false && $modified < $expiration) {
                @unlink($file);
            }
        }
    }

    private function validMime(string $path): bool
    {
        if (!function_exists('finfo_open')) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return false;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true);
    }

    /**
     * @param array{valid_rows:list<array<string,mixed>>,errors:list<array{line:int,message:string}>,valid:int,invalid:int,duplicate:int,conflict:int} $analysis
     */
    private function analysisFingerprint(array $analysis): string
    {
        $payload = json_encode([
            'valid_rows' => $analysis['valid_rows'],
            'valid' => $analysis['valid'],
            'invalid' => $analysis['invalid'],
            'duplicate' => $analysis['duplicate'],
            'conflict' => $analysis['conflict'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function isRegularPrivateFile(string $path): bool
    {
        return $path !== '' && is_file($path) && !is_link($path) && is_readable($path);
    }

    private function removePreviewFile(mixed $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
