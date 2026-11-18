param([switch]$Register)
$ErrorActionPreference='Stop'
$name='The Allin Family - Local Advertising'
$script=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'Supervisor.ps1'))
$windowsPowerShell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$action=New-ScheduledTaskAction -Execute $windowsPowerShell -Argument ('-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -File "' + $script + '" -Mode Run') -WorkingDirectory $PSScriptRoot
$logon=New-ScheduledTaskTrigger -AtLogOn -User $identity
$watchdog=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal=New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden
# No WakeToRun: a minute watchdog must not wake the computer every minute.
$task=New-ScheduledTask -Action $action -Trigger @($logon,$watchdog) -Principal $principal -Settings $settings -Description 'Local advertising dependencies and worker; Gary must stay logged in. Prevents idle sleep on AC only. No automatic configuration changes.'
if (!$Register) {
    $task | ConvertTo-Json -Depth 10
    Write-Output 'Preview only. Use -Register after review; registration does not enable or start advertising.'
    return
}
if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) { throw 'Task already exists. Inspect it; this installer never overwrites a task.' }
Register-ScheduledTask -TaskName $name -InputObject $task | Select-Object TaskName,State
