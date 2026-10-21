$ErrorActionPreference = 'Stop'
$dataPath = Join-Path $PSScriptRoot '.local\mysql'
$configPath = Join-Path $dataPath 'my.ini'
if (-not (Test-Path -LiteralPath $configPath)) {
    throw 'Initialise the isolated database first; see README.md.'
}
$listener = Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue
if ($listener) {
    $pidPath = Join-Path $dataPath 'server.pid'
    if ((Test-Path -LiteralPath $pidPath) -and ([int](Get-Content $pidPath) -eq $listener[0].OwningProcess)) {
        Write-Output 'The family project database is already running on port 3307.'
        exit 0
    }
    throw 'Port 3307 is already in use by another process.'
}
$databaseProcess = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList @("--defaults-file=`"$configPath`"") -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $dataPath 'stdout.log') -RedirectStandardError (Join-Path $dataPath 'stderr.log')
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    if ($databaseProcess.HasExited) { throw 'The database stopped during startup. Check .local/mysql/mariadb-error.log.' }
    if (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue) { break }
    Start-Sleep -Milliseconds 250
}
if (-not (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue)) {
    throw 'The database did not start listening. Check .local/mysql/mariadb-error.log.'
}
Write-Output "Started the family project database on port 3307 (PID $($databaseProcess.Id))."
