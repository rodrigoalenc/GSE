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

    public function criarBackupManual()
    {
        try {
            $pastaLocal = ROOT_PATH . '/database/backups/';
            $arquivoBanco = $_ENV['DB_PATH'] ?? getenv('DB_PATH');

            if (!$arquivoBanco || !is_file($arquivoBanco) || !is_readable($arquivoBanco)) {
                throw new Exception('Arquivo de banco de dados original nao encontrado para backup.');
            }

            if (!is_dir($pastaLocal) && !mkdir($pastaLocal, 0755, true)) {
                throw new Exception('Nao foi possivel criar a pasta local de backups.');
            }

            $nome = 'escola_backup_MANUAL_' . date('Y-m-d_H-i-s') . '.db';
            $destinoLocal = $pastaLocal . $nome;

            if (!copy($arquivoBanco, $destinoLocal)) {
                return false;
            }

            return $nome;
        } catch (Exception $e) {
            error_log('Erro CRITICO no Model Sistema (criarBackupManual): ' . $e->getMessage());
            return false;
        }
    }

    public function listarBackups()
    {
        try {
            $pasta = ROOT_PATH . '/database/backups/';

            if (!is_dir($pasta)) {
                return [];
            }

            $arquivos = glob($pasta . '*.db') ?: [];

            usort($arquivos, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            return $arquivos;
        } catch (Exception $e) {
            error_log('Erro no Model Sistema (listarBackups): ' . $e->getMessage());
            return [];
        }
    }
}
