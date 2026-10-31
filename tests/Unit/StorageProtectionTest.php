<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Core/StorageProtection.php';

final class StorageProtectionTest extends TestCase
{
    public function testMissingUnverifiedOrUnencryptedPathsFailClosed(): void
    {
        self::assertFalse(\StorageProtection::inspect([])['ok']);
        $paths = array_fill_keys(['database', 'documents', 'sessions', 'logs', 'keys', 'temporary', 'backups'], sys_get_temp_dir());
        self::assertFalse(\StorageProtection::inspect($paths, static fn (string $path): array => ['encrypted' => false, 'private' => true])['ok']);
        self::assertFalse(\StorageProtection::inspect($paths, static fn (string $path): array => ['encrypted' => true, 'private' => false])['ok']);
        self::assertTrue(\StorageProtection::inspect($paths, static fn (string $path): array => ['encrypted' => true, 'private' => true])['ok']);
        self::assertFalse(\StorageProtection::inspect($paths, static function (string $path): array { throw new \RuntimeException('synthetic'); })['ok']);
    }

    public function testPosixRequiresPrivateDirectoryAndFileModes(): void
    {
        $method = new \ReflectionMethod(\StorageProtection::class, 'privatePosixMode');
        self::assertTrue($method->invoke(null, 0100600, false));
        self::assertTrue($method->invoke(null, 0040700, true));
        foreach ([0640, 0660, 0604, 0644, 0700] as $mode) { self::assertFalse($method->invoke(null, $mode, false)); }
        foreach ([0750, 0770, 0704, 0777, 0600] as $mode) { self::assertFalse($method->invoke(null, $mode, true)); }
        self::assertFalse(\StorageProtection::privatePath(sys_get_temp_dir() . '/gse-no-such-path-' . bin2hex(random_bytes(8))));
    }

    public function testExplicitPermissionForAnotherWindowsUserAndBroadParentFailClosed(): void
    {
        $method = new \ReflectionMethod(\StorageProtection::class, 'privateWindowsAcls');
        $current = 'S-1-5-21-100-200-300-1001';
        $base = ['owner' => $current, 'current' => $current, 'entries' => [
            ['sid' => $current, 'allow' => true], ['sid' => 'S-1-5-18', 'allow' => true], ['sid' => 'S-1-5-32-544', 'allow' => true],
        ]];
        self::assertTrue($method->invoke(null, [$base], false));
        self::assertTrue($method->invoke(null, [$base, $base], true));
        self::assertFalse($method->invoke(null, [$base], true), 'A file requires evidence for its parent directory.');
        $other = $base;
        $other['entries'][] = ['sid' => 'S-1-5-21-100-200-300-1002', 'allow' => true];
        self::assertFalse($method->invoke(null, [$other], false));
        self::assertFalse($method->invoke(null, [$base, $other], true));
        foreach (['S-1-1-0', 'S-1-5-11', 'S-1-5-32-545', 'S-1-5-7'] as $sid) {
            $broad = $base; $broad['entries'][] = ['sid' => $sid, 'allow' => true];
            self::assertFalse($method->invoke(null, [$broad], false));
        }
        self::assertFalse($method->invoke(null, [['entries' => []]], false));
    }

    public function testEveryBackingBranchMustCrossEncryptionRatherThanOnlyOneSibling(): void
    {
        $method = new \ReflectionMethod(\StorageProtection::class, 'hasCrypt');
        $crypt = ['type' => 'crypt', 'children' => [['type' => 'part', 'children' => [['type' => 'disk']]]]];
        self::assertTrue($method->invoke(null, ['blockdevices' => [['type' => 'lvm', 'children' => [$crypt]]]]));
        self::assertTrue($method->invoke(null, ['blockdevices' => [['type' => 'lvm', 'children' => [$crypt, $crypt]]]]));
        self::assertFalse($method->invoke(null, ['blockdevices' => [['type' => 'lvm', 'children' => [$crypt, ['type' => 'part']]]]]));
        self::assertFalse($method->invoke(null, ['blockdevices' => [$crypt, ['type' => 'disk']]]));
        self::assertFalse($method->invoke(null, ['blockdevices' => []]));
        self::assertFalse($method->invoke(null, ['blockdevices' => [['children' => [$crypt]]]]));
    }
}
