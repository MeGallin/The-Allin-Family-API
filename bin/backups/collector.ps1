# Private Windows collector. Dot-source for tests; invoke through collect-backups.ps1.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-BackupPath([string]$Path) {
    if (-not [IO.Path]::IsPathRooted($Path)) { throw 'Absolute backup path required.' }
    $full = [IO.Path]::GetFullPath($Path)
    $cursor = $full
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if ($item.LinkType -in @('SymbolicLink', 'Junction')) { throw 'Linked backup paths are not allowed.' }
        }
        $parent = Split-Path -Parent $cursor
        if ($parent -eq $cursor) { break }
        $cursor = $parent
    }
    return $full
}

function Write-BackupJson([string]$Path, $Value) {
    $temporary = $Path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    try {
        [IO.File]::WriteAllText($temporary, ($Value | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
        Move-Item -LiteralPath $temporary -Destination $Path -Force
    } finally { if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary } }
}

function New-BackupCurlConfig([string]$HostName, [string]$Name, [pscredential]$Credential, [long]$MaximumBytes, [bool]$Listing) {
    if ($HostName -notmatch '^[a-z0-9.-]+$' -or $Name -cnotmatch '^[a-zA-Z0-9_./-]*$' -or $Name.Contains('..')) { throw 'Invalid transfer target.' }
    $userPassword=$Credential.UserName+':'+$Credential.GetNetworkCredential().Password
    if($userPassword -match '[\r\n\x00]') { throw 'Invalid credential characters.' }
    $escaped=$userPassword.Replace('\','\\').Replace('"','\"')
    $lines=@(('url = "ftp://'+$HostName+'/'+$Name+'"'), ('user = "'+$escaped+'"'),
        'ssl-reqd','tlsv1.2','ftp-pasv','ftp-skip-pasv-ip','connect-timeout = 15',
        'max-time = 300','silent','show-error','fail','noproxy = "*"',
        'proto = "=ftp"',('max-filesize = '+$MaximumBytes))
    if($Listing) { $lines+='list-only' }
    return ($lines -join "`n")+"`n"
}

function Invoke-BackupFtps([string]$HostName, [string]$Name, [pscredential]$Credential, [long]$MaximumBytes, [string]$Destination = '', [bool]$Listing = $false) {
    # Windows' bundled curl handles this host's passive TLS data connection.
    # Disable .curlrc; pass credentials only through the redirected stdin pipe,
    # never command arguments, environment variables or a plaintext config file.
    $configuration=New-BackupCurlConfig $HostName $Name $Credential $MaximumBytes $Listing
    $info=[Diagnostics.ProcessStartInfo]::new()
    $info.FileName="$env:SystemRoot/System32/curl.exe"
    $info.Arguments='-q --config -'
    $info.UseShellExecute=$false; $info.CreateNoWindow=$true
    $info.RedirectStandardInput=$true; $info.RedirectStandardOutput=$true; $info.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new(); $process.StartInfo=$info
    $started=$false
    $output = if ($Destination) { [IO.File]::Open($Destination, [IO.FileMode]::CreateNew) } else { [IO.MemoryStream]::new() }
    try {
        $started=$process.Start()
        if(-not $started) { throw 'Cannot start FTPS transport.' }
        $errorTask=$process.StandardError.ReadToEndAsync()
        $process.StandardInput.Write($configuration); $process.StandardInput.Close()
        $configuration=$null
        $stream=$process.StandardOutput.BaseStream
        $buffer = New-Object byte[] 65536
        $total = 0L
        while (($read = $stream.Read($buffer, 0, $buffer.Length)) -gt 0) {
            $total += $read
            if ($total -gt $MaximumBytes) { throw 'Transfer exceeded its declared size limit.' }
            $output.Write($buffer, 0, $read)
        }
        $output.Flush()
        $process.WaitForExit()
        $null=$errorTask.GetAwaiter().GetResult()
        if($process.ExitCode -ne 0) { throw ('FTPS transport failed (curl '+$process.ExitCode+').') }
        if (-not $Destination) { return ,$output.ToArray() }
    } finally {
        $output.Dispose()
        if($started -and -not $process.HasExited) { $process.Kill(); $process.WaitForExit() }
        $process.Dispose()
    }
}

function Read-BackupFtps([string]$HostName, [string]$Name, [pscredential]$Credential, [long]$MaximumBytes, [string]$Destination = '') {
    return Invoke-BackupFtps $HostName $Name $Credential $MaximumBytes $Destination $false
}

function Get-BackupFtpsNames([string]$HostName, [pscredential]$Credential) {
    $bytes=Invoke-BackupFtps $HostName '' $Credential 1048576 '' $true
    $names=@([Text.Encoding]::UTF8.GetString($bytes) -split '\r?\n' | Where-Object { $_ -ne '' })
    if($names.Count -gt 20000 -or @($names | Where-Object { $_.Length -gt 512 }).Count -gt 0) { throw 'Oversized backup listing.' }
    return $names
}

function Assert-BackupReceipt($Receipt, [string]$Name, [long]$MaximumBytes) {
    if ($Receipt.format -cne 'allin-family-transfer-v1' -or
        $Receipt.file -notmatch '^allin-\d{8}T\d{6}Z-[a-f0-9]{12}\.zip$' -or
        $Name -cne ($Receipt.file + '.receipt.json')) { throw 'Invalid backup receipt identity.' }
    foreach ($field in @('sha256', 'keySha256')) {
        if ($Receipt.$field -cnotmatch '^[a-f0-9]{64}$') { throw 'Invalid backup checksum.' }
    }
    if (($Receipt.bytes -isnot [int] -and $Receipt.bytes -isnot [long]) -or
        ($Receipt.keyBytes -isnot [int] -and $Receipt.keyBytes -isnot [long]) -or
        $Receipt.bytes -le 0 -or $Receipt.bytes -gt $MaximumBytes -or
        $Receipt.keyBytes -le 0 -or $Receipt.keyBytes -gt 16384) { throw 'Invalid backup sizes.' }
    $captured = [DateTimeOffset]::Parse($Receipt.capturedAt, [Globalization.CultureInfo]::InvariantCulture)
    if ($captured -gt [DateTimeOffset]::UtcNow.AddHours(2) -or $captured.Year -lt 2026) { throw 'Invalid capture time.' }
    return $captured
}

function Confirm-BackupFile([string]$Path, [long]$Bytes, [string]$Sha256) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
    $null = Assert-BackupPath $Path
    return (Get-Item -LiteralPath $Path).Length -eq $Bytes -and
        (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ceq $Sha256
}

# Transfer is injected so all publication/error paths can be tested without a server.
function Receive-BackupSet([string]$ReceiptName, [string]$ArchiveRoot, [string]$StagingRoot, [scriptblock]$Fetch, [long]$MaximumBytes = 2147483648) {
    if ($ReceiptName -cnotmatch '^allin-\d{8}T\d{6}Z-[a-f0-9]{12}\.zip\.receipt\.json$') { throw 'Unsafe receipt name.' }
    $ArchiveRoot = Assert-BackupPath $ArchiveRoot
    $StagingRoot = Assert-BackupPath $StagingRoot
    $stage = Join-Path $StagingRoot ([guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $stage
    try {
        $receiptPath = Join-Path $stage $ReceiptName
        & $Fetch $ReceiptName $receiptPath 16384
        if ((Get-Item -LiteralPath $receiptPath).Length -gt 16384) { throw 'Oversized receipt.' }
        $receipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
        $captured = Assert-BackupReceipt $receipt $ReceiptName $MaximumBytes
        $files = @(
            @{ Name = $receipt.file; Bytes = $receipt.bytes; Hash = $receipt.sha256 },
            @{ Name = $receipt.file + '.key.json'; Bytes = $receipt.keyBytes; Hash = $receipt.keySha256 }
        )
        foreach ($file in $files) {
            $destination = Join-Path $ArchiveRoot $file.Name
            if (Test-Path -LiteralPath $destination) {
                if (-not (Confirm-BackupFile $destination $file.Bytes $file.Hash)) { throw 'Existing immutable backup differs; investigate without overwriting.' }
                continue
            }
            $partial = Join-Path $stage $file.Name
            & $Fetch $file.Name $partial $file.Bytes
            if (-not (Confirm-BackupFile $partial $file.Bytes $file.Hash)) { throw 'Downloaded backup checksum mismatch.' }
        }
        # Publish only after both files verify. Receipts are the completion marker.
        foreach ($file in $files) {
            $partial = Join-Path $stage $file.Name
            if (Test-Path -LiteralPath $partial) { [IO.File]::Move($partial, (Join-Path $ArchiveRoot $file.Name)) }
        }
        $finalReceipt = Join-Path $ArchiveRoot $ReceiptName
        if (Test-Path -LiteralPath $finalReceipt) {
            if ((Get-FileHash -LiteralPath $finalReceipt).Hash -cne (Get-FileHash -LiteralPath $receiptPath).Hash) { throw 'Immutable receipt changed.' }
        } else { [IO.File]::Move($receiptPath, $finalReceipt) }
        return @{ file = $receipt.file; capturedAt = $captured.ToString('o'); verifiedAt = [DateTimeOffset]::UtcNow.ToString('o') }
    } finally {
        # This invocation owns this unique direct child; never remove archive files.
        if ((Split-Path -Parent $stage) -cne $StagingRoot) { throw 'Unsafe staging cleanup.' }
        if (Test-Path -LiteralPath $stage) {
            Get-ChildItem -LiteralPath $stage -File | Remove-Item -Force
            Remove-Item -LiteralPath $stage
        }
    }
}
