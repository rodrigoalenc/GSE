<?php

declare(strict_types=1);

/** The child has no application bootstrap, database, session or secret environment. */
final class AlunoPdfWorker
{
    public const TIMEOUT_SECONDS = 12.0;
    public const MEMORY_LIMIT = '192M';
    public const MAX_CONCURRENT = 2;
    private const MAX_OUTPUT_BYTES = 8_388_608;

    public function __construct(private ?string $worker = null, private float $timeout = self::TIMEOUT_SECONDS, private ?string $lockDirectory = null)
    {
        $this->timeout = max(0.1, min(self::TIMEOUT_SECONDS, $timeout));
    }

    /** @return array<string,mixed> */
    public function parse(string $path): array
    {
        if (!function_exists('proc_open')) { throw new DomainException('A leitura isolada de PDF está indisponível. Contate o responsável técnico.'); }
        $base = $this->lockDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-pdf-' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 20);
        if ((!is_dir($base) && !@mkdir($base, 0700) && !is_dir($base)) || is_link($base) || !is_writable($base)) {
            throw new DomainException('Não foi possível preparar a leitura segura do PDF.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($base) & 0077) !== 0) {
            throw new DomainException('O diretório temporário do leitor PDF precisa de acesso privado.');
        }
        $slot = $this->acquire($base);
        $process = null;
        $output = false;
        $errors = false;
        try {
            $output = $this->temporaryOutput();
            $errors = $this->temporaryOutput();
            $binary = $this->phpBinary();
            $command = [$binary];
            $ini = php_ini_loaded_file();
            if (is_string($ini)) { array_push($command, '-c', $ini); }
            foreach (['memory_limit=' . self::MEMORY_LIMIT, 'max_execution_time=10', 'display_errors=0', 'log_errors=0', 'html_errors=0', 'auto_prepend_file=', 'auto_append_file=', 'allow_url_fopen=0'] as $setting) {
                array_push($command, '-d', $setting);
            }
            array_push($command, $this->worker ?? dirname(__DIR__, 2) . '/bin/parse-students-pdf.php', $path, (string) PHP_VERSION_ID);
            $environment = [];
            foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
                $value = getenv($name);
                if (is_string($value)) { $environment[$name] = $value; }
            }
            $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => $errors], $pipes, dirname(__DIR__, 2), $environment, ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($process)) { throw new DomainException('Não foi possível iniciar a leitura isolada do PDF.'); }
            $started = hrtime(true);
            do {
                $status = proc_get_status($process);
                $outputSize = fstat($output);
                $errorSize = fstat($errors);
                if (($outputSize !== false && $outputSize['size'] > self::MAX_OUTPUT_BYTES) || ($errorSize !== false && $errorSize['size'] > 65_536)) {
                    throw new DomainException('O PDF produziu conteúdo excessivo. Exporte arquivos menores.');
                }
                if (!$status['running']) { break; }
                if ((hrtime(true) - $started) / 1_000_000_000 >= $this->timeout) {
                    throw new DomainException('O PDF excedeu o tempo de leitura permitido. Exporte arquivos menores.');
                }
                usleep(20_000);
            } while (true);
            $exitCode = $status['exitcode'];
            proc_close($process);
            $process = null;
            rewind($output);
            $json = stream_get_contents($output, self::MAX_OUTPUT_BYTES + 1);
            $result = is_string($json) ? json_decode($json, true, 16) : null;
            if (!is_array($result) || !is_bool($result['ok'] ?? null)) {
                throw new DomainException('O leitor PDF foi interrompido ou ultrapassou os limites de recursos. Nenhum aluno foi importado.');
            }
            if ($result['ok'] === false) {
                throw new DomainException(is_string($result['message'] ?? null) ? $result['message'] : 'Não foi possível ler este PDF.');
            }
            if ($exitCode !== 0 || !is_array($result['report'] ?? null)) {
                throw new DomainException('O leitor PDF não concluiu a análise. Nenhum aluno foi importado.');
            }
            return $result['report'];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                usleep(20_000);
                if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                proc_close($process);
            }
            if (is_resource($output)) { fclose($output); }
            if (is_resource($errors)) { fclose($errors); }
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    private function phpBinary(): string
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_file(PHP_BINARY)) { return PHP_BINARY; }
        $ini = php_ini_loaded_file();
        $directories = array_unique([dirname(PHP_BINARY), ...(is_string($ini) ? [dirname($ini)] : []), PHP_BINDIR]);
        $names = PHP_OS_FAMILY === 'Windows' ? ['php.exe'] : ['php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'php'];
        foreach ($names as $name) {
            foreach ($directories as $directory) {
                $candidate = $directory . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate) && is_executable($candidate)) { return $candidate; }
            }
        }
        throw new DomainException('O leitor PDF requer o PHP CLI da mesma versão instalado junto ao servidor PHP.');
    }

    /** @return resource */
    private function temporaryOutput()
    {
        $handle = tmpfile();
        if ($handle === false) { throw new DomainException('Não foi possível preparar a leitura segura do PDF.'); }
        // Unix keeps the descriptor readable without a directory entry, even if
        // the request is killed. Windows tmpfile is closed in the finally block.
        if (PHP_OS_FAMILY !== 'Windows') {
            $metadata = stream_get_meta_data($handle);
            if (!@unlink($metadata['uri'])) {
                fclose($handle);
                throw new DomainException('Não foi possível proteger a saída temporária do leitor PDF.');
            }
        }
        return $handle;
    }

    /** @return resource */
    private function acquire(string $base)
    {
        for ($index = 0; $index < self::MAX_CONCURRENT; $index++) {
            $path = $base . DIRECTORY_SEPARATOR . 'slot-' . $index . '.lock';
            if (is_link($path)) { continue; }
            $handle = @fopen($path, 'c');
            if ($handle === false) { continue; }
            @chmod($path, 0600);
            if (flock($handle, LOCK_EX | LOCK_NB)) { return $handle; }
            fclose($handle);
        }
        throw new DomainException('O leitor PDF está ocupado. Aguarde e envie o arquivo novamente.');
    }
}
