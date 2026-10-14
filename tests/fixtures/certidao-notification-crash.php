<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
require_once ROOT_PATH . '/src/Services/CertidaoNotificationService.php';

$pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$transport = new class implements MailTransport {
    public function send(string $a, string $n, string $s, string $h, string $t): void
    {
        // Simulate process termination during network I/O, without finally cleanup.
        fwrite(STDOUT, "SMTP_READY\n");
        fflush(STDOUT);
        fgets(STDIN);
        exit(73);
    }
};
(new CertidaoNotificationService($pdo, $transport, new CertidaoStatus(new DateTimeImmutable('2026-09-16T12:00:00Z'))))->notify();
