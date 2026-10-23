<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TechnicalLoggerDiagnosticsTest extends TestCase
{
    public function testChainedDiagnosticsIdentifyRootCauseWithoutSensitiveMessagesOrArguments(): void
    {
        $directory = sys_get_temp_dir() . '/gse-diagnostics-' . bin2hex(random_bytes(6));
        $path = $directory . '/errors.log';
        $previous = $_ENV['LOG_PATH'] ?? null;
        $previousLog = \TechnicalLogger::path();
        $previousIniLog = ini_get('error_log');
        $_ENV['LOG_PATH'] = $path;
        try {
            \TechnicalLogger::configure();
            $secret = 'Maria Segredo maria@example.test cookie=COOKIE_SECRET senha=PASSWORD_SECRET';
            $root = new \PDOException($secret, 23000);
            $wrapped = new \RuntimeException($secret, 777, $root);
            \TechnicalLogger::exception('database_operation_failed', $wrapped);
            \TechnicalLogger::exception('validation_failed', new \RuntimeException($secret, 0, new \TypeError($secret)));
            $content = (string) file_get_contents($path);
            foreach (['Maria', 'maria@example.test', 'COOKIE_SECRET', 'PASSWORD_SECRET', dirname(__FILE__)] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content);
            }
            $records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($content)));
            $chain = $records[0]['exception_chain'];
            $this->assertSame(['RuntimeException', 'PDOException'], array_column($chain, 'type'));
            $this->assertSame(basename(__FILE__), $chain[1]['file']);
            $this->assertGreaterThan(0, $chain[1]['line']);
            $this->assertSame('database_constraint', $records[0]['cause_code']);
            $this->assertSame('type_error', $records[1]['cause_code']);
        } finally {
            if ($previous === null) { unset($_ENV['LOG_PATH']); } else { $_ENV['LOG_PATH'] = $previous; }
            (new \ReflectionProperty(\TechnicalLogger::class, 'path'))->setValue(null, $previousLog);
            ini_set('error_log', (string) $previousIniLog);
            @unlink($path);
            @rmdir($directory);
        }
    }
}
