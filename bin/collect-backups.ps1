param([Parameter(Mandatory=$true)][string]$Settings)
. (Join-Path $PSScriptRoot 'backups/collector.ps1')
. (Join-Path $PSScriptRoot 'backups/sync-state.ps1')
$state = $null
$lock = $null
try {
    $config = Get-Content -LiteralPath (Assert-BackupPath $Settings) -Raw | ConvertFrom-Json
    $state = Assert-BackupPath $config.stateRoot
    $archiveRoot = Assert-BackupPath $config.archiveRoot
    if ($state.StartsWith($archiveRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or $state -eq $archiveRoot) { throw 'Collector state must stay outside cloud archives.' }
    try {
        $lock = [IO.File]::Open((Join-Path $state 'collector.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    } catch [IO.IOException] {
        if (($_.Exception.HResult -band 65535) -in @(32,33)) { Write-Output 'Collector already running; duplicate skipped.'; exit 0 }
        throw
    }
    $credential = Import-Clixml -LiteralPath (Join-Path $state 'ftps-credential.xml')
    if ($credential -isnot [pscredential] -or $credential.UserName -cne $config.userName) { throw 'Wrong collector credential.' }
    $fetch = { param($name,$destination,$maximum) Read-BackupFtps $config.hostName $name $credential $maximum $destination }
    $names = @(Get-BackupFtpsNames $config.hostName $credential | Where-Object { $_ -cmatch '^allin-\d{8}T\d{6}Z-[a-f0-9]{12}\.zip\.receipt\.json$' } | Sort-Object)
    if ($names.Count -eq 0) { throw 'No completed server backup found.' }
    # Catch up every missing set, then verify the latest. Historical sets already
    # accepted locally are not rehydrated/rehashed on every hourly wake-up.
    $pending = @($names | Where-Object { -not (Test-Path -LiteralPath (Join-Path $archiveRoot $_)) })
    $pending = @(($pending + $names[-1]) | Sort-Object -Unique)
    $latest = $null
    foreach ($name in $pending) { $latest = Receive-BackupSet $name $archiveRoot (Join-Path $state 'staging') $fetch ([long]$config.maximumBytes) }
    if ([DateTimeOffset]::Parse($latest.capturedAt) -lt [DateTimeOffset]::UtcNow.AddHours(-[double]$config.maximumAgeHours)) { throw 'The latest server capture is stale.' }
    $cloudFiles = @((Join-Path $archiveRoot $latest.file), (Join-Path $archiveRoot ($latest.file+'.key.json')), (Join-Path $archiveRoot ($latest.file+'.receipt.json')), (Assert-BackupPath $config.recoveryFile))
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        $unsynced = @($cloudFiles | Where-Object { -not (Test-BackupCloudSynced $_) })
        if ($unsynced.Count -eq 0) { break }
        Start-Sleep -Seconds 2
    } while ([DateTime]::UtcNow -lt $deadline)
    if ($unsynced.Count -gt 0) { throw 'OneDrive has not acknowledged all backup and recovery files as in sync.' }
    Write-BackupJson (Join-Path $state 'collector-status.json') @{
        success=$true; attemptedAt=[DateTimeOffset]::UtcNow.ToString('o'); latest=$latest;
        checkedSets=$names.Count; oneDriveReportsInSync=$true; verificationMethod='Windows Cloud Files IN_SYNC'; independentCloudDownloadVerified=$false
    }
    Write-Output ('Collected and hash-verified ' + $names.Count + ' encrypted sets; OneDrive reports backup and recovery files in sync.')
    exit 0
} catch {
    if ($state -and (Test-Path -LiteralPath $state)) {
        Write-BackupJson (Join-Path $state 'collector-status.json') @{
            success=$false; attemptedAt=[DateTimeOffset]::UtcNow.ToString('o'); errorCode='collection_failed';
            errorType=$_.Exception.GetType().Name
        }
    }
    Write-Error 'BACKUP COLLECTION FAILED. Check private collector status, server capture freshness, network and credential availability.' -ErrorAction Continue
    exit 1
} finally { if ($lock) { $lock.Dispose() } }
