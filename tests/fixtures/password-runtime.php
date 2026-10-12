<?php

declare(strict_types=1);

// CLI auto_prepend_file or explicit HTTP test router include; never loaded by the application.
$testDb = (string) getenv('DB_PATH');
$testParent = dirname($testDb);
$disposable = (realpath(dirname($testParent)) === realpath(sys_get_temp_dir())
        && str_starts_with(basename($testParent), 'gse-password-browser-'))
    || (realpath($testParent) === realpath(sys_get_temp_dir()) && str_starts_with(basename($testDb), 'tcc_phpunit_'));
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true) || !$disposable) {
    throw new RuntimeException('Disposable password test database required.');
}
require_once dirname(__DIR__, 2) . '/src/Core/PasswordPolicy.php';
require_once dirname(__DIR__) . '/Support/PasswordRuntime.php';
$testModeFile = $testDb . '.password-runtime';
if (is_file($testModeFile)) {
    $testMode = trim((string) file_get_contents($testModeFile));
    if (in_array($testMode, ['unavailable', 'failure'], true)) {
        Tests\Support\PasswordRuntime::inject($testMode);
    }
}
