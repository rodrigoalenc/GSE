<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

$runtimeFile = ROOT_PATH . '/src/Core/RuntimeRequirements.php';
if (is_file($runtimeFile)) {
    require_once $runtimeFile;
}

final class RuntimeRequirementsTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(\RuntimeRequirements::class), 'A instalação deve verificar PHP, arquitetura e SQLite antes de abrir dados.');
    }

    public function testUnpatchedPhpAnd32BitRuntimeAreRejectedIndependently(): void
    {
        $issues = \RuntimeRequirements::issues('8.4.13', 4, '3.53.4');
        self::assertCount(2, $issues);
        self::assertStringContainsString('PHP', $issues[0]);
        self::assertStringContainsString('64 bits', $issues[1]);
        self::assertNotEmpty(\RuntimeRequirements::issues('8.3.35', 8, '3.53.4'));
        self::assertNotEmpty(\RuntimeRequirements::issues('8.5.11', 8, '3.53.4'));
    }

    public function testPatchedSupportedBranchesAndSqliteBackportsAreAccepted(): void
    {
        foreach ([['8.3.36', '3.44.6'], ['8.4.27', '3.50.7'], ['8.5.12', '3.51.3'], ['8.4.28', '3.53.4']] as [$php, $sqlite]) {
            self::assertSame([], \RuntimeRequirements::issues($php, 8, $sqlite));
        }
    }

    public function testKnownWalAffectedVersionsAndReleaseCandidatesAreRejected(): void
    {
        foreach (['3.49.2', '3.44.5', '3.45.1', '3.50.6', '3.51.2'] as $sqlite) {
            self::assertNotEmpty(\RuntimeRequirements::issues('8.4.27', 8, $sqlite));
        }
        self::assertNotEmpty(\RuntimeRequirements::issues('8.4.27RC1', 8, '3.53.4'));
        self::assertNotEmpty(\RuntimeRequirements::issues('8.6.0RC1', 8, '3.53.4'));
    }

    public function testInspectionNeverOpensConfiguredInstitutionalDatabase(): void
    {
        $before = getenv('DB_PATH');
        putenv('DB_PATH=' . sys_get_temp_dir() . '/gse-must-not-create-' . bin2hex(random_bytes(8)) . '.sqlite');
        $path = (string) getenv('DB_PATH');
        try {
            $report = \RuntimeRequirements::inspect();
            self::assertSame(PHP_VERSION, $report['php']);
            self::assertSame(PHP_INT_SIZE * 8, $report['bits']);
            self::assertFalse(is_file($path));
            self::assertIsArray($report['errors']);
        } finally {
            $before === false ? putenv('DB_PATH') : putenv('DB_PATH=' . $before);
        }
    }
}
