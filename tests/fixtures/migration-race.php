<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/src/Core/SqliteTransaction.php';
require ROOT_PATH . '/src/Core/DatabaseInitializer.php';

[$script, $database, $barrier, $worker] = $argv;
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA busy_timeout=10000');
$pdo->exec('PRAGMA foreign_keys=ON');
// All workers decide that v21 is missing before any of them acquires its lock.
if ((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=21')->fetchColumn() !== 0) {
    exit(2);
}
file_put_contents($barrier . '.' . $worker, 'ready');
for ($attempt = 0; $attempt < 1000 && !is_file($barrier); $attempt++) {
    usleep(10000);
}
if (!is_file($barrier)) {
    exit(3);
}
try {
    (new ReflectionMethod(src\Core\DatabaseInitializer::class, 'runMigration'))->invoke(
        null, $pdo, 21, (string) file_get_contents(ROOT_PATH . '/database/schema.sql')
    );
    echo 'OK';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
