param([ValidateSet('Enable','Run','Stop','Status')][string]$Mode = 'Status')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Supervisor.Core.ps1')
$settings=$null
try {
    $settings = Get-LocalAdvertisingControl
    if ($Mode -eq 'Run' -and !(Test-Path -LiteralPath (Join-Path $settings.Directory 'enabled.json'))) { exit 0 }
    if ($Mode -in @('Enable','Run')) { $settings = Get-LocalAdvertisingSettings }
    Invoke-LocalAdvertisingSupervisor $settings $Mode
} catch {
    # Raw exceptions can contain paths/configuration; persist fixed diagnostics only.
    if ($settings) { Write-SupervisorEvent $settings 'supervisor_failed' }
    Write-Error 'Local advertising supervisor failed. Check private supervisor events and setup prerequisites.' -ErrorAction Continue
    exit 1
}
