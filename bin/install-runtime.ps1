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
try {
    if (-not (Test-Path -LiteralPath $gsePhpDirectory)) {
        $gseDownload = 'https://downloads.php.net/~windows/releases/archives/' + $gseManifest.phpArchive
        Invoke-WebRequest -Uri $gseDownload -OutFile $gseArchive
        if ((Get-FileHash -LiteralPath $gseArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $gseManifest.phpSha256) { throw 'O download PHP não confere com o SHA256 oficial.' }
        New-Item -ItemType Directory -Path $gsePhpDirectory | Out-Null
        [IO.Compression.ZipFile]::ExtractToDirectory($gseArchive, $gsePhpDirectory)
    }
    $gseIni = Join-Path $gsePhpDirectory 'php.ini'
    if (-not (Test-Path -LiteralPath $gseIni)) {
        $gseExtensions = (Join-Path $gsePhpDirectory 'ext').Replace('\','/')
        $gseConfiguration = 'extension_dir="' + $gseExtensions + '"' + [Environment]::NewLine + (Get-Content -LiteralPath (Join-Path $gseRoot 'config/php-development.ini') -Raw)
        Set-Content -LiteralPath $gseIni -Value $gseConfiguration -Encoding ascii
    }
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
}
