$ErrorActionPreference = 'Stop'
# A named PowerShell parameter such as Tool also consumes PHP's -t option.
# Keep all native arguments positional until the selected executable receives them.
$gseInvocationArguments = @($args)
$Tool = if ($gseInvocationArguments.Count -gt 0) { [string]$gseInvocationArguments[0] } else { 'check' }
if ($Tool -notin @('php','composer','check','serve')) { throw 'Use php, composer, check ou serve.' }
$ToolArguments = @($gseInvocationArguments | Select-Object -Skip 1)
. (Join-Path $PSScriptRoot 'runtime.ps1')
$gseTools = Get-GseToolchain
$gsePreviousPath = $env:PATH
$gsePreviousBinary = $env:PHP_BINARY
Push-Location $gseTools.Root
try {
    $env:PATH = (Split-Path -Parent $gseTools.Php) + [IO.Path]::PathSeparator + $env:PATH
    $env:PHP_BINARY = $gseTools.Php
    switch ($Tool) {
        'php' { & $gseTools.Php @ToolArguments; $gseExit = $LASTEXITCODE }
        'serve' {
            $gseServerOptions = @{}
            for ($gseIndex = 0; $gseIndex -lt $ToolArguments.Count; $gseIndex++) {
                switch ([string]$ToolArguments[$gseIndex]) {
                    '-Port' {
                        if (++$gseIndex -ge $ToolArguments.Count) { throw 'Informe o número depois de -Port.' }
                        $gseServerOptions.Port = [int]$ToolArguments[$gseIndex]
                    }
                    '-Lan' { $gseServerOptions.Lan = $true }
                    default { throw 'serve aceita somente -Port NUMERO e -Lan.' }
                }
            }
            & (Join-Path $PSScriptRoot 'start-server.ps1') @gseServerOptions
            $gseExit = 0
        }
        default {
            if (-not (Test-Path -LiteralPath $gseTools.Composer)) { throw 'Composer privado não encontrado. Execute bin/install-runtime.ps1.' }
            [string[]]$gseComposerArguments = @($ToolArguments)
            if ($Tool -eq 'check') { $gseComposerArguments = @('check') + $ToolArguments }
            & $gseTools.Php $gseTools.Composer @gseComposerArguments
            $gseExit = $LASTEXITCODE
        }
    }
} finally {
    $env:PATH = $gsePreviousPath
    $env:PHP_BINARY = $gsePreviousBinary
    Pop-Location
}
exit $gseExit
