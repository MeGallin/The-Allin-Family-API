param(
    [int]$Port = 8000,
    [string]$BindAddress = '127.0.0.1',
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
if (-not $PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $PhpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
}
if (-not (Test-Path -LiteralPath $PhpPath)) {
    throw 'PHP was not found. Pass its executable path using -PhpPath.'
}

Push-Location $PSScriptRoot
try {
    & $PhpPath -S "${BindAddress}:$Port" router.php
    if ($LASTEXITCODE -ne 0) { throw 'The PHP development server stopped with an error.' }
} finally {
    Pop-Location
}
