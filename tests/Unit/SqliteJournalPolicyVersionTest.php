<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SqliteJournalPolicyVersionTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(\SqliteJournalPolicy::class), 'A política de journal deve selecionar DELETE para SQLite sem correção WAL.');
    }

    public function testSupportedVersionsIncludeUbuntuAndOnlyTheMaintainedOlderBackport(): void
    {
        foreach (['3.44.6', '3.44.9', '3.45.1', '3.49.2', '3.50.6', '3.51.2', '3.53.4', '4.0.0'] as $version) {
            self::assertTrue(\SqliteJournalPolicy::supported($version), $version);
        }
        foreach (['3.43.99', '3.44.5', '3.45.0', '3.45', '3.45.1-RC1', '3.51.3beta', '3.51.3.0', 'v3.53.4', ' 3.53.4', '03.53.4', 'not-a-version', ''] as $version) {
            self::assertFalse(\SqliteJournalPolicy::supported($version), $version);
            self::assertFalse(\SqliteJournalPolicy::walSafe($version), $version);
        }
    }

    public function testWalFixThresholdsAreBranchSpecificAndDefaultToSafeJournal(): void
    {
        foreach (['3.44.6', '3.44.99', '3.50.7', '3.50.99', '3.51.3', '3.52.0', '4.0.0'] as $version) {
            self::assertTrue(\SqliteJournalPolicy::walSafe($version), $version);
            self::assertSame('WAL', \SqliteJournalPolicy::preferred($version));
            self::assertSame('DELETE', \SqliteJournalPolicy::preferred($version, 'delete'));
            self::assertTrue(\SqliteJournalPolicy::allowed($version, 'WAL'));
        }
        foreach (['3.45.1', '3.49.2', '3.50.6', '3.51.0', '3.51.2'] as $version) {
            self::assertFalse(\SqliteJournalPolicy::walSafe($version), $version);
            self::assertSame('DELETE', \SqliteJournalPolicy::preferred($version));
            self::assertTrue(\SqliteJournalPolicy::allowed($version, 'DELETE'));
            self::assertFalse(\SqliteJournalPolicy::allowed($version, 'WAL'));
        }
        foreach (['OFF', 'MEMORY', 'PERSIST', 'TRUNCATE', 'invalid'] as $mode) {
            self::assertFalse(\SqliteJournalPolicy::allowed('3.53.4', $mode));
        }
        self::assertFalse(\SqliteJournalPolicy::allowed('3.44.5', 'DELETE'));
    }

    public function testRequestCannotForceWalEvenOnPatchedSqlite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \SqliteJournalPolicy::preferred('3.53.4', 'wal');
    }

    public function testUnsupportedVersionCannotSelectAnyJournal(): void
    {
        $this->expectException(\RuntimeException::class);
        \SqliteJournalPolicy::preferred('3.45.0', 'delete');
    }
}
