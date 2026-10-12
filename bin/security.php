<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/src/Core/Helpers.php';
if (is_file(ROOT_PATH . '/.env')) { carregar_env(ROOT_PATH . '/.env'); }
require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/ProtectedBackup.php';

try {
    $command = $argv[1] ?? '';
    if ($command === 'keygen') {
        $path = $argv[2] ?? '';
        $directory = realpath(dirname($path));
        $root = str_replace('\\','/', (string) realpath(ROOT_PATH));
        $normalized = str_replace('\\','/', (string) $directory);
        if ($directory === false || !preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $path)
            || str_starts_with(strtolower($normalized). '/', strtolower($root). '/') || is_link(dirname($path))
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($directory) & 0077) !== 0)) {
            throw new RuntimeException('Escolha um diretório absoluto privado fora do projeto.');
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) { throw new RuntimeException('Destino já existe ou indisponível.'); }
        try {
            if (!chmod($path,0600)) { throw new RuntimeException('Permissões indisponíveis.'); }
            $json = json_encode(['version'=>1,'active'=>'k1','keys'=>['k1'=>base64_encode(random_bytes(32))],
                'index_key'=>base64_encode(random_bytes(32))],JSON_THROW_ON_ERROR);
            if (fwrite($handle,$json) !== strlen($json) || !fflush($handle) || !fsync($handle)) { throw new RuntimeException('Gravação incompleta.'); }
        } catch (Throwable $exception) { fclose($handle); @unlink($path); throw $exception; }
        fclose($handle);
        echo "Keyring privado criado. Configure GSE_KEYRING_PATH e guarde cópia separada dos backups.\n";
    } elseif ($command === 'rotate-key') {
        $path = (string) ($_ENV['GSE_KEYRING_PATH'] ?? getenv('GSE_KEYRING_PATH'));
        DataProtection::keyring();
        if (($argv[2] ?? '') !== '--maintenance-confirmed') { throw new RuntimeException('Manutenção exigida.'); }
        // Lock a stable sibling; Windows cannot replace an open keyring file.
        $lockPath = $path . '.rotation.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Lock inválido.'); }
        $lock = fopen($lockPath, 'cb');
        chmod($lockPath,0600);
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('Keyring ocupado.'); }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $ring = json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            $id = 'k' . bin2hex(random_bytes(8));
            $ring['keys'][$id] = base64_encode(random_bytes(32));
            $ring['active'] = $id;
            $out = fopen($temporary,'xb');
            if ($out === false) { throw new RuntimeException('Destino indisponível.'); }
            chmod($temporary,0600);
            $json = json_encode($ring,JSON_THROW_ON_ERROR);
            try {
                if (fwrite($out,$json) !== strlen($json) || !fflush($out) || !fsync($out)) { throw new RuntimeException('Gravação incompleta.'); }
            } finally { fclose($out); }
            if (!rename($temporary,$path)) { throw new RuntimeException('Substituição atômica indisponível.'); }
        } finally { @unlink($temporary); flock($lock,LOCK_UN); fclose($lock); }
        echo "Nova chave ativa criada; todas as chaves antigas e o índice HMAC foram preservados.\n";
    } elseif ($command === 'restore') {
        $source = $argv[2] ?? ''; $target = $argv[3] ?? '';
        if ($target === '' || file_exists($target) || is_link($target) || is_link(dirname($target))) { throw new RuntimeException('Escolha um arquivo novo em diretório privado.'); }
        $parent = realpath(dirname($target));
        $public = strtolower(str_replace('\\','/',(string)realpath(ROOT_PATH . '/public')));
        $normalized = strtolower(str_replace('\\','/',(string)$parent));
        if ($parent === false || $normalized === $public || str_starts_with($normalized . '/', $public . '/')) {
            throw new RuntimeException('Restauração não pode usar a raiz pública.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms(dirname($target)) & 0077) !== 0) { throw new RuntimeException('Diretório de destino não privado.'); }
        ProtectedBackup::decrypt($source, $target);
        try {
            $pdo = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) { throw new RuntimeException('Integridade inválida.'); }
            DataProtection::register($pdo);
            if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 17) {
                foreach ($pdo->query('SELECT id,nome,email FROM usuarios') as $row) { DataProtection::user($row); }
            }
            $pdo = null;
        } catch (Throwable $exception) { $pdo = null; @unlink($target); throw $exception; }
        echo "Banco restaurado em arquivo novo; anexos não fazem parte deste backup.\n";
    } elseif (in_array($command, ['preflight','migrate','rotate-data'], true)) {
        DataProtection::keyring();
        $pdo = \src\Core\Database::getConnection();
        if ($command === 'preflight') {
            $scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-preflight-' . bin2hex(random_bytes(16));
            if (!mkdir($scratch,0700)) { throw new RuntimeException('Destino temporário indisponível.'); }
            $temp = $scratch . '/copy.sqlite';
            $reservation = fopen($temp,'xb');
            if ($reservation === false) { throw new RuntimeException('Destino temporário indisponível.'); }
            fclose($reservation);
            chmod($temp, 0600);
            try {
                $pdo->exec('VACUUM main INTO ' . $pdo->quote($temp));
                $copy = new PDO('sqlite:' . $temp, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
                \src\Core\DatabaseInitializer::initialize($copy, true);
                if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $copy->query('PRAGMA foreign_key_check')->fetchAll() !== []) { throw new RuntimeException('Integridade inválida.'); }
                $copy = null;
                echo "Simulação concluída em cópia descartável. Banco original preservado.\n";
            } finally {
                $copy = null;
                foreach (glob($scratch . '/backups/*') ?: [] as $file) { @unlink($file); }
                @rmdir($scratch . '/backups');
                foreach (glob($scratch . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($scratch);
            }
        } else {
            if (($argv[2] ?? '') !== '--maintenance-confirmed') { throw new RuntimeException('Pare HTTP e workers e use --maintenance-confirmed.'); }
            \src\Core\DatabaseInitializer::initialize($pdo, true);
            if ($command === 'rotate-data') {
                \src\Core\SqliteTransaction::immediate($pdo, static function (PDO $pdo): void {
                    $update = $pdo->prepare('UPDATE usuarios SET nome=?,email=? WHERE id=?');
                    foreach ($pdo->query('SELECT id,nome,email FROM usuarios')->fetchAll() as $row) {
                        $plain = DataProtection::user($row);
                        $update->execute([DataProtection::encrypt((string)$plain['nome'],'usuarios.nome',(int)$row['id']),
                            DataProtection::encrypt((string)$plain['email'],'usuarios.email',(int)$row['id']),$row['id']]);
                    }
                });
            }
            // Run only with exclusive operational access; never promise physical SSD erasure.
            $checkpoint = $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
            if ((int) $checkpoint[0] !== 0) { throw new RuntimeException('Checkpoint ocupado; mantenha manutenção e repita.'); }
            $pdo->exec('PRAGMA secure_delete=ON');
            $pdo->exec('VACUUM');
            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) { throw new RuntimeException('Integridade inválida.'); }
            echo "Proteção e reconstrução concluídas. Verifique restauração antes de reabrir o serviço.\n";
        }
    } elseif ($command === 'protect-legacy') {
        ProtectedBackup::encrypt($argv[2] ?? '', $argv[3] ?? '');
        echo "Cópia protegida criada; original preservado.\n";
    } else {
        throw new RuntimeException('Uso: security.php keygen arquivo | preflight | migrate --maintenance-confirmed | rotate-data --maintenance-confirmed | restore origem destino-novo | protect-legacy origem destino-novo');
    }
} catch (Throwable) {
    fwrite(STDERR, "Operação não concluída. Confira chaves, permissões, integridade e procedimento de manutenção.\n");
    exit(1);
}
