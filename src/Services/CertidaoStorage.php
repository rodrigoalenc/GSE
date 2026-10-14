<?php

declare(strict_types=1);

/** Private local filesystem. The directory must be owned by the application account. */
final class CertidaoStorage
{
    private readonly string $root;
    private readonly int $maximum;

    public function __construct(?string $directory = null, ?int $maximum = null)
    {
        $directory ??= Config::string('CERTIDAO_STORAGE_PATH') ?: ROOT_PATH . '/storage/certidoes';
        $directory = str_replace('\\', '/', $directory);
        if (!str_starts_with($directory, '/') && preg_match('/^[a-z]:\//i', $directory) !== 1) {
            throw new RuntimeException('O armazenamento de certidões exige caminho absoluto privado.');
        }
        self::rejectLinks($directory);
        if (file_exists($directory) && !is_dir($directory)) { throw new RuntimeException('Armazenamento deve ser um diretório.'); }
        $public = strtolower(str_replace('\\', '/', (string) realpath(ROOT_PATH . '/public')));
        $candidate = strtolower(rtrim($directory, '/'));
        if (str_contains($candidate, '/../') || str_ends_with($candidate, '/..') || $candidate === $public || str_starts_with($candidate, $public . '/')) {
            throw new RuntimeException('Diretório de documentos inválido ou público.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento privado.');
        }
        $resolved = realpath($directory);
        if ($resolved === false) { throw new RuntimeException('Armazenamento indisponível.'); }
        $canonical = strtolower(str_replace('\\', '/', $resolved));
        if ($canonical === $public || str_starts_with($canonical, $public . '/')) {
            throw new RuntimeException('Diretório público não permitido.');
        }
        $this->root = $resolved;
        $raw = $maximum ?? Config::string('CERTIDAO_PDF_MAX_BYTES', '10485760');
        $limit = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 104857600]]);
        if ($limit === false) { throw new RuntimeException('Limite de PDF inválido.'); }
        $this->maximum = $limit;
    }

    public static function rejectLinks(string $path): void
    {
        for ($part = $path; $part !== dirname($part); $part = dirname($part)) {
            if (is_link($part)) { throw new RuntimeException('Links simbólicos não são permitidos.'); }
        }
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T */
    public function locked(callable $operation): mixed
    {
        $path = $this->root . '/.storage.lock';
        self::rejectLinks($path);
        $lock = fopen($path, 'c');
        if ($lock === false) { throw new RuntimeException('Armazenamento indisponível.'); }
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new RuntimeException('Armazenamento ocupado.'); }
        try { return $operation(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /**
     * @param array<string,mixed> $upload
     * @return array{key:string,name:string,bytes:int,hash:string} */
    public function receive(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])) {
            throw new DomainException('Envie um PDF completo e válido. Verifique também o limite do servidor.');
        }
        return $this->copyValidated($upload['tmp_name'], is_string($upload['name'] ?? null) ? $upload['name'] : '');
    }

    /** For controlled CLI import and synthetic tests; HTTP callers must use receive().
     *
     * @return array{key:string,name:string,bytes:int,hash:string}
     */
    public function importLocal(string $path, string $name): array
    {
        if (PHP_SAPI !== 'cli') { throw new RuntimeException('Importação local restrita ao CLI.'); }
        return $this->copyValidated($path, $name);
    }

    /**
     * @return array{key:string,name:string,bytes:int,hash:string} */
    private function copyValidated(string $path, string $name): array
    {
        self::rejectLinks($path);
        if (!is_file($path) || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 180
            || preg_match('/^[^\x00-\x1f\x7f\/\\\\.]+\.pdf$/iuD', $name) !== 1) {
            throw new DomainException('Use um nome simples com uma única extensão .pdf.');
        }
        $size = filesize($path);
        if ($size === false || $size < 8 || $size > $this->maximum) { throw new DomainException('PDF vazio ou acima do limite permitido.'); }
        if (!class_exists(finfo::class)) { throw new RuntimeException('A extensão fileinfo é obrigatória.'); }
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf' || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw new DomainException('O arquivo não contém um PDF válido.');
        }
        $key = bin2hex(random_bytes(24)) . '.pdf';
        $stage = $this->root . '/' . $key . '.stage';
        $target = $this->root . '/' . $key;
        try {
            if (!copy($path, $stage)) { throw new RuntimeException('Falha ao gravar o PDF.'); }
            @chmod($stage, 0600);
            $hash = hash_file('sha256', $stage);
            if (filesize($stage) !== $size || $hash === false || $hash !== hash_file('sha256', $path) || !rename($stage, $target)) {
                throw new RuntimeException('Falha ao disponibilizar o PDF.');
            }
            return ['key' => $key, 'name' => $name, 'bytes' => $size, 'hash' => $hash];
        } finally {
            if (is_file($stage)) { unlink($stage); }
        }
    }

    public function path(string $key): string
    {
        if (preg_match('/^[a-f0-9]{48}\.pdf$/D', $key) !== 1) { throw new DomainException('Documento privado indisponível.'); }
        $path = $this->root . '/' . $key;
        self::rejectLinks($path);
        if (!is_file($path)) { throw new DomainException('Documento ausente; solicite revisão do cadastro.'); }
        return $path;
    }

    /** Remove only a newly created, unreferenced file while holding locked(). */
    public function compensate(string $key, PDO $pdo): void
    {
        $q = $pdo->prepare('SELECT 1 FROM certidoes WHERE pdf_privado = ?');
        $q->execute([$key]);
        if ($q->fetchColumn() === false) { unlink($this->path($key)); }
    }

    /** Conservative inventory only; no purge of documents.
     * @return list<array{key:string,reason:string}> */
    public function reconcile(PDO $pdo): array
    {
        return $this->locked(function () use ($pdo): array {
            $issues = [];
            foreach (glob($this->root . '/*') ?: [] as $path) {
                if (is_link($path) || !is_file($path) || filemtime($path) > time() - 86400) { continue; }
                $key = basename($path);
                if (preg_match('/^[a-f0-9]{48}\.pdf(?:\.stage)?$/D', $key) !== 1) { continue; }
                $q = $pdo->prepare('SELECT 1 FROM certidoes WHERE pdf_privado = ?');
                $q->execute([$key]);
                if ($q->fetchColumn() === false) { $issues[] = ['key' => $key, 'reason' => 'Órfão ou staging antigo: preservar para revisão.']; }
            }
            return $issues;
        });
    }
}
