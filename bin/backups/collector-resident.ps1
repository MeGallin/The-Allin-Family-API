param([Parameter(Mandatory=$true)][string]$StateRoot)
$ErrorActionPreference='Stop'
$lock=$null
try {
    try { $lock=[IO.File]::Open((Join-Path $StateRoot 'resident.lock'),'OpenOrCreate','ReadWrite','None') } catch [IO.IOException] { exit 0 }
    while($true) {
        $arguments='-NoLogo -NoProfile -NonInteractive -File "'+(Join-Path $PSScriptRoot 'collect-backups.ps1')+'" -Settings "'+(Join-Path $StateRoot 'collector-settings.json')+'"'
        $run=Start-Process -FilePath (Join-Path $PSHOME 'pwsh.exe') -ArgumentList $arguments -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput (Join-Path $StateRoot 'resident-output.log') -RedirectStandardError (Join-Path $StateRoot 'resident-error.log')
        $delay=600
        if($run.ExitCode -eq 0) { $delay=3600 }
        @{checkedAt=[DateTimeOffset]::UtcNow.ToString('o');lastExitCode=$run.ExitCode;nextAttemptSeconds=$delay} | ConvertTo-Json | Set-Content (Join-Path $StateRoot 'resident-status.json')
        Start-Sleep -Seconds $delay
    }
} finally { if($lock) { $lock.Dispose() } }
