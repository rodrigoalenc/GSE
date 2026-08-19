param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8000,

    [switch]$Lan
)

$ErrorActionPreference = 'Stop'
$workspace = Split-Path -Parent $PSScriptRoot
$address = if ($Lan) { '0.0.0.0' } else { '127.0.0.1' }
$accessHost = if ($Lan) { '<IP-DESTE-COMPUTADOR>' } else { 'localhost' }
$envFile = Join-Path $workspace '.env'
$envExample = Join-Path $workspace '.env.example'
$stdoutLog = Join-Path $env:TEMP "gse-server-$Port.out.log"
$stderrLog = Join-Path $env:TEMP "gse-server-$Port.err.log"

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP não foi encontrado no PATH.'
}

if (-not (Test-Path -LiteralPath $envFile)) {
    Copy-Item -LiteralPath $envExample -Destination $envFile
    Write-Host 'Arquivo .env criado a partir de .env.example.' -ForegroundColor Yellow
}

$existing = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue

if ($existing) {
    Write-Host "A porta $Port já está em uso pelo processo $($existing[0].OwningProcess)." -ForegroundColor Yellow
    Write-Host "Tente abrir http://localhost:$Port ou execute com outra porta:"
    Write-Host ".\bin\start-server.ps1 -Port 8080"
    exit 0
}

Push-Location $workspace

try {
    & php bin/init-db.php

    if ($LASTEXITCODE -ne 0) {
        throw 'Falha ao inicializar o banco SQLite.'
    }

    $process = Start-Process -FilePath 'php' `
        -ArgumentList '-S', "${address}:$Port", '-t', 'public', 'public/index.php' `
        -WorkingDirectory $workspace `
        -RedirectStandardOutput $stdoutLog `
        -RedirectStandardError $stderrLog `
        -WindowStyle Hidden `
        -PassThru

    $ready = $false

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $status = & curl.exe -s -o NUL -w '%{http_code}' "http://127.0.0.1:$Port/login"

        if ($status -eq '200') {
            $ready = $true
            break
        }

        Start-Sleep -Milliseconds 200
    }

    if (-not $ready) {
        if (-not $process.HasExited) {
            Stop-Process -Id $process.Id -Force
        }

        $errorText = if (Test-Path -LiteralPath $stderrLog) {
            Get-Content -Raw -LiteralPath $stderrLog
        } else {
            'O servidor não produziu log de erro.'
        }

        throw "O servidor não respondeu corretamente.`n$errorText"
    }

    Write-Host ''
    Write-Host 'GSE iniciado com sucesso.' -ForegroundColor Green
    Write-Host "URL: http://${accessHost}:$Port"
    Write-Host "PID: $($process.Id)"
    Write-Host "Log de erros: $stderrLog"
    Write-Host "Para encerrar: Stop-Process -Id $($process.Id)"
} finally {
    Pop-Location
}
