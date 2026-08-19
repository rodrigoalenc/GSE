param(
    [int]$Port = 8765
)

$ErrorActionPreference = 'Stop'
$workspace = Split-Path -Parent $PSScriptRoot

Push-Location $workspace

try {
    & php tests/http-smoke.php "--port=$Port"
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
