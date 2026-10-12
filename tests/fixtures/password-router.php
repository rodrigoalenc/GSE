<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('Roteador exclusivo dos testes HTTP.');
}

require_once __DIR__ . '/password-runtime.php';

return require dirname(__DIR__, 2) . '/public/index.php';
