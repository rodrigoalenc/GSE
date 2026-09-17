<?php

declare(strict_types=1);

require_once __DIR__ . '/certidao-bootstrap.php';
require_once ROOT_PATH . '/src/Services/CertidaoNotificationService.php';

try {
    if (!Config::isProduction() || !Config::bool('MAIL_ENABLED') || !Config::bool('CERTIDAO_MAIL_ENABLED')) {
        fwrite(STDOUT, "Envio real de certidões desabilitado.\n"); exit(0);
    }
    $pdo = src\Core\Database::getConnection();
    src\Core\DatabaseInitializer::initialize($pdo);
    $result = (new CertidaoNotificationService($pdo, PhpMailerTransport::fromConfig()))->notify();
    fwrite(STDOUT, sprintf("Certidões: %d aviso(s), %d destinatário(s), %d envio(s), %d já enviado(s), %d falha(s).\n", ...array_values($result)));
    exit($result['failed'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    TechnicalLogger::error('certidao_notification_command_failed',['exception'=>$e::class]);
    fwrite(STDERR,"Falha nas notificações de certidões. Consulte o log técnico.\n"); exit(1);
}
