<?php

require_once ROOT_PATH . '/src/Core/Model.php';

class Sistema extends Model
{
    public function listarLogs($limite = 500)
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

    public function limparLogsAntigos($dias = 365)
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
            if (is_link($pastaLocal)) {
                throw new Exception('A pasta de backups nao pode ser um link simbolico.');
            }
            if (!is_dir($pastaLocal) && !@mkdir($pastaLocal, 0700, true) && !is_dir($pastaLocal)) {
                throw new Exception('Nao foi possivel criar a pasta local de backups.');
            }
            if (DIRECTORY_SEPARATOR === '/' && !chmod($pastaLocal, 0700)) {
                throw new Exception('Nao foi possivel proteger a pasta de backups.');
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

    public function listarBackups()
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
}
