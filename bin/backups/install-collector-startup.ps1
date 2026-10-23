param([Parameter(Mandatory=$true)][string]$StateRoot)
# Run once using PowerShell 7; the user must already have completed secure setup.
. (Join-Path $PSScriptRoot 'collector.ps1')
$StateRoot=Assert-BackupPath $StateRoot
$restore=Get-Content (Join-Path $StateRoot 'last-restore.json') -Raw | ConvertFrom-Json
$cloud=Get-Content (Join-Path $StateRoot 'cloud-verification.json') -Raw | ConvertFrom-Json
if(-not ($restore.schemaMatches -and $restore.rowsMatch -and $restore.photosMatch -and $cloud.verified) -or
   $cloud.method -cne 'Windows Cloud Files IN_SYNC' -or $cloud.archiveSha256 -cne $restore.archiveSha256) {
    throw 'Successful real restore and provider sync acknowledgement are required.'
}
$program=Join-Path $StateRoot 'program/bin'
$executable=Join-Path $PSHOME 'pwsh.exe'
if(-not (Test-Path -LiteralPath $executable)) { throw 'Run with PowerShell 7.' }
$shortcutPath=Join-Path ([Environment]::GetFolderPath('Startup')) 'AllinFamily Encrypted Backups.lnk'
if(Test-Path -LiteralPath $shortcutPath) { throw 'Inspect existing Startup installation rather than overwrite.' }
$null=New-Item -ItemType Directory -Force -Path (Join-Path $program 'backups')
foreach($name in @('collector.ps1','sync-state.ps1')) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $name) -Destination (Join-Path $program ('backups/'+$name))
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../collect-backups.ps1') -Destination (Join-Path $program 'collect-backups.ps1')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'collector-resident.ps1') -Destination (Join-Path $program 'collector-resident.ps1')
$arguments='-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -File "'+(Join-Path $program 'collector-resident.ps1')+'" -StateRoot "'+$StateRoot+'"'
$shell=New-Object -ComObject WScript.Shell
$shortcut=$shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath=$executable
$shortcut.Arguments=$arguments
$shortcut.WorkingDirectory=$StateRoot
$shortcut.WindowStyle=7
$shortcut.Description='Collect encrypted Allin Family backups hourly; retry failures after ten minutes.'
$shortcut.Save()
Write-BackupJson (Join-Path $StateRoot 'schedule.json') @{
    installedAt=[DateTimeOffset]::UtcNow.ToString('o'); shortcut=$shortcutPath; program=$executable;
    mode='Current-user Startup; immediate then hourly; failures retry after 10 minutes';
    serverSchedule='15 2 * * * UTC'; taskSchedulerEnabled=$false
}
Start-Process -FilePath $executable -ArgumentList $arguments -WindowStyle Hidden
