. (Join-Path $PSScriptRoot '../bin/backups/collector.ps1')
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot ('../.local/collector-test-' + [guid]::NewGuid().ToString('N'))))
$checks = 0
function Check($Condition, [string]$Label) {
    if (-not $Condition) { throw $Label }
    $script:checks++
    Write-Output ('PASS: ' + $Label)
}
function Reject([scriptblock]$Action,[string]$Label) {
    $rejected=$false
    try { & $Action | Out-Null } catch { $rejected=$true }
    Check $rejected $Label
}
try {
    foreach ($folder in @('source','archives','staging')) { $null=New-Item -ItemType Directory -Path (Join-Path $root $folder) -Force }
    $source = Join-Path $root 'source'
    $archives = Join-Path $root 'archives'
    $staging = Join-Path $root 'staging'
    $name='allin-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-abcdef123456.zip'
    # Synthetic ciphertext stand-ins; this suite never opens a network connection.
    [IO.File]::WriteAllBytes((Join-Path $source $name),[byte[]](0,1,2,3,255))
    [IO.File]::WriteAllText((Join-Path $source ($name+'.key.json')),'synthetic key envelope')
    $receipt=@{
        format='allin-family-transfer-v1'; file=$name; capturedAt=[DateTimeOffset]::UtcNow.ToString('o');
        bytes=5; sha256=(Get-FileHash -LiteralPath (Join-Path $source $name)).Hash.ToLowerInvariant();
        keyBytes=(Get-Item -LiteralPath (Join-Path $source ($name+'.key.json'))).Length;
        keySha256=(Get-FileHash -LiteralPath (Join-Path $source ($name+'.key.json'))).Hash.ToLowerInvariant()
    }
    $receiptName=$name+'.receipt.json'
    Write-BackupJson (Join-Path $source $receiptName) $receipt
    $fetch={ param($remote,$destination,$maximum) Copy-Item -LiteralPath (Join-Path $source $remote) -Destination $destination }.GetNewClosure()
    $result=Receive-BackupSet $receiptName $archives $staging $fetch
    Check ($result.file -ceq $name) 'Completed encrypted set is collected'
    Check (Confirm-BackupFile (Join-Path $archives $name) 5 $receipt.sha256) 'Archive hash and size match'
    Check (Confirm-BackupFile (Join-Path $archives ($name+'.key.json')) $receipt.keyBytes $receipt.keySha256) 'Key envelope hash and size match'
    Check (Test-Path -LiteralPath (Join-Path $archives $receiptName)) 'Receipt published after verified pair'
    Check (@(Get-ChildItem -LiteralPath $staging).Count -eq 0) 'Successful staging is removed'
    $null=Receive-BackupSet $receiptName $archives $staging $fetch
    Check (@(Get-ChildItem -LiteralPath $archives).Count -eq 3) 'Repeat collection reuses identical immutable files'
    Reject { Receive-BackupSet '../outside.zip.receipt.json' $archives $staging $fetch } 'Traversal rejected before transfer'
    Reject { Receive-BackupSet 'other.zip.receipt.json' $archives $staging $fetch } 'Unexpected receipt names rejected'
    Reject { Assert-BackupReceipt ([pscustomobject]($receipt+@{extra=1})) '../outside' 100 } 'Receipt identity mismatch rejected'
    $bad=$receipt.Clone(); $bad.format='unknown'
    Reject { Assert-BackupReceipt ([pscustomobject]$bad) $receiptName 100 } 'Unknown receipt format rejected'
    $bad=$receipt.Clone(); $bad.sha256='not-a-hash'
    Reject { Assert-BackupReceipt ([pscustomobject]$bad) $receiptName 100 } 'Invalid checksum rejected'
    $bad=$receipt.Clone(); $bad.bytes=-1
    Reject { Assert-BackupReceipt ([pscustomobject]$bad) $receiptName 100 } 'Negative file size rejected'
    $bad=$receipt.Clone(); $bad.bytes=101
    Reject { Assert-BackupReceipt ([pscustomobject]$bad) $receiptName 100 } 'Oversized archive rejected'
    $bad=$receipt.Clone(); $bad.capturedAt=[DateTimeOffset]::UtcNow.AddDays(1).ToString('o')
    Reject { Assert-BackupReceipt ([pscustomobject]$bad) $receiptName 100 } 'Future capture time rejected'
    [IO.File]::WriteAllText((Join-Path $archives $name),'preserve existing mismatch')
    Reject { Receive-BackupSet $receiptName $archives $staging $fetch } 'Existing mismatching archive cannot be overwritten'
    Check ([IO.File]::ReadAllText((Join-Path $archives $name)) -ceq 'preserve existing mismatch') 'Existing archive preserved on failure'
    Check (@(Get-ChildItem -LiteralPath $staging).Count -eq 0) 'Rejected transfer removes its own staging'
    $empty=Join-Path $root 'empty'; $null=New-Item -ItemType Directory -Path $empty
    $broken={ param($remote,$destination,$maximum) if($remote.EndsWith('.zip')) { throw 'simulated disconnect' }; Copy-Item -LiteralPath (Join-Path $source $remote) -Destination $destination }.GetNewClosure()
    Reject { Receive-BackupSet $receiptName $empty $staging $broken } 'Disconnected transfer fails'
    Check (@(Get-ChildItem -LiteralPath $empty).Count -eq 0) 'Interrupted transfer publishes no completion marker or files'
    $tampered={ param($remote,$destination,$maximum) Copy-Item -LiteralPath (Join-Path $source $remote) -Destination $destination; if($remote.EndsWith('.key.json')) { [IO.File]::WriteAllText($destination,'changed') } }.GetNewClosure()
    Reject { Receive-BackupSet $receiptName $empty $staging $tampered } 'Corrupted key envelope rejected'
    Check (@(Get-ChildItem -LiteralPath $empty).Count -eq 0) 'Corrupted pair is never accepted'
    $configuration=New-BackupCurlConfig 'cloud752.thundercloud.uk' 'example' ([pscredential]::new('synthetic',(ConvertTo-SecureString 'synthetic' -AsPlainText -Force))) 100 $false
    Check ($configuration.Contains('ssl-reqd') -and $configuration.Contains('tlsv1.2') -and $configuration.Contains('ftp-pasv') -and -not $configuration.Contains('insecure')) 'FTPS always requires TLS and certificate checks'
    Check (($configuration -split "`n").Count -eq 15 -and $configuration.Contains("`nssl-reqd`n")) 'Each curl option occupies its own config line'
    Check (@(Get-ChildItem -LiteralPath $source).Count -eq 3) 'Source fixtures are never modified or deleted'
    Write-Output ('Collector: '+$checks+' checks passed. Synthetic files only; no network or family data accessed.')
} finally {
    $parent=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../.local'))
    if ((Split-Path -Parent $root) -cne $parent -or (Split-Path -Leaf $root) -notmatch '^collector-test-[a-f0-9]{32}$') { throw 'Unsafe test cleanup.' }
    if(Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
