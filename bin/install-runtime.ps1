$ErrorActionPreference = 'Stop'
$gseRoot = Split-Path -Parent $PSScriptRoot
$gseManifest = Get-Content -LiteralPath (Join-Path $gseRoot 'config/runtime-windows.json') -Raw | ConvertFrom-Json
if ($gseManifest.phpVersion -notmatch '^8\.[345]\.\d+$' -or $gseManifest.composerVersion -notmatch '^2\.\d+\.\d+$' -or
    $gseManifest.phpArchive -notmatch '^php-8\.[345]\.\d+-nts-Win32-vs17-x64\.zip$' -or
    $gseManifest.phpSha256 -notmatch '^[a-f0-9]{64}$' -or $gseManifest.composerSha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'Manifesto de runtime inválido.'
}
$gsePhpDirectory = Join-Path $env:LOCALAPPDATA "GSE/php/$($gseManifest.phpVersion)-x64"
$gseComposerDirectory = Join-Path $env:LOCALAPPDATA "GSE/composer/$($gseManifest.composerVersion)"
$gseArchive = Join-Path ([IO.Path]::GetTempPath()) ('gse-runtime-' + [guid]::NewGuid().ToString('N') + '.zip')
$gsePhpStage = $null
function Test-GsePhpRuntime([string] $Directory) {
    $executable = Join-Path $Directory 'php.exe'
    if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) { return $false }
    try {
        & $executable -n -r "exit(PHP_VERSION === '$($gseManifest.phpVersion)' && PHP_INT_SIZE === 8 ? 0 : 1);" 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { return $false }
        # Verify the shipped binaries independently of any deliberately customized local php.ini.
        & $executable -c (Join-Path $gseRoot 'config/php-development.ini') -d "extension_dir=$(Join-Path $Directory 'ext')" (Join-Path $gseRoot 'bin/runtime-check.php') 2>$null | Out-Null
        return $LASTEXITCODE -eq 0
    } catch { return $false }
}
function Initialize-GsePhpIni([string] $Directory) {
    $ini = Join-Path $Directory 'php.ini'
    if (-not (Test-Path -LiteralPath $ini)) {
        $extensions = (Join-Path $gsePhpDirectory 'ext').Replace('\','/')
        $configuration = 'extension_dir="' + $extensions + '"' + [Environment]::NewLine + (Get-Content -LiteralPath (Join-Path $gseRoot 'config/php-development.ini') -Raw)
        Set-Content -LiteralPath $ini -Value $configuration -Encoding ascii
    }
}
function Move-GsePhpDirectory([string] $Source, [string] $Destination) {
    $privatePhpRoot = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'GSE/php')).TrimEnd('\')
    $sourceFullPath = [IO.Path]::GetFullPath($Source)
    $destinationFullPath = [IO.Path]::GetFullPath($Destination)
    foreach ($path in @($sourceFullPath, $destinationFullPath)) {
        if ((Split-Path -Parent $path) -ne $privatePhpRoot -or
            (Split-Path -Leaf $path) -notmatch ('^' + [regex]::Escape($gseManifest.phpVersion) + '-x64(?:\.(?:staging|partial)-[a-f0-9]{32})?$')) {
            throw 'Movimentação fora do diretório privado PHP recusada.'
        }
    }
    Move-Item -LiteralPath $sourceFullPath -Destination $destinationFullPath
}
try {
    if (-not (Test-GsePhpRuntime $gsePhpDirectory)) {
        $gseDownload = 'https://downloads.php.net/~windows/releases/archives/' + $gseManifest.phpArchive
        Invoke-WebRequest -Uri $gseDownload -OutFile $gseArchive
        if ((Get-FileHash -LiteralPath $gseArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $gseManifest.phpSha256) { throw 'O download PHP não confere com o SHA256 oficial.' }
        $gsePhpParent = Split-Path -Parent $gsePhpDirectory
        New-Item -ItemType Directory -Path $gsePhpParent -Force | Out-Null
        $gsePhpStage = $gsePhpDirectory + '.staging-' + [guid]::NewGuid().ToString('N')
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [IO.Compression.ZipFile]::ExtractToDirectory($gseArchive, $gsePhpStage)
        if (-not (Test-GsePhpRuntime $gsePhpStage)) { throw 'O arquivo PHP extraído não contém o runtime esperado.' }
        Initialize-GsePhpIni $gsePhpStage
        & (Join-Path $gsePhpStage 'php.exe') -c (Join-Path $gsePhpStage 'php.ini') -d "extension_dir=$(Join-Path $gsePhpStage 'ext')" (Join-Path $gseRoot 'bin/runtime-check.php')
        if ($LASTEXITCODE -ne 0) { throw 'O PHP extraído não passou na verificação de runtime.' }
        # Preserve every preexisting file; only publish the verified staging directory.
        $gsePartial = $null
        if (Test-Path -LiteralPath $gsePhpDirectory) {
            $gsePartial = $gsePhpDirectory + '.partial-' + [guid]::NewGuid().ToString('N')
            Move-GsePhpDirectory $gsePhpDirectory $gsePartial
            Write-Host "Instalação parcial preservada em: $gsePartial"
        }
        try { Move-GsePhpDirectory $gsePhpStage $gsePhpDirectory }
        catch {
            if ($gsePartial -and -not (Test-Path -LiteralPath $gsePhpDirectory)) { Move-GsePhpDirectory $gsePartial $gsePhpDirectory }
            throw
        }
        $gsePhpStage = $null
    }
    Initialize-GsePhpIni $gsePhpDirectory
    New-Item -ItemType Directory -Path $gseComposerDirectory -Force | Out-Null
    $gseComposer = Join-Path $gseComposerDirectory 'composer.phar'
    if (-not (Test-Path -LiteralPath $gseComposer)) {
        Invoke-WebRequest -Uri "https://getcomposer.org/download/$($gseManifest.composerVersion)/composer.phar" -OutFile $gseComposer
    }
    if ((Get-FileHash -LiteralPath $gseComposer -Algorithm SHA256).Hash.ToLowerInvariant() -ne $gseManifest.composerSha256) { throw 'O Composer não confere com o SHA256 oficial.' }
    & (Join-Path $gsePhpDirectory 'php.exe') (Join-Path $gseRoot 'bin/runtime-check.php')
    if ($LASTEXITCODE -ne 0) { throw 'O ambiente instalado não passou na verificação.' }
    Write-Host 'Runtime privado pronto. Use bin/gse.ps1 check. O PHP global e o banco escolar não foram modificados.'
} finally {
    if (Test-Path -LiteralPath $gseArchive) { Remove-Item -LiteralPath $gseArchive }
    if ($gsePhpStage -and (Test-Path -LiteralPath $gsePhpStage)) {
        $gseStageFullPath = [IO.Path]::GetFullPath($gsePhpStage)
        $gseExpectedParent = [IO.Path]::GetFullPath((Split-Path -Parent $gsePhpDirectory))
        if ((Split-Path -Parent $gseStageFullPath) -ne $gseExpectedParent -or
            (Split-Path -Leaf $gseStageFullPath) -notlike "$($gseManifest.phpVersion)-x64.staging-*") { throw 'Diretório temporário de runtime inválido.' }
        Remove-Item -LiteralPath $gseStageFullPath -Recurse -Force
    }
}
