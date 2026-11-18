param([Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference='Stop'
$env:LOCALAPPDATA=$Directory
. (Join-Path $PSScriptRoot '../../bin/local-advertising/Supervisor.Core.ps1')
$settings=Get-LocalAdvertisingControl
if ($settings.Roles[1].Environment.HOST -ne '127.0.0.2' -or $settings.Roles[1].Environment.PORT -ne '8000' -or $settings.Roles[1].Environment.ADVERTISING_BRIDGE_URL -ne 'http://127.0.0.1:8001/advertising/machine') { throw 'Loopback binding contract failed.' }
Write-SupervisorJson $settings 'enabled.json' @{}
Invoke-LocalAdvertisingSupervisor $settings 'Stop'
if (Test-Path -LiteralPath (Join-Path $settings.Directory 'enabled.json')) { throw 'Stop failed to disarm.' }
if (!(Test-Path -LiteralPath (Join-Path $settings.Directory 'stop.json'))) { throw 'Stop failed to signal.' }
Invoke-LocalAdvertisingSupervisor $settings 'Status'
