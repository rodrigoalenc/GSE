param(
    [string] $PhpFixtureDirectory = (Join-Path $env:LOCALAPPDATA 'GSE/php/8.4.27-x64')
)
$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSVersion.Major -ne 5) { throw 'Execute this test with Windows PowerShell 5.1.' }
$repository = Split-Path -Parent $PSScriptRoot
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('gse-installer-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null
function Assert-Installer($Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}
function Invoke-Installer([string] $Case, [string] $Mode = 'good', [string] $Archive = $fixtureArchive) {
    $caseData = Join-Path $testRoot $Case
    New-Item -ItemType Directory -Path $caseData -Force | Out-Null
    # PS5.1 turns redirected native stderr into ErrorRecord objects; inspect the exit code ourselves.
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner $sandbox $caseData $Archive $composerFixture $Mode 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousPreference }
    return @{ Code = $code; Output = ($output -join [Environment]::NewLine); Data = $caseData; Php = (Join-Path $caseData "GSE/php/$($manifest.phpVersion)-x64") }
}
try {
    Assert-Installer (Test-Path -LiteralPath (Join-Path $PhpFixtureDirectory 'php.exe') -PathType Leaf) 'Provide a real private PHP fixture directory.'
    $sandbox = Join-Path $testRoot 'project'
    foreach ($directory in @('bin', 'config', 'src/Core')) { New-Item -ItemType Directory -Path (Join-Path $sandbox $directory) -Force | Out-Null }
    foreach ($file in @('bin/install-runtime.ps1', 'bin/runtime-check.php', 'config/php-development.ini', 'src/Core/RuntimeRequirements.php', 'src/Core/UbuntuPhpBackport.php', 'src/Core/SqliteJournalPolicy.php')) {
        Copy-Item -LiteralPath (Join-Path $repository $file) -Destination (Join-Path $sandbox $file)
    }
    $manifest = Get-Content -LiteralPath (Join-Path $repository 'config/runtime-windows.json') -Raw | ConvertFrom-Json
    $payload = Join-Path $testRoot 'payload'
    New-Item -ItemType Directory -Path $payload | Out-Null
    Get-ChildItem -LiteralPath $PhpFixtureDirectory -File | Where-Object { $_.Extension -in @('.exe', '.dll') } | Copy-Item -Destination $payload
    Copy-Item -LiteralPath (Join-Path $PhpFixtureDirectory 'ext') -Destination (Join-Path $payload 'ext') -Recurse
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $fixtureArchive = Join-Path $testRoot 'php-fixture.zip'
    [IO.Compression.ZipFile]::CreateFromDirectory($payload, $fixtureArchive, [IO.Compression.CompressionLevel]::Fastest, $false)
    $composerFixture = Join-Path $testRoot 'composer-fixture.phar'
    Set-Content -LiteralPath $composerFixture -Value 'Offline composer download fixture' -Encoding ascii
    $manifest.phpSha256 = (Get-FileHash -LiteralPath $fixtureArchive -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest.composerSha256 = (Get-FileHash -LiteralPath $composerFixture -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $sandbox 'config/runtime-windows.json') -Encoding ascii
    $runner = Join-Path $testRoot 'run-installer.ps1'
    @'
param($Project, $Data, $Archive, $Composer, $Mode)
$ErrorActionPreference = 'Stop'
$env:LOCALAPPDATA = $Data
function Invoke-WebRequest($Uri, $OutFile) {
    if ($Uri -like 'https://downloads.php.net/*') {
        if ($Mode -eq 'deny') { throw 'Unexpected PHP download for an already valid runtime.' }
        if ($Mode -eq 'bad-hash') { Set-Content -LiteralPath $OutFile -Value 'corrupt download' -Encoding ascii }
        else { Copy-Item -LiteralPath $Archive -Destination $OutFile }
    } elseif ($Uri -like 'https://getcomposer.org/download/*/composer.phar') {
        Copy-Item -LiteralPath $Composer -Destination $OutFile
    } else { throw "Unexpected network request: $Uri" }
}
try {
    if ($Mode -eq 'unsafe-moves') {
        . (Join-Path $Project 'bin/install-runtime.ps1')
        $outside = Join-Path $Data "outside/$($gseManifest.phpVersion)-x64"
        New-Item -ItemType Directory -Path $outside -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $outside 'keep.txt') -Value 'outside private PHP root' -Encoding ascii
        $internalTarget = $gsePhpDirectory + '.staging-00000000000000000000000000000000'
        try { Move-GsePhpDirectory $outside $internalTarget; throw 'Unsafe source was moved.' }
        catch { if ($_.Exception.Message -notmatch 'recusada') { throw } }
        try { Move-GsePhpDirectory $gsePhpDirectory (Join-Path $Data 'outside/destination'); throw 'Unsafe destination was moved.' }
        catch { if ($_.Exception.Message -notmatch 'recusada') { throw } }
        if (-not (Test-Path -LiteralPath (Join-Path $outside 'keep.txt')) -or
            -not (Test-Path -LiteralPath (Join-Path $gsePhpDirectory 'php.exe')) -or
            (Test-Path -LiteralPath $internalTarget) -or (Test-Path -LiteralPath (Join-Path $Data 'outside/destination'))) {
            throw 'Unsafe path validation modified the source or destination.'
        }
    } else { & (Join-Path $Project 'bin/install-runtime.ps1') }
    exit 0
}
catch { [Console]::Error.WriteLine($_.ToString()); exit 1 }
'@ | Set-Content -LiteralPath $runner -Encoding ascii

    # Catches missing PS5.1 ZipFile assembly loading and publishing before validation.
    $clean = Invoke-Installer 'clean'
    Assert-Installer ($clean.Code -eq 0) "Clean installation failed: $($clean.Output)"
    Assert-Installer (Test-Path -LiteralPath (Join-Path $clean.Php 'php.exe') -PathType Leaf) 'Clean installation did not publish PHP.'
    Write-Host 'PASS clean installation in fresh PS5.1 process'

    # A real valid executable must be reusable, without redownloading or overwriting user files.
    $ini = Join-Path $clean.Php 'php.ini'
    Add-Content -LiteralPath $ini -Value '; preserved local configuration'
    $iniHash = (Get-FileHash -LiteralPath $ini).Hash
    $rerun = Invoke-Installer 'clean' 'deny'
    Assert-Installer ($rerun.Code -eq 0) "Valid installation was not reusable: $($rerun.Output)"
    Assert-Installer ((Get-FileHash -LiteralPath $ini).Hash -eq $iniHash) 'Reexecution replaced an existing php.ini.'
    Write-Host 'PASS valid runtime reuse and existing configuration preservation'

    # Complete binaries with a deliberately restrictive local configuration must not trigger replacement.
    $savedIni = Get-Content -LiteralPath $ini -Raw
    Set-Content -LiteralPath $ini -Value '; deliberately disabled extensions' -Encoding ascii
    $customIniHash = (Get-FileHash -LiteralPath $ini).Hash
    $customIni = Invoke-Installer 'clean' 'deny'
    Assert-Installer ($customIni.Code -ne 0 -and $customIni.Output -notmatch 'Unexpected PHP download') 'Customized php.ini incorrectly triggered binary recovery.'
    Assert-Installer ((Get-FileHash -LiteralPath $ini).Hash -eq $customIniHash) 'Invalid local configuration was silently overwritten.'
    Assert-Installer (@(Get-ChildItem -LiteralPath (Join-Path $customIni.Data 'GSE/php') -Directory).Count -eq 1) 'Customized installation was moved aside.'
    Set-Content -LiteralPath $ini -Value $savedIni -Encoding ascii
    Write-Host 'PASS local configuration errors do not replace complete binaries or php.ini'

    $unsafeMoves = Invoke-Installer 'unsafe-moves' 'unsafe-moves'
    Assert-Installer ($unsafeMoves.Code -eq 0) "Move path containment failed: $($unsafeMoves.Output)"
    Write-Host 'PASS move helper rejects both source and destination outside private PHP root'

    # Catches directory-exists readiness, including the directory left by the old failed installer.
    $partialPhp = Join-Path $testRoot "partial/GSE/php/$($manifest.phpVersion)-x64"
    New-Item -ItemType Directory -Path $partialPhp -Force | Out-Null
    $partial = Invoke-Installer 'partial'
    Assert-Installer ($partial.Code -eq 0) "Empty partial directory was not recovered: $($partial.Output)"
    Assert-Installer (Test-Path -LiteralPath (Join-Path $partial.Php 'php.exe') -PathType Leaf) 'Partial recovery left PHP absent.'
    Write-Host 'PASS empty partial directory recovery'

    # A runnable interpreter left by an interrupted extraction still needs its extensions repaired.
    $existingLimitedPhp = Join-Path $testRoot "existing-limited/GSE/php/$($manifest.phpVersion)-x64"
    New-Item -ItemType Directory -Path $existingLimitedPhp -Force | Out-Null
    foreach ($file in @('php.exe', 'php8.dll')) { Copy-Item -LiteralPath (Join-Path $payload $file) -Destination $existingLimitedPhp }
    Set-Content -LiteralPath (Join-Path $existingLimitedPhp 'keep.txt') -Value 'preexisting limited PHP sentinel' -Encoding ascii
    $existingLimited = Invoke-Installer 'existing-limited'
    Assert-Installer ($existingLimited.Code -eq 0) "Existing PHP without extensions was not recovered: $($existingLimited.Output)"
    Assert-Installer (Test-Path -LiteralPath (Join-Path $existingLimited.Php 'ext/php_pdo_sqlite.dll') -PathType Leaf) 'Recovery did not restore required extensions.'
    $limitedSentinel = @(Get-ChildItem -LiteralPath (Join-Path $existingLimited.Data 'GSE/php') -Filter 'keep.txt' -File -Recurse)
    Assert-Installer ($limitedSentinel.Count -eq 1 -and (Get-Content -LiteralPath $limitedSentinel[0].FullName -Raw).Trim() -eq 'preexisting limited PHP sentinel') 'Limited PHP recovery lost preexisting content.'
    Write-Host 'PASS existing runnable PHP without extensions is recovered conservatively'

    # Unexpected content must survive recovery even when php.exe is corrupt.
    $brokenPhp = Join-Path $testRoot "broken/GSE/php/$($manifest.phpVersion)-x64"
    New-Item -ItemType Directory -Path $brokenPhp -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $brokenPhp 'php.exe') -Value 'not an executable' -Encoding ascii
    Set-Content -LiteralPath (Join-Path $brokenPhp 'keep.txt') -Value 'unexpected user file' -Encoding ascii
    $broken = Invoke-Installer 'broken'
    Assert-Installer ($broken.Code -eq 0) "Corrupt executable was not recovered: $($broken.Output)"
    $preserved = @(Get-ChildItem -LiteralPath (Join-Path $broken.Data 'GSE/php') -Filter 'keep.txt' -File -Recurse)
    Assert-Installer ($preserved.Count -eq 1 -and (Get-Content -LiteralPath $preserved[0].FullName -Raw).Trim() -eq 'unexpected user file') 'Recovery lost unexpected content.'
    Write-Host 'PASS corrupt executable recovery preserves preexisting files'

    # Hash rejection must precede extraction and leave neither an installation nor a readiness message.
    $bad = Invoke-Installer 'bad-hash' 'bad-hash'
    Assert-Installer ($bad.Code -ne 0 -and $bad.Output -match 'SHA256') "Bad hash was not rejected: $($bad.Output)"
    Assert-Installer (-not (Test-Path -LiteralPath $bad.Php)) 'Hash rejection published a runtime.'
    Assert-Installer ($bad.Output -notmatch 'Runtime privado pronto') 'Hash rejection reported readiness.'
    $retry = Invoke-Installer 'bad-hash'
    Assert-Installer ($retry.Code -eq 0) "Retry after a rejected hash failed: $($retry.Output)"
    Write-Host 'PASS hash failure and retry'

    $retainedPhp = Join-Path $testRoot "retained/GSE/php/$($manifest.phpVersion)-x64"
    New-Item -ItemType Directory -Path $retainedPhp -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $retainedPhp 'keep.txt') -Value 'untouched on failure' -Encoding ascii
    $retained = Invoke-Installer 'retained' 'bad-hash'
    Assert-Installer ($retained.Code -ne 0) 'Corrupt download was accepted while recovering a partial directory.'
    Assert-Installer ((Get-Content -LiteralPath (Join-Path $retainedPhp 'keep.txt') -Raw).Trim() -eq 'untouched on failure') 'Failed recovery moved or modified preexisting content.'
    Assert-Installer (@(Get-ChildItem -LiteralPath (Join-Path $retained.Data 'GSE/php') -Directory).Count -eq 1) 'Failed recovery left extra directories.'
    Write-Host 'PASS failed recovery leaves preexisting directory untouched'

    # A runnable PHP alone is insufficient: required extensions must work before publication.
    $limitedPayload = Join-Path $testRoot 'limited-payload'
    New-Item -ItemType Directory -Path $limitedPayload | Out-Null
    foreach ($file in @('php.exe', 'php8.dll')) { Copy-Item -LiteralPath (Join-Path $payload $file) -Destination $limitedPayload }
    $limitedArchive = Join-Path $testRoot 'limited.zip'
    [IO.Compression.ZipFile]::CreateFromDirectory($limitedPayload, $limitedArchive)
    $manifest.phpSha256 = (Get-FileHash -LiteralPath $limitedArchive -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $sandbox 'config/runtime-windows.json') -Encoding ascii
    $limited = Invoke-Installer 'missing-extensions' 'good' $limitedArchive
    Assert-Installer ($limited.Code -ne 0) 'PHP without required extensions was accepted.'
    Assert-Installer (-not (Test-Path -LiteralPath $limited.Php)) 'PHP without required extensions was published.'
    Assert-Installer ($limited.Output -notmatch 'Runtime privado pronto') 'Missing extensions reported readiness.'
    Assert-Installer (@(Get-ChildItem -LiteralPath (Join-Path $limited.Data 'GSE/php') -Directory -ErrorAction SilentlyContinue).Count -eq 0) 'Failed validation leaked a staging directory.'
    Write-Host 'PASS required extensions validated before publication'

    # An archive with an authentic hash but unusable content must also never be published.
    $emptyPayload = Join-Path $testRoot 'empty-payload'
    New-Item -ItemType Directory -Path $emptyPayload | Out-Null
    Set-Content -LiteralPath (Join-Path $emptyPayload 'readme.txt') -Value 'missing PHP'
    $invalidArchive = Join-Path $testRoot 'invalid.zip'
    [IO.Compression.ZipFile]::CreateFromDirectory($emptyPayload, $invalidArchive)
    $manifest.phpSha256 = (Get-FileHash -LiteralPath $invalidArchive -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $sandbox 'config/runtime-windows.json') -Encoding ascii
    $invalid = Invoke-Installer 'invalid-archive' 'good' $invalidArchive
    Assert-Installer ($invalid.Code -ne 0) 'Archive without PHP was accepted.'
    Assert-Installer (-not (Test-Path -LiteralPath $invalid.Php)) 'Invalid archive left an installed runtime directory.'
    Assert-Installer ($invalid.Output -notmatch 'Runtime privado pronto') 'Invalid archive reported readiness.'
    Assert-Installer (@(Get-ChildItem -LiteralPath (Join-Path $invalid.Data 'GSE/php') -Directory -ErrorAction SilentlyContinue).Count -eq 0) 'Failed extraction leaked a staging directory.'
    Write-Host 'PASS invalid archive never publishes and staging is cleaned'
    Write-Host 'All isolated runtime installer tests passed (Windows PowerShell 5.1).'
} finally {
    $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
    $temporaryParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolvedTestRoot.StartsWith($temporaryParent, [StringComparison]::OrdinalIgnoreCase) -or
        [IO.Path]::GetFileName($resolvedTestRoot) -notlike 'gse-installer-test-*') { throw 'Unsafe test cleanup path.' }
    if (Test-Path -LiteralPath $resolvedTestRoot) { Remove-Item -LiteralPath $resolvedTestRoot -Recurse -Force }
}
