$ErrorActionPreference = 'Stop'

function Get-GseToolchain {
    $gseRoot = Split-Path -Parent $PSScriptRoot
    $gseManifest = Get-Content -LiteralPath (Join-Path $gseRoot 'config/runtime-windows.json') -Raw | ConvertFrom-Json
    $gsePrivatePhp = Join-Path $env:LOCALAPPDATA "GSE/php/$($gseManifest.phpVersion)-x64/php.exe"
    $gsePhp = if ($env:GSE_PHP_BINARY) { $env:GSE_PHP_BINARY } elseif (Test-Path -LiteralPath $gsePrivatePhp) { $gsePrivatePhp } else { (Get-Command php -ErrorAction Stop).Source }
    if (-not (Test-Path -LiteralPath $gsePhp -PathType Leaf)) { throw 'PHP não encontrado. Execute bin/install-runtime.ps1.' }
    & $gsePhp (Join-Path $gseRoot 'bin/runtime-check.php') --json | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'PHP incompatível. Execute bin/install-runtime.ps1 e confira bin/runtime-check.php.' }
    $gseComposer = Join-Path $env:LOCALAPPDATA "GSE/composer/$($gseManifest.composerVersion)/composer.phar"
    [pscustomobject]@{ Php = $gsePhp; Composer = $gseComposer; Root = $gseRoot }
}
