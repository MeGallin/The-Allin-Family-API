param([string]$PhpPath = 'C:\xampp\php\php.exe')
$ErrorActionPreference = 'Stop'
$dataPath = Join-Path $PSScriptRoot '.local\mysql'
if (Test-Path -LiteralPath $dataPath) { throw 'Database files already exist. Use start-database.ps1 instead.' }
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env'))) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot '.env.example') -Destination (Join-Path $PSScriptRoot '.env')
}
New-Item -ItemType Directory -Path (Split-Path $dataPath) -Force | Out-Null
& 'C:\xampp\mysql\bin\mysql_install_db.exe' "--datadir=$dataPath" --port=3307
if ($LASTEXITCODE -ne 0) { throw 'Database initialisation failed.' }
$normalisedDataPath = $dataPath.Replace('\','/')
$databaseConfig = @"
[mysqld]
basedir=C:/xampp/mysql
datadir=$normalisedDataPath
port=3307
bind-address=127.0.0.1
pid-file=$normalisedDataPath/server.pid
log-error=$normalisedDataPath/mariadb-error.log
innodb_buffer_pool_size=64M
[client]
port=3307
host=127.0.0.1
"@
Set-Content -LiteralPath (Join-Path $dataPath 'my.ini') -Value $databaseConfig -Encoding ascii
& (Join-Path $PSScriptRoot 'start-database.ps1')
Push-Location $PSScriptRoot
try {
    & $PhpPath bin/setup-local.php
    if ($LASTEXITCODE -ne 0) { throw 'Database provisioning failed.' }
} finally { Pop-Location }
