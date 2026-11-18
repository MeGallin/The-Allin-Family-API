param([Parameter(Mandatory=$true)][string]$SourceNode)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'Supervisor.Core.ps1')
$source=[IO.Path]::GetFullPath($SourceNode)
if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw 'Verified existing Node executable required.' }
if ((Invoke-PrivateCommand $source @('--version') $PSScriptRoot).Trim() -ne 'v24.22.3') { throw 'Expected approved Node v24.22.3.' }
$directory=Join-Path $env:LOCALAPPDATA 'TheAllinFamily\runtime\node24'
$target=Join-Path $directory 'node.exe'
$pin=Join-Path $directory 'sha256.txt'
$hash=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
if (Test-Path -LiteralPath $target) {
    if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $hash) { throw 'Existing runtime differs; do not overwrite a running or unreviewed runtime.' }
} else {
    [IO.Directory]::CreateDirectory($directory) | Out-Null
    [IO.File]::Copy($source,$target,$false)
}
if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $hash) { throw 'Copied runtime hash mismatch.' }
[IO.File]::WriteAllText($pin,$hash + [Environment]::NewLine)
Write-Output 'Pinned Node v24.22.3 provisioned and SHA256 verified. No download or global runtime change.'
