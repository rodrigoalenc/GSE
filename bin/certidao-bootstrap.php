<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__)); }
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/src/Core/Helpers.php';
if (is_file(ROOT_PATH . '/.env')) { carregar_env(ROOT_PATH . '/.env'); }
foreach (['Config','RequestContext','TechnicalLogger','AuditLogger','SqliteTransaction','Database','DatabaseInitializer','Model'] as $core) { require_once ROOT_PATH . '/src/Core/' . $core . '.php'; }
require_once ROOT_PATH . '/src/Model/Certidao.php';
require_once ROOT_PATH . '/src/Mail/MailTransport.php';
require_once ROOT_PATH . '/src/Mail/PhpMailerTransport.php';
TechnicalLogger::configure();
