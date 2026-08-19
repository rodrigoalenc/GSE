<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}

require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/RequestContext.php';
require_once ROOT_PATH . '/src/Core/TechnicalLogger.php';
require_once ROOT_PATH . '/src/Core/PasswordPolicy.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/Database.php';
require_once ROOT_PATH . '/src/Core/DatabaseInitializer.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Model/Usuario.php';

use src\Core\Database;
use src\Core\DatabaseInitializer;
use src\Core\SqliteTransaction;

$options = getopt('', ['name:', 'email:']);
$name = trim((string) ($options['name'] ?? ''));
$email = trim((string) ($options['email'] ?? ''));

if ($name === '' || $email === '') {
    fwrite(STDERR, "Uso: php bin/create-admin.php --name=\"Nome\" --email=admin@exemplo.local\n");
    exit(1);
}

$password = (string) (getenv('GSE_ADMIN_PASSWORD') ?: '');
$generatedPassword = false;

if ($password === '') {
    $password = 'GSE-' . bin2hex(random_bytes(12));
    $generatedPassword = true;
}

try {
    TechnicalLogger::configure();
    $pdo = Database::getConnection();
    DatabaseInitializer::initialize($pdo);
    Model::setConexao($pdo);

    $users = new Usuario();

    $created = SqliteTransaction::immediate($pdo, static function () use ($users, $name, $email, $password): array|false {
        if ($users->contarAdministradoresAtivos() > 0) {
            throw new RuntimeException('Já existe um administrador ativo. Cadastre novos administradores pela interface.');
        }

        if (!$users->cadastrar($name, $email, $password, Usuario::PERFIL_ADMINISTRADOR, true)) {
            throw new RuntimeException('Não foi possível criar o administrador. Verifique nome, e-mail e política de senha.');
        }

        return $users->buscarPorEmail($email);
    });

    AuditLogger::record(
        'user.initial_admin_created',
        AuditLogger::SUCCESS,
        null,
        is_array($created) ? (int) $created['id'] : null,
        'Primeiro administrador criado com senha temporária.'
    );

    echo "Administrador criado com sucesso para {$email}.\n";

    if ($generatedPassword) {
        echo "Senha temporaria gerada (anote agora): {$password}\n";
        echo "Ela deverá ser alterada no primeiro acesso e não será exibida novamente.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha ao criar administrador: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
