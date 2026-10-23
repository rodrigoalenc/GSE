<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

$ubuntuFile = ROOT_PATH . '/src/Core/UbuntuPhpBackport.php';
if (is_file($ubuntuFile)) { require_once $ubuntuFile; }

final class UbuntuPhpBackportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        self::assertTrue(class_exists(\UbuntuPhpBackport::class), 'O verificador deve reconhecer pacotes Ubuntu corrigidos sem ignorar a segurança.');
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/gse-ubuntu-' . bin2hex(random_bytes(6));
        foreach (['etc', 'var/lib/dpkg/info', 'usr/bin', 'usr/sbin', 'usr/lib/apache2/modules', 'proc/self'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
        file_put_contents($this->root . '/etc/os-release', "ID=ubuntu\nVERSION_ID=\"24.04\"\n");
        $this->fixture('cli');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) { return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($this->root);
    }

    private function fixture(string $sapi, string $version = '8.3.6-0ubuntu0.24.04.13'): string
    {
        [$package, $path] = match ($sapi) {
            'fpm-fcgi' => ['php8.3-fpm', '/usr/sbin/php-fpm8.3'],
            'cgi-fcgi' => ['php8.3-cgi', '/usr/bin/php-cgi8.3'],
            'apache2handler' => ['libapache2-mod-php8.3', '/usr/lib/apache2/modules/libphp8.3.so'],
            default => ['php8.3-cli', '/usr/bin/php8.3'],
        };
        $binary = $this->root . $path;
        file_put_contents($binary, 'synthetic-patched-php-image');
        file_put_contents($this->root . '/proc/self/exe', 'synthetic-patched-php-image');
        clearstatcache(true, $binary);
        file_put_contents($this->root . '/proc/self/maps', '1000-2000 r-xp 00000000 08:01 ' . fileinode($binary) . ' ' . $binary . "\n");
        file_put_contents($this->root . '/var/lib/dpkg/status', "Package: {$package}\nStatus: install ok installed\nVersion: {$version}\n\n");
        file_put_contents($this->root . '/var/lib/dpkg/info/' . $package . '.md5sums', md5_file($binary) . '  ' . ltrim($path, '/') . "\n");
        return $binary;
    }

    public function testAcceptsPatchedPackageForTheActualSapiOnly(): void
    {
        foreach (['cli', 'cli-server', 'fpm-fcgi', 'cgi-fcgi', 'apache2handler'] as $sapi) {
            $binary = $this->fixture($sapi);
            $result = \UbuntuPhpBackport::inspect('8.3.6', $sapi, $binary, $this->root);
            self::assertSame('8.3.6-0ubuntu0.24.04.13', $result['version'] ?? null);
        }
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $this->root . '/usr/bin/php8.3', $this->root), 'Módulo Apache instalado não atesta uma CLI de outro pacote.');
    }

    public function testRejectsOldPackageDifferentDistributionAndCustomBinary(): void
    {
        $binary = $this->fixture('cli', '8.3.6-0ubuntu0.24.04.12');
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $binary, $this->root));
        $binary = $this->fixture('cli', '8.3.6-0ubuntu0.24.04.14');
        self::assertNotNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $binary, $this->root));
        file_put_contents($this->root . '/etc/os-release', "ID=debian\nVERSION_ID=12\n");
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $binary, $this->root));
        file_put_contents($this->root . '/etc/os-release', "ID=ubuntu\nVERSION_ID=\"24.04\"\n");
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $this->root . '/usr/bin/custom-php', $this->root));
        self::assertNull(\UbuntuPhpBackport::inspect('8.4.13', 'cli', $binary, $this->root));
    }

    public function testRejectsModifiedBinaryOrOldRunningProcessAfterUpgrade(): void
    {
        $binary = $this->fixture('cli');
        file_put_contents($this->root . '/proc/self/exe', 'old-vulnerable-running-php');
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $binary, $this->root));
        $binary = $this->fixture('cli');
        file_put_contents($binary, 'locally-replaced-php');
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'cli', $binary, $this->root));
        $binary = $this->fixture('apache2handler');
        file_put_contents($this->root . '/proc/self/maps', "1000-2000 r-xp 00000000 08:01 123 {$binary} (deleted)\n");
        self::assertNull(\UbuntuPhpBackport::inspect('8.3.6', 'apache2handler', $binary, $this->root));
    }
}
