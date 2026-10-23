param(
    [Parameter(Mandatory=$true)][string]$StateRoot,
    [Parameter(Mandatory=$true)][string]$Archive
)
# Run with PowerShell 7. No live DB connection or app configuration is changed.
. (Join-Path $PSScriptRoot 'collector.ps1')
Add-Type -AssemblyName System.Security
$StateRoot=Assert-BackupPath $StateRoot
$Archive=Assert-BackupPath $Archive
$receiptPath=$Archive+'.receipt.json'
$receipt=Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
$null=Assert-BackupReceipt $receipt (Split-Path -Leaf $receiptPath) 2147483648
if(-not (Confirm-BackupFile $Archive $receipt.bytes $receipt.sha256) -or -not (Confirm-BackupFile ($Archive+'.key.json') $receipt.keyBytes $receipt.keySha256)) { throw 'Recovery inputs do not match accepted hashes.' }
$run=Join-Path $StateRoot ('rehearsal-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')+'-'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $run
$temporaryKey=Join-Path $run 'temporary-private-key.pem'
try {
    $protected=[IO.File]::ReadAllBytes((Join-Path $StateRoot 'recovery-key.dpapi'))
    $privateBytes=[Security.Cryptography.ProtectedData]::Unprotect($protected,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    try { [IO.File]::WriteAllText($temporaryKey,([Security.Cryptography.PemEncoding]::WriteString('PRIVATE KEY',$privateBytes)+"`n"),[Text.UTF8Encoding]::new($false)) }
    finally { [Array]::Clear($privateBytes,0,$privateBytes.Length) }
    & 'C:/xampp/php/php.exe' (Join-Path $PSScriptRoot '../open-backup.php') ('--archive='+$Archive) ('--key-file='+$Archive+'.key.json') ('--private-key='+$temporaryKey) ('--destination='+(Join-Path $run 'recovered'))
    if($LASTEXITCODE -ne 0) { throw 'Private bundle extraction failed.' }
} finally { if(Test-Path -LiteralPath $temporaryKey) { Remove-Item -LiteralPath $temporaryKey -Force } }
& 'C:/xampp/php/php.exe' (Join-Path $PSScriptRoot '../rehearse-backup-local.php') ('--recovered='+(Join-Path $run 'recovered'))
if($LASTEXITCODE -ne 0) { throw 'Isolated local restore verification failed.' }
$report=Get-Content -LiteralPath (Join-Path $run 'recovered/restore-verification.json') -Raw | ConvertFrom-Json
Write-BackupJson (Join-Path $StateRoot 'last-restore.json') @{
    archive=(Split-Path -Leaf $Archive); archiveSha256=$receipt.sha256; verifiedAt=$report.verifiedAt;
    tables=$report.tables; photoFiles=$report.photoFiles; schemaMatches=$report.schemaMatches;
    rowsMatch=$report.rowsMatch; photosMatch=$report.photosMatch; environment=$report.environment;
    privateReport=(Join-Path $run 'recovered/restore-verification.json')
}
Write-Output 'Restore verified; temporary local restore database and user removed. Private recovered files remain for operator inspection.'
