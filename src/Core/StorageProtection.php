<?php

declare(strict_types=1);

/** Read-only OS checks. Failure to obtain evidence is a failed check, never approval. */
final class StorageProtection
{
    /**
     * @param array<string,string> $paths
     * @param (callable(string):array{encrypted:bool,private:bool})|null $probe
     * @return array{ok:bool,checks:array<string,array{encrypted:bool,private:bool}>,errors:list<string>} */
    public static function inspect(array $paths, ?callable $probe = null): array
    {
        $result = ['ok' => false, 'checks' => [], 'errors' => []];
        foreach (['database', 'documents', 'sessions', 'logs', 'keys', 'temporary', 'backups'] as $category) {
            $path = $paths[$category] ?? '';
            $real = $path === '' ? false : realpath($path);
            $check = ['encrypted' => false, 'private' => false];
            if ($real !== false && !self::linked($path)) {
                try { $check = $probe === null ? self::system($real) : $probe($real); }
                catch (Throwable) { /* No OS evidence means unverified. */ }
            }
            $result['checks'][$category] = $check;
            if (!$check['encrypted'] || !$check['private']) {
                $result['errors'][] = $category . ': criptografia do volume e permissões privadas não comprovadas.';
            }
        }
        $result['ok'] = $result['errors'] === [];
        return $result;
    }

    private static function linked(string $path): bool
    {
        for ($current = $path; ; $current = dirname($current)) {
            if (is_link($current)) { return true; }
            if ($current === dirname($current)) { return false; }
        }
    }

    /** Checks access restrictions only; useful before reading an approved CLI policy. */
    public static function privatePath(string $path): bool
    {
        $real = realpath($path);
        if ($real === false || self::linked($path)) { return false; }
        if (PHP_OS_FAMILY === 'Windows') { return self::windowsPrivate($real); }
        $mode = @fileperms($real);
        if ($mode === false || !self::privatePosixMode($mode, is_dir($real))) { return false; }
        if (!is_dir($real)) {
            $parentMode = @fileperms(dirname($real));
            return $parentMode !== false && self::privatePosixMode($parentMode, true);
        }
        return true;
    }

    private static function privatePosixMode(int $mode, bool $directory): bool
    {
        return ($mode & 0777) === ($directory ? 0700 : 0600);
    }

    private static function windowsPrivate(string $path): bool
    {
        $literal = "'" . str_replace("'", "''", $path) . "'";
        $script = '$ErrorActionPreference="Stop"; $p=' . $literal . '; '
            . '$current=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value; '
            . '$targets=@($p); if (!(Get-Item -LiteralPath $p).PSIsContainer) {$targets += [IO.Path]::GetDirectoryName($p)}; '
            . '$acls=@(foreach($target in $targets){$a=Get-Acl -LiteralPath $target; '
            . '@{owner=$a.GetOwner([Security.Principal.SecurityIdentifier]).Value;current=$current; '
            . 'entries=@($a.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])|ForEach-Object {'
            . '@{sid=$_.IdentityReference.Value;allow=($_.AccessControlType -eq "Allow")}})}}); '
            . '@{acls=$acls}|ConvertTo-Json -Compress -Depth 5';
        $json = self::run(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))]);
        $data = json_decode($json ?? '', true);
        return is_array($data) && is_array($data['acls'] ?? null) && self::privateWindowsAcls($data['acls'], !is_dir($path));
    }

    /** @param array<mixed> $acls */
    private static function privateWindowsAcls(array $acls, bool $file): bool
    {
        if (count($acls) !== ($file ? 2 : 1)) { return false; }
        $broad = ['S-1-1-0', 'S-1-5-11', 'S-1-5-32-545', 'S-1-5-7'];
        foreach ($acls as $acl) {
            if (!is_array($acl) || !is_string($acl['owner'] ?? null) || !is_string($acl['current'] ?? null)
                || !is_array($acl['entries'] ?? null) || $acl['entries'] === []) { return false; }
            if (preg_match('/^S-\d+(?:-\d+)+$/D', $acl['owner']) !== 1 || preg_match('/^S-\d+(?:-\d+)+$/D', $acl['current']) !== 1) { return false; }
            $allowed = ['S-1-5-18', 'S-1-5-32-544', $acl['current'], $acl['owner']];
            foreach ($acl['entries'] as $entry) {
                if (!is_array($entry) || !is_string($entry['sid'] ?? null) || !is_bool($entry['allow'] ?? null)) { return false; }
                if ($entry['allow'] && (in_array($entry['sid'], $broad, true) || !in_array($entry['sid'], $allowed, true))) { return false; }
            }
        }
        return true;
    }

    /** @return array{encrypted:bool,private:bool} */
    private static function system(string $path): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $literal = "'" . str_replace("'", "''", $path) . "'";
            $script = '$ErrorActionPreference="Stop"; $p=' . $literal . '; $encrypted=$false; '
                . 'try {$drive=[IO.Path]::GetPathRoot($p); $v=Get-BitLockerVolume -MountPoint $drive; '
                . '$encrypted=($v.VolumeStatus -eq "FullyEncrypted" -and $v.ProtectionStatus -eq "On")} catch {}; '
                . '@{encrypted=[bool]$encrypted}|ConvertTo-Json -Compress';
            $json = self::run(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))]);
            $data = json_decode($json ?? '', true);
            return ['encrypted' => is_array($data) && ($data['encrypted'] ?? false) === true,
                'private' => self::privatePath($path)];
        }
        $private = self::privatePath($path);
        $mount = self::run(['findmnt', '--noheadings', '--output', 'SOURCE', '--target', $path]);
        $encrypted = false;
        if ($mount !== null && preg_match('~^/dev/[A-Za-z0-9_./+-]+(?:\[[^\r\n]+\])?$~', trim($mount)) === 1) {
            $device = preg_replace('/\[.*$/', '', trim($mount));
            $tree = self::run(['lsblk', '--inverse', '--json', '--output', 'TYPE', (string) $device]);
            $data = json_decode($tree ?? '', true);
            $encrypted = is_array($data) && self::hasCrypt($data);
        }
        return ['encrypted' => $encrypted, 'private' => $private];
    }

    /** @param array<mixed> $data */
    private static function hasCrypt(array $data): bool
    {
        $devices = $data['blockdevices'] ?? null;
        if (!is_array($devices) || $devices === []) { return false; }
        foreach ($devices as $device) {
            if (!is_array($device) || !self::encryptedBranch($device)) { return false; }
        }
        return true;
    }

    /**
     * Every backing branch must cross an encryption layer before physical storage.
     * @param array<mixed> $node
     */
    private static function encryptedBranch(array $node): bool
    {
        if (($node['type'] ?? null) === 'crypt') { return true; }
        if (!is_string($node['type'] ?? null) || !is_array($node['children'] ?? null) || $node['children'] === []) { return false; }
        foreach ($node['children'] as $child) {
            if (!is_array($child) || !self::encryptedBranch($child)) { return false; }
        }
        return true;
    }

    /** @param list<string> $command */
    private static function run(array $command): ?string
    {
        if (!function_exists('proc_open')) { return null; }
        $output = tmpfile(); $error = tmpfile();
        if ($output === false || $error === false) {
            if (is_resource($output)) { fclose($output); }
            if (is_resource($error)) { fclose($error); }
            return null;
        }
        $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes, null, null,
            ['bypass_shell' => true, 'suppress_errors' => true, 'create_no_window' => true]);
        if (!is_resource($process)) { fclose($output); fclose($error); return null; }
        $deadline = microtime(true) + 4;
        try {
            do {
                $status = proc_get_status($process);
                $outputStats = fstat($output); $errorStats = fstat($error);
                if ($outputStats === false || $errorStats === false || $outputStats['size'] + $errorStats['size'] > 65536) { proc_terminate($process, 9); return null; }
                if (!$status['running']) {
                    rewind($output);
                    $text = stream_get_contents($output, 65537);
                    return $status['exitcode'] === 0 && is_string($text) ? trim($text) : null;
                }
                if (microtime(true) >= $deadline) { proc_terminate($process, 9); return null; }
                usleep(10000);
            } while (true);
        } finally { proc_close($process); fclose($output); fclose($error); }
    }
}
