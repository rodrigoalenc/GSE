<?php

require_once ROOT_PATH . '/src/Core/Model.php';

class Sistema extends Model
{
    /** @return list<array<string,mixed>> */
    public function listarLogs(mixed $limite = 500): array
    {
        try {
            $limite = max(1, min((int) $limite, 1000));
            $stmt = self::$pdo->prepare('SELECT * FROM logs ORDER BY data_hora DESC LIMIT ?');
            $stmt->bindValue(1, $limite, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Erro no Model Sistema (listarLogs): ' . $e->getMessage());
            return [];
        }
    }

    public function limparLogsAntigos(mixed $dias = 365): int|false
    {
        try {
            $dias = max(1, min((int) $dias, 3650));
            $stmt = self::$pdo->prepare("DELETE FROM logs WHERE data_hora < date('now', ?)");
            $stmt->execute(["-{$dias} days"]);

            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('Erro no Model Sistema (limparLogsAntigos): ' . $e->getMessage());
            return false;
        }
    }

    public function criarBackupManual(): string|false
    {
        $destino = null;
        $snapshot = null;
        $origem = null;
        try {
            $arquivoBanco = \src\Core\Database::resolvePath();

            if (!is_file($arquivoBanco) || !is_readable($arquivoBanco)) {
                throw new Exception('Arquivo de banco de dados original nao encontrado para backup.');
            }

            $pastaLocal = $this->pastaBackups();
            if ($this->dentroDe($pastaLocal, ROOT_PATH . '/public')) {
                throw new Exception('O backup deve permanecer em armazenamento privado.');
            }
            if (is_link($pastaLocal)) {
                throw new Exception('A pasta de backups nao pode ser um link simbolico.');
            }
            if (!is_dir($pastaLocal) && !@mkdir($pastaLocal, 0700, true) && !is_dir($pastaLocal)) {
                throw new Exception('Nao foi possivel criar a pasta local de backups.');
            }
            if (DIRECTORY_SEPARATOR === '/' && !chmod($pastaLocal, 0700)) {
                throw new Exception('Nao foi possivel proteger a pasta de backups.');
            }
            if ($this->diretorioPrivado($pastaLocal) === null) {
                throw new Exception('O destino do backup nao e um diretorio privado seguro.');
            }

            $nome = 'escola_backup_MANUAL_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(16)) . '.db';
            $candidato = $pastaLocal . '/' . $nome;
            // Exclusive creation reserves only our file; VACUUM INTO accepts an empty target.
            $reserva = @fopen($candidato, 'x');
            if ($reserva === false) {
                throw new Exception('Nao foi possivel reservar o arquivo de backup.');
            }
            $destino = $candidato;
            fclose($reserva);
            if (DIRECTORY_SEPARATOR === '/' && !chmod($destino, 0600)) {
                throw new Exception('Nao foi possivel proteger o arquivo de backup.');
            }

            // A separate connection snapshots the configured source, including committed WAL pages.
            $origem = new PDO('sqlite:' . $arquivoBanco, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $origem->exec('PRAGMA busy_timeout = 5000');
            $origem->exec('VACUUM main INTO ' . $origem->quote($destino));
            $origem = null;
            $snapshot = new PDO('sqlite:' . $destino, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($snapshot->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']
                || $snapshot->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new Exception('O snapshot do banco falhou nas verificacoes de integridade.');
            }
            $snapshot = null;

            return $nome;
        } catch (Throwable $e) {
            $snapshot = null;
            $origem = null;
            if ($destino !== null && is_file($destino)) {
                @unlink($destino);
            }
            error_log('Erro CRITICO no Model Sistema (criarBackupManual): ' . $e->getMessage());
            return false;
        }
    }

    /** @return list<string> */
    public function listarBackups(): array
    {
        try {
            $arquivos = [];
            // Keep older installations' backups discoverable without moving their files.
            $pastas = array_unique([$this->pastaBackups(), str_replace('\\', '/', ROOT_PATH) . '/database/backups']);
            foreach ($pastas as $pasta) {
                if (is_dir($pasta) && !is_link($pasta)) {
                    $arquivos = array_merge($arquivos, glob($pasta . '/*.db') ?: []);
                }
            }

            usort($arquivos, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            return $arquivos;
        } catch (Exception $e) {
            error_log('Erro no Model Sistema (listarBackups): ' . $e->getMessage());
            return [];
        }
    }

    private function pastaBackups(): string
    {
        return dirname(\src\Core\Database::resolvePath()) . '/backups';
    }

    public function criarBackupAdministrativo(int $ator, int $versaoSessao, string $senhaAtual): string|false
    {
        $novo = null;
        $identidade = null;
        try {
            $pdo = $this->conexaoBackup();
            $usuario = $this->administradorAtual($pdo, $ator, $versaoSessao);
            if ($senhaAtual === '' || preg_match('//u', $senhaAtual) !== 1 || str_contains($senhaAtual, "\0")
                || mb_strlen($senhaAtual, 'UTF-8') > PasswordPolicy::MAX_LENGTH
                || !password_verify($senhaAtual, (string) $usuario['senha'])) {
                throw new DomainException('Confirmacao de acesso invalida.');
            }
            $hashVerificado = (string) $usuario['senha'];
            // Validate the source parent and the prospective destination before VACUUM can create a file.
            $source = \src\Core\Database::resolvePath();
            $sourceCanonical = realpath($source);
            $folder = $this->pastaBackups();
            if (is_link($source) || $sourceCanonical === false
                || $this->diretorioPrivado(dirname($source)) === null
                || $this->diretorioPrivado(dirname($sourceCanonical)) === null
                || ((file_exists($folder) || is_link($folder)) && $this->diretorioPrivado($folder) === null)) {
                throw new RuntimeException('Destino privado indisponivel.');
            }
            // VACUUM runs before the short audit transaction: it cannot run inside one.
            $nome = $this->criarBackupManual();
            if ($nome === false) {
                return false;
            }
            $novo = $this->resolverBackup($nome, [$this->pastaBackups()]);
            $identidade = @lstat($novo);
            if ($identidade === false) {
                throw new RuntimeException('Snapshot indisponivel.');
            }
            \src\Core\SqliteTransaction::immediate($pdo, function (PDO $pdo) use ($ator, $versaoSessao, $hashVerificado, $nome): void {
                $atual = $this->administradorAtual($pdo, $ator, $versaoSessao);
                if (!hash_equals($hashVerificado, (string) $atual['senha'])) {
                    throw new RuntimeException('Confirmacao de acesso expirada.');
                }
                AuditLogger::recordRequired($pdo, 'backup.created', AuditLogger::SUCCESS, $ator, null,
                    'Snapshot SQLite criado: ' . $nome, 'backup');
            });
            return $nome;
        } catch (Throwable $exception) {
            // Compensation only touches the file created by this operation, never an older backup.
            if ($novo !== null && is_array($identidade)) {
                $this->removerSnapshotCriado($novo, $identidade);
            }
            TechnicalLogger::error('administrative_backup_failed', ['exception' => $exception::class]);
            return false;
        }
    }

    /** @return array{items:list<array{nome:string,bytes:int,modificado:int}>,page:int,pages:int,total:int} */
    public function listarBackupsAdministrativos(int $ator, int $versaoSessao, int $pagina = 1): array
    {
        $this->administradorAtual($this->conexaoBackup(), $ator, $versaoSessao);
        $items = [];
        $nomes = [];
        foreach ($this->pastasBackupsPrivadas() as $pasta) {
            foreach (new DirectoryIterator($pasta) as $entry) {
                $nome = $entry->getFilename();
                if (!$this->nomeBackupValido($nome) || isset($nomes[$nome])) {
                    continue;
                }
                try {
                    $path = $this->resolverBackup($nome, [$pasta]);
                    $stat = @lstat($path);
                    if ($stat === false) {
                        continue;
                    }
                    $nomes[$nome] = true;
                    $items[] = ['nome' => $nome, 'bytes' => (int) $stat['size'], 'modificado' => (int) $stat['mtime']];
                    usort($items, static fn (array $a, array $b): int => ($b['modificado'] <=> $a['modificado']) ?: strcmp($b['nome'], $a['nome']));
                    // Bound displayed metadata even when an installation retains many snapshots.
                    if (count($items) > 200) {
                        array_pop($items);
                    }
                } catch (DomainException) {
                    continue;
                }
            }
        }
        $total = count($items);
        $pages = max(1, (int) ceil($total / 20));
        $pagina = max(1, min($pagina, $pages));
        return ['items' => array_slice($items, ($pagina - 1) * 20, 20), 'page' => $pagina, 'pages' => $pages, 'total' => $total];
    }

    /** @return array{handle:resource,nome:string,bytes:int} */
    public function abrirBackupAdministrativo(string $nome, int $ator, int $versaoSessao): array
    {
        $pdo = $this->conexaoBackup();
        $this->administradorAtual($pdo, $ator, $versaoSessao);
        $path = $this->resolverBackup($nome, $this->pastasBackupsPrivadas());
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new DomainException('Backup indisponivel.');
        }
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $atual = @lstat($path);
            if ($stat === false || $atual === false || !$this->mesmoArquivo($stat, $atual)
                || $this->resolverBackup($nome, $this->pastasBackupsPrivadas()) !== $path
                || fread($handle, 16) !== "SQLite format 3\0" || !rewind($handle)) {
                throw new DomainException('Backup indisponivel.');
            }
            \src\Core\SqliteTransaction::immediate($pdo, function (PDO $pdo) use ($ator, $versaoSessao, $nome): void {
                $this->administradorAtual($pdo, $ator, $versaoSessao);
                AuditLogger::recordRequired($pdo, 'backup.downloaded', AuditLogger::SUCCESS, $ator, null,
                    'Download de snapshot SQLite autorizado: ' . $nome, 'backup');
            });
            return ['handle' => $handle, 'nome' => $nome, 'bytes' => (int) $stat['size']];
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    private function conexaoBackup(): PDO
    {
        $path = \src\Core\Database::resolvePath();
        if (!is_file($path)) {
            throw new RuntimeException('Banco indisponivel.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        return $pdo;
    }

    /** @return array<string,mixed> */
    private function administradorAtual(PDO $pdo, int $ator, int $versaoSessao): array
    {
        $stmt = $pdo->prepare('SELECT id,tipo,ativo,session_version,deve_alterar_senha,senha FROM usuarios WHERE id=?');
        $stmt->execute([$ator]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario || $ator < 1 || $versaoSessao < 1
            || $usuario['tipo'] !== Usuario::PERFIL_ADMINISTRADOR || (int) $usuario['ativo'] !== 1
            || (int) $usuario['deve_alterar_senha'] !== 0 || (int) $usuario['session_version'] !== $versaoSessao) {
            throw new RuntimeException('Acesso administrativo indisponivel.');
        }
        return $usuario;
    }

    private function nomeBackupValido(string $nome): bool
    {
        // Legacy manual snapshots used the same timestamp without the random suffix.
        return preg_match('/\Aescola_backup_MANUAL_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(?:_[a-f0-9]{32})?\.db\z/D', $nome) === 1;
    }

    /** @return list<string> */
    private function pastasBackupsPrivadas(): array
    {
        $pastas = [];
        foreach ([$this->pastaBackups(), ROOT_PATH . '/database/backups'] as $pasta) {
            $canonical = $this->diretorioPrivado($pasta);
            if ($canonical !== null) {
                $pastas[$canonical] = $canonical;
            }
        }
        return array_values($pastas);
    }

    private function diretorioPrivado(string $pasta): ?string
    {
        $canonical = realpath($pasta);
        if ($canonical === false || !is_dir($canonical) || is_link($pasta)
            || $this->dentroDe($canonical, realpath(ROOT_PATH . '/public') ?: ROOT_PATH . '/public')) {
            return null;
        }
        // Reject linked ancestors as well as a symlink on the final directory.
        for ($ancestor = $pasta; dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                return null;
            }
        }
        return str_replace('\\', '/', $canonical);
    }

    /** @param list<string> $pastas */
    private function resolverBackup(string $nome, array $pastas): string
    {
        if (!$this->nomeBackupValido($nome) || basename($nome) !== $nome) {
            throw new DomainException('Backup indisponivel.');
        }
        foreach ($pastas as $pasta) {
            $pasta = $this->diretorioPrivado($pasta);
            if ($pasta === null) {
                continue;
            }
            $path = $pasta . '/' . $nome;
            clearstatcache(true, $path);
            $canonical = realpath($path);
            if ($canonical === false) {
                continue;
            }
            // A retained snapshot may disappear between directory discovery and stat.
            $stat = @lstat($path);
            if ($stat !== false && !is_link($path) && is_readable($path)
                && ($stat['mode'] & 0170000) === 0100000 && (int) $stat['nlink'] === 1
                && $this->dentroDe($canonical, $pasta) && dirname(str_replace('\\', '/', $canonical)) === $pasta) {
                return str_replace('\\', '/', $canonical);
            }
        }
        throw new DomainException('Backup indisponivel.');
    }

    private function dentroDe(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }
        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    /**
     * @param array<string|int,mixed> $a
     * @param array<string|int,mixed> $b
     */
    private function mesmoArquivo(array $a, array $b): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'mode', 'nlink'] as $key) {
            if ($a[$key] !== $b[$key]) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $identidade */
    private function removerSnapshotCriado(string $path, array $identidade): void
    {
        try {
            $safe = $this->resolverBackup(basename($path), [$this->pastaBackups()]);
            $atual = @lstat($safe);
            if ($safe === $path && is_array($atual) && $this->mesmoArquivo($identidade, $atual)) {
                if (!@unlink($safe)) {
                    TechnicalLogger::error('administrative_backup_cleanup_failed');
                }
            }
        } catch (Throwable) {
            TechnicalLogger::error('administrative_backup_cleanup_failed');
        }
    }
}
