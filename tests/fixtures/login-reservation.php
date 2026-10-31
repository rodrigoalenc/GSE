<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/src/Core/Config.php';
require_once ROOT_PATH . '/src/Core/DataProtection.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/LoginThrottle.php';
$pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');
echo (new LoginThrottle($pdo))->reserve('parallel@example.test', '192.0.2.9')['admitted'] ? '1' : '0';
