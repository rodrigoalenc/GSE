<?php

declare(strict_types=1);

/** Recognizes the official Noble PHP security backport without a shell or DB. */
final class UbuntuPhpBackport
{
    /**
     * The filesystem root is injectable for isolated tests, never from configuration.
     * @return array{package:string,version:string}|null
     */
    public static function inspect(string $phpVersion, string $sapi, string $binary, string $root = '/'): ?array
    {
        if ($phpVersion !== '8.3.6') { return null; }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $release = self::read($root . '/etc/os-release', 16384);
        if ($release === null) { return null; }
        $fields = [];
        foreach (explode("\n", $release) as $line) {
            if (!str_contains($line, '=')) { continue; }
            [$key, $value] = explode('=', $line, 2);
            $fields[trim($key)] = trim(trim($value), "\"'");
        }
        if (($fields['ID'] ?? '') !== 'ubuntu' || ($fields['VERSION_ID'] ?? '') !== '24.04') { return null; }
        $target = match ($sapi) {
            'cli', 'cli-server' => ['php8.3-cli', '/usr/bin/php8.3'],
            'fpm-fcgi' => ['php8.3-fpm', '/usr/sbin/php-fpm8.3'],
            'cgi-fcgi', 'cgi' => ['php8.3-cgi', '/usr/bin/php-cgi8.3'],
            'apache2handler' => ['libapache2-mod-php8.3', '/usr/lib/apache2/modules/libphp8.3.so'],
            default => null,
        };
        if ($target === null) { return null; }
        [$package, $relative] = $target;
        $installedFile = $root . $relative;
        $packageStatus = self::read($root . '/var/lib/dpkg/status', 16777216);
        if ($packageStatus === null) { return null; }
        $packageStatus = str_replace("\r\n", "\n", $packageStatus);
        if (preg_match('/(?:\A|\n\n)Package: ' . preg_quote($package, '/') . '\n(.*?)(?:\n\n|\z)/s', $packageStatus, $block) !== 1
            || preg_match('/^Status: install ok installed$/m', $block[1]) !== 1
            || preg_match('/^Version: (8\.3\.6-0ubuntu0\.24\.04\.([0-9]{1,4}))$/m', $block[1], $version) !== 1
            || (int) $version[2] < 13) { return null; }
        $checksums = self::read($root . '/var/lib/dpkg/info/' . $package . '.md5sums', 1048576);
        if ($checksums === null) { return null; }
        $expected = null;
        foreach (explode("\n", $checksums) as $line) {
            if (preg_match('/\A([a-f0-9]{32})[ \t]+\*?([^\r\n]+)\r?\z/i', $line, $sum) === 1
                && $sum[2] === ltrim($relative, '/')) { $expected = strtolower($sum[1]); break; }
        }
        if ($expected === null || !self::matches($installedFile, $expected)) { return null; }
        if ($sapi === 'apache2handler') {
            // Installing a fixed module does not patch already-running Apache workers.
            $maps = self::read($root . '/proc/self/maps', 4194304);
            if ($maps === null) { return null; }
            $loaded = false;
            clearstatcache(true, $installedFile);
            foreach (explode("\n", $maps) as $line) {
                $mapping = preg_split('/\s+/', trim($line), 6);
                if (is_array($mapping) && count($mapping) === 6 && $mapping[5] === $installedFile
                    && $mapping[4] === (string) @fileinode($installedFile)) { $loaded = true; break; }
            }
            if (!$loaded) { return null; }
        } else {
            $resolved = realpath($binary);
            if ($resolved === false || $resolved !== realpath($installedFile)
                || !self::matches($root . '/proc/self/exe', $expected)) { return null; }
        }
        return ['package' => $package, 'version' => $version[1]];
    }

    private static function read(string $path, int $maximum): ?string
    {
        // procfs files report size zero; bounded reads work for them as well.
        $content = @file_get_contents($path, false, null, 0, $maximum + 1);
        return is_string($content) && strlen($content) <= $maximum ? $content : null;
    }

    private static function matches(string $path, string $expected): bool
    {
        $actual = @hash_file('md5', $path);
        // Uses the local package inventory, not MD5 as a download signature.
        return is_string($actual) && hash_equals($expected, $actual);
    }
}
