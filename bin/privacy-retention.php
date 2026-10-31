<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/src/Core/RuntimeRequirements.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/PrivacyRetention.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/StorageProtection.php';

function rejectRetentionLinks(string $path): void
{
    for ($current = $path; ; $current = dirname($current)) {
        if (is_link($current)) { throw new DomainException('Caminhos com links não são aceitos.'); }
        if ($current === dirname($current)) { return; }
    }
}

// Explicit paths only: this command never loads the institutional .env implicitly.
$options = getopt('', ['database:', 'keyring:', 'policy:', 'plan:', 'apply', 'confirm:', 'actor:', 'reviewer:', 'maintenance-confirmed']);
try {
    RuntimeRequirements::assertSupported();
    foreach (['database', 'keyring', 'plan'] as $key) {
        if (!isset($options[$key]) || !is_string($options[$key]) || preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $options[$key]) !== 1) { throw new DomainException('Informe caminhos absolutos --database, --keyring e --plan.'); }
        rejectRetentionLinks($options[$key]);
    }
    $_ENV['DB_PATH'] = $options['database'];
    $_ENV['GSE_KEYRING_PATH'] = $options['keyring'];
    if (!is_file($options['database']) || is_link($options['database'])) { throw new DomainException('Banco existente privado exigido.'); }
    $apply = isset($options['apply']);
    if ($apply && !isset($options['maintenance-confirmed'])) { throw new DomainException('Pare os escritores e informe --maintenance-confirmed antes da aplicação.'); }
    $pdo = new PDO('sqlite:' . $options['database'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLITE_ATTR_OPEN_FLAGS => $apply ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    if (!$apply) { $pdo->exec('PRAGMA query_only=ON'); }
    \src\Core\DatabaseInitializer::assertCurrent($pdo);
    if ($apply) {
        if (!is_file($options['plan']) || !StorageProtection::privatePath($options['plan']) || filesize($options['plan']) > 1048576) { throw new DomainException('Plano privado inválido.'); }
        $plan = json_decode((string) file_get_contents($options['plan']), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($plan)) { throw new DomainException('Plano inválido.'); }
        $count = PrivacyRetention::apply($pdo, $plan, (string) ($options['confirm'] ?? ''), (int) ($options['actor'] ?? 0), (int) ($options['reviewer'] ?? 0));
        echo "Dados operacionais tratados em {$count} aluno(s). Identidade, documentos e vínculos foram preservados.\n";
    } else {
        if (!is_string($options['policy'] ?? null) || preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $options['policy']) !== 1
            || !is_file($options['policy']) || !StorageProtection::privatePath($options['policy'])
            || filesize($options['policy']) > 65536) { throw new DomainException('Informe --policy com caminho absoluto privado da política institucional aprovada.'); }
        rejectRetentionLinks($options['policy']);
        $policy = json_decode((string) file_get_contents($options['policy']), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($policy)) { throw new DomainException('Política inválida.'); }
        $plan = PrivacyRetention::plan($pdo, $policy);
        $directory = realpath(dirname($options['plan']));
        $public = strtolower(str_replace('\\', '/', (string) realpath(ROOT_PATH . '/public')));
        if ($directory === false || !StorageProtection::privatePath($directory)
            || str_starts_with(strtolower(str_replace('\\', '/', $directory)) . '/', $public . '/')) { throw new DomainException('Escolha diretório privado fora de public para o plano.'); }
        $handle = @fopen($options['plan'], 'xb');
        if ($handle === false) { throw new DomainException('O destino do plano deve ser novo.'); }
        $complete = false;
        try {
            if (DIRECTORY_SEPARATOR === '/' && !chmod($options['plan'], 0600)) { throw new RuntimeException('Proteção do plano falhou.'); }
            $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) { throw new RuntimeException('Gravação incompleta.'); }
            $complete = true;
        } finally { fclose($handle); if (!$complete) { @unlink($options['plan']); } }
        echo 'Prévia criada: ' . count($plan['students']) . " aluno(s). Nenhum cadastro alterado. Conferência HMAC: " . $plan['confirmation'] . "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof DomainException ? $error->getMessage() . "\n" : "Não foi possível executar a retenção. Confira caminhos privados, permissões e política.\n");
    exit(1);
}
