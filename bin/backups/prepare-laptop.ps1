param(
    [Parameter(Mandatory=$true)][string]$StateRoot,
    [Parameter(Mandatory=$true)][string]$ArchiveRoot,
    [Parameter(Mandatory=$true)][string]$RecoveryRoot
)
# Run with PowerShell 7 on Windows. User types secrets directly into this local
# masked form. No passwords appear in arguments, logs, chat or repository files.
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Security
. (Join-Path $PSScriptRoot 'collector.ps1')
$StateRoot=Assert-BackupPath $StateRoot
$ArchiveRoot=Assert-BackupPath $ArchiveRoot
$RecoveryRoot=Assert-BackupPath $RecoveryRoot
if(Test-Path -LiteralPath (Join-Path $StateRoot 'ftps-credential.xml')) { throw 'Collector credential already exists; refuse overwrite.' }
if(Test-Path -LiteralPath (Join-Path $RecoveryRoot 'allin-recovery-key.pem')) { throw 'Recovery copy already exists; refuse overwrite.' }
$null=New-Item -ItemType Directory -Force -Path $StateRoot
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
# Modify only the DACL. Set-Acl can additionally attempt audit-policy writes and
# require SeSecurityPrivilege even when the requested DACL was already applied.
& "$env:SystemRoot/System32/icacls.exe" $StateRoot /inheritance:r /grant:r ('*'+$identity.User.Value+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' | Out-Null
if($LASTEXITCODE -ne 0) { throw 'Cannot secure local collector directory.' }
$acl=Get-Acl -LiteralPath $StateRoot
if(-not $acl.AreAccessRulesProtected) { throw 'Collector directory inheritance is not protected.' }
foreach($rule in $acl.Access) {
    $sid=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if($rule.AccessControlType -eq 'Allow' -and $sid -notin @($identity.User.Value,'S-1-5-18')) { throw 'Unexpected access to collector directory.' }
}
$form=[Windows.Forms.Form]::new()
$form.Text='Allin Family — secure backup setup'
$form.Width=650; $form.Height=505; $form.StartPosition='CenterScreen'; $form.TopMost=$true
$form.FormBorderStyle='FixedDialog'; $form.MaximizeBox=$false; $form.MinimizeBox=$false
$intro=[Windows.Forms.Label]::new(); $intro.Location=[Drawing.Point]::new(20,18); $intro.Size=[Drawing.Size]::new(595,70)
$intro.Text="Enter the restricted FTP account password you just created.`r`nThen choose a separate recovery passphrase (at least 20 characters).`r`nKeep that passphrase privately: it is needed if this laptop is lost."
$form.Controls.Add($intro)
$fields=@{}
$y=96
foreach($field in @(@('ftp','FTPS password — family-backup@theallinfamily.co.uk'),@('recovery','New backup recovery passphrase'),@('confirm','Confirm recovery passphrase'))) {
    $label=[Windows.Forms.Label]::new(); $label.Text=$field[1]; $label.Location=[Drawing.Point]::new(20,$y); $label.Size=[Drawing.Size]::new(590,22); $form.Controls.Add($label)
    $box=[Windows.Forms.TextBox]::new(); $box.UseSystemPasswordChar=$true; $box.Location=[Drawing.Point]::new(20,($y+24)); $box.Size=[Drawing.Size]::new(590,25); $form.Controls.Add($box)
    $fields[$field[0]]=$box; $y+=70
}
$consent=[Windows.Forms.CheckBox]::new(); $consent.Location=[Drawing.Point]::new(20,310); $consent.Size=[Drawing.Size]::new(590,65)
$consent.Text="Save the FTP credential protected by my Windows account. Store only encrypted backup archives in OneDrive, plus a passphrase-encrypted recovery key in a separate AllinFamilyRecovery folder."
$form.Controls.Add($consent)
$save=[Windows.Forms.Button]::new(); $save.Text='Save secure setup'; $save.Location=[Drawing.Point]::new(425,390); $save.Size=[Drawing.Size]::new(185,35); $form.Controls.Add($save)
$save.Add_Click({
    if($fields.ftp.Text.Length -eq 0 -or $fields.recovery.Text.Length -lt 20 -or $fields.recovery.Text -cne $fields.confirm.Text -or -not $consent.Checked) {
        $null=[Windows.Forms.MessageBox]::Show('Enter the FTPS password, matching recovery passphrases of at least 20 characters, and tick the storage confirmation.'); return
    }
    $form.DialogResult=[Windows.Forms.DialogResult]::OK
})
try {
    if($form.ShowDialog() -ne [Windows.Forms.DialogResult]::OK) { exit 2 }
    $null=New-Item -ItemType Directory -Force -Path $ArchiveRoot,$RecoveryRoot,(Join-Path $StateRoot 'staging')
    $credential=[pscredential]::new('family-backup@theallinfamily.co.uk',(ConvertTo-SecureString $fields.ftp.Text -AsPlainText -Force))
    $rsa=[Security.Cryptography.RSA]::Create(4096)
    try {
        $privateBytes=$rsa.ExportPkcs8PrivateKey()
        $protected=[Security.Cryptography.ProtectedData]::Protect($privateBytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
        [IO.File]::WriteAllBytes((Join-Path $StateRoot 'recovery-key.dpapi'),$protected)
        [Array]::Clear($privateBytes,0,$privateBytes.Length)
        $publicPem=[Security.Cryptography.PemEncoding]::WriteString('PUBLIC KEY',$rsa.ExportSubjectPublicKeyInfo()) + "`n"
        [IO.File]::WriteAllText((Join-Path $StateRoot 'recipient.pem'),$publicPem,[Text.UTF8Encoding]::new($false))
        $pbe=[Security.Cryptography.PbeParameters]::new([Security.Cryptography.PbeEncryptionAlgorithm]::Aes256Cbc,[Security.Cryptography.HashAlgorithmName]::SHA256,250000)
        $encrypted=$rsa.ExportEncryptedPkcs8PrivateKey($fields.recovery.Text.ToCharArray(),$pbe)
        $encryptedPem=[Security.Cryptography.PemEncoding]::WriteString('ENCRYPTED PRIVATE KEY',$encrypted) + "`n"
        $recheck=[Security.Cryptography.RSA]::Create()
        try {
            $recheck.ImportFromEncryptedPem($encryptedPem.ToCharArray(),$fields.recovery.Text.ToCharArray())
            if([Convert]::ToBase64String($recheck.ExportSubjectPublicKeyInfo()) -cne [Convert]::ToBase64String($rsa.ExportSubjectPublicKeyInfo())) { throw 'Portable recovery key round trip failed.' }
        } finally { $recheck.Dispose() }
        [IO.File]::WriteAllText((Join-Path $RecoveryRoot 'allin-recovery-key.pem'),$encryptedPem,[Text.UTF8Encoding]::new($false))
    } finally { $rsa.Dispose() }
    $credential | Export-Clixml -LiteralPath (Join-Path $StateRoot 'ftps-credential.xml')
    Write-BackupJson (Join-Path $StateRoot 'collector-settings.json') @{
        hostName='cloud752.thundercloud.uk'; userName='family-backup@theallinfamily.co.uk';
        stateRoot=$StateRoot; archiveRoot=$ArchiveRoot; maximumBytes=2147483648; maximumAgeHours=36;
        recoveryFile=(Join-Path $RecoveryRoot 'allin-recovery-key.pem')
    }
    Write-BackupJson (Join-Path $StateRoot 'setup-complete.json') @{
        completedAt=[DateTimeOffset]::UtcNow.ToString('o'); publicKeySha256=(Get-FileHash -LiteralPath (Join-Path $StateRoot 'recipient.pem')).Hash.ToLowerInvariant();
        credentialProtection='Windows DPAPI CurrentUser'; recoveryKeyProtection='DPAPI plus separately passphrase-encrypted portable copy'; scheduled=$false
    }
    $null=[Windows.Forms.MessageBox]::Show('Secure setup saved. Keep your recovery passphrase private. You can return to the chat.','Allin Family backups')
} catch {
    Write-BackupJson (Join-Path $StateRoot 'setup-failed.json') @{ failedAt=[DateTimeOffset]::UtcNow.ToString('o'); errorType=$_.Exception.GetType().Name }
    $null=[Windows.Forms.MessageBox]::Show('Setup could not complete. No backup schedule has been enabled. Please return to the chat.','Allin Family backups')
    exit 1
} finally {
    foreach($box in $fields.Values) { $box.Clear() }
    $form.Dispose()
}
