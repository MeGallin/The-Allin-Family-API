Set-StrictMode -Version Latest

function Get-LocalAdvertisingControl {
    $api = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
    $php = 'C:\xampp\php\php.exe'
    if (!$env:LOCALAPPDATA -or ![IO.Path]::IsPathRooted($env:LOCALAPPDATA)) { throw 'Local application directory unavailable.' }
    $directory = Join-Path $env:LOCALAPPDATA 'TheAllinFamily\Advertising\supervisor'
    $node = Join-Path $env:LOCALAPPDATA 'TheAllinFamily\runtime\node24\node.exe'
    [IO.Directory]::CreateDirectory($directory) | Out-Null
    return @{
        Directory=$directory; Api=$api; Php=$php; SafeToArm=$false; PollMilliseconds=5000; DrainSeconds=350
        Roles=@(
            @{ Name='bridge'; Exe=$php; Arguments=(Join-NativeArguments @('-S','127.0.0.1:8001',(Join-Path $api 'bin/advertising-bridge-router.php'))); Cwd=$api; Host='127.0.0.1'; Port=8001 },
            @{ Name='node'; Exe=$node; Arguments=(Join-NativeArguments @('--env-file=.env','src/index.js')); Cwd=(Join-Path (Split-Path $api) 'dynamic-adverts'); Host='127.0.0.2'; Port=8000; Environment=@{HOST='127.0.0.2';PORT='8000';NODE_ENV='development';ADVERTISING_BRIDGE_URL='http://127.0.0.1:8001/advertising/machine'} },
            @{ Name='worker'; Exe=$php; Arguments=(Join-NativeArguments @((Join-Path $api 'bin/advertising-worker.php'),'run')); Cwd=$api; Host=''; Port=0 }
        )
        WorkerStop=@{Exe=$php; Args=@((Join-Path $api 'bin/advertising-worker.php'),'stop'); Cwd=$api}
        EngineCheck=@{Exe=$php; Args=@((Join-Path $PSScriptRoot 'preflight.php'),'engine'); Cwd=$api}
    }
}

function Get-LocalAdvertisingSettings {
    $settings=Get-LocalAdvertisingControl
    $metadata=(Invoke-PrivateCommand $settings.Php @((Join-Path $PSScriptRoot 'preflight.php')) $settings.Api) | ConvertFrom-Json
    if (!$metadata.local) { throw 'Local configuration required.' }
    $settings.SafeToArm=[bool]$metadata.safeToArm
    $node=$settings.Roles[1].Exe
    $pin=Join-Path (Split-Path $node) 'sha256.txt'
    if (!(Test-Path -LiteralPath $node) -or !(Test-Path -LiteralPath $pin)) { throw 'Provision pinned Node first.' }
    $expected=(Get-Content -LiteralPath $pin -Raw).Trim()
    if ($expected -notmatch '^[a-fA-F0-9]{64}$' -or (Get-FileHash -LiteralPath $node -Algorithm SHA256).Hash -ne $expected) { throw 'Runtime hash mismatch.' }
    if ((Invoke-PrivateCommand $node @('--version') $settings.Api).Trim() -ne 'v24.22.3') { throw 'Pinned Node version required.' }
    return $settings
}

function Join-NativeArguments([string[]]$Values) {
    # These operational arguments are fixed identifiers/paths, never user copy or secrets.
    return (($Values | ForEach-Object {
        if ($_ -match '["\r\n]' -or $_.EndsWith('\')) { throw 'Unsafe process argument.' }
        '"' + $_ + '"'
    }) -join ' ')
}

function New-PrivateProcess([string]$Exe, [string]$Arguments, [string]$Cwd) {
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$Exe; $info.Arguments=$Arguments; $info.WorkingDirectory=$Cwd
    $info.UseShellExecute=$false; $info.CreateNoWindow=$true
    $info.RedirectStandardOutput=$true; $info.RedirectStandardError=$true
    $process = New-Object Diagnostics.Process
    $process.StartInfo=$info
    if (!$process.Start()) { throw 'Process unavailable.' }
    return $process
}

function Invoke-PrivateCommand([string]$Exe, [string[]]$Arguments, [string]$Cwd) {
    $process = New-PrivateProcess $Exe (Join-NativeArguments $Arguments) $Cwd
    try {
        # Only metadata helpers are captured, with a strict short deadline and output bound.
        $output = $process.StandardOutput.ReadToEndAsync()
        $discard = $process.StandardError.BaseStream.CopyToAsync([IO.Stream]::Null)
        if (!$process.WaitForExit(12000)) { $process.Kill(); throw 'Command deadline.' }
        if ($process.ExitCode -ne 0 -or $output.Result.Length -gt 8192) { throw 'Command failed.' }
        return $output.Result
    } finally { $process.Dispose() }
}

function Write-SupervisorJson($Settings, [string]$Name, $Value) {
    $path = Join-Path $Settings.Directory $Name
    $temp = $path + '.' + $PID + '.tmp'
    [IO.File]::WriteAllText($temp, ($Value | ConvertTo-Json -Depth 8 -Compress))
    if ([IO.File]::Exists($path)) { [IO.File]::Replace($temp,$path,[NullString]::Value) }
    else { [IO.File]::Move($temp,$path) }
}

function Read-SupervisorJson($Settings, [string]$Name) {
    $path = Join-Path $Settings.Directory $Name
    if (!(Test-Path -LiteralPath $path)) { return $null }
    $raw = [IO.File]::ReadAllText($path)
    if ($raw.Length -gt 16384) { throw 'Supervisor metadata oversize.' }
    return ($raw | ConvertFrom-Json)
}

function Enter-SupervisorControl($Settings) {
    $path=Join-Path $Settings.Directory 'control.lock'
    $until=[DateTime]::UtcNow.AddSeconds(2)
    do {
        try { return [IO.File]::Open($path,'OpenOrCreate','ReadWrite','None') }
        catch [IO.IOException] {
            if ([DateTime]::UtcNow -ge $until) { throw 'Supervisor control is busy.' }
            Start-Sleep -Milliseconds 20
        }
    } while ($true)
}

function Write-SupervisorEvent($Settings, [string]$Code, [string]$Role='', [Nullable[int]]$ExitCode=$null) {
    if ($Code -notmatch '^[a-z_]{1,40}$' -or $Role -notmatch '^[a-z]{0,12}$') { throw 'Invalid diagnostic.' }
    $path = Join-Path $Settings.Directory 'events.jsonl'
    if ((Test-Path -LiteralPath $path) -and (Get-Item -LiteralPath $path).Length -gt 65536) {
        [IO.File]::Copy($path,(Join-Path $Settings.Directory 'events.previous.jsonl'),$true)
        [IO.File]::WriteAllText($path,'')
    }
    $entry = @{at=[DateTime]::UtcNow.ToString('o'); event=$Code; role=$Role; exitCode=$ExitCode} | ConvertTo-Json -Compress
    [IO.File]::AppendAllText($path,$entry + [Environment]::NewLine)
}

function Get-ProcessIdentity($Process, $Role) {
    return @{pid=$Process.Id; startedTicks=$Process.StartTime.ToUniversalTime().Ticks.ToString(); exe=$Role.Exe; arguments=$Role.Arguments}
}

function Restore-OwnedProcess($Identity, $Role) {
    if (!$Identity) { return $null }
    if ($Identity.exe -ne $Role.Exe -or $Identity.arguments -cne $Role.Arguments) { throw 'Unexpected persisted owner.' }
    try { $process = [Diagnostics.Process]::GetProcessById([int]$Identity.pid) } catch { return $null }
    try {
        if ($process.HasExited -or $process.StartTime.ToUniversalTime().Ticks.ToString() -ne $Identity.startedTicks) { $process.Dispose(); return $null }
        $actual = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $process.Id)
        $expected = '"' + $Role.Exe + '" ' + $Role.Arguments
        $unquoted = $Role.Exe + ' ' + $Role.Arguments
        if (!$actual -or $actual.ExecutablePath -ine $Role.Exe -or ($actual.CommandLine -cne $expected -and $actual.CommandLine -cne $unquoted)) { throw 'Process ownership mismatch.' }
        # Hold a kernel handle while this verified process is alive so its exit
        # code remains queryable after the PID disappears from the process table.
        $null=$process.Handle
        return $process
    } catch { $process.Dispose(); throw }
}

function Test-RolePort($Role) {
    if (!$Role.Port) { return $true }
    $tcp = New-Object Net.Sockets.TcpClient
    try {
        $pending = $tcp.BeginConnect($Role.Host,[int]$Role.Port,$null,$null)
        if (!$pending.AsyncWaitHandle.WaitOne(500)) { return $false }
        $tcp.EndConnect($pending)
        return $true
    } catch { return $false } finally { $tcp.Dispose() }
}

function Set-AdvertisingAwake([bool]$Running) {
    if (!('AdvertisingPower' -as [type])) {
        Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class AdvertisingPower {
 [StructLayout(LayoutKind.Sequential)] public struct Power { public byte AC, Battery, Percent, Reserved; public uint Life, Full; }
 [DllImport("kernel32.dll")] public static extern uint SetThreadExecutionState(uint flags);
 [DllImport("kernel32.dll")] public static extern bool GetSystemPowerStatus(out Power status);
 public static bool Update(bool running) { Power p; bool ac = GetSystemPowerStatus(out p) && p.AC == 1; return SetThreadExecutionState(running && ac ? 0x80000001u : 0x80000000u) != 0; }
}
'@
    }
    if (![AdvertisingPower]::Update($Running)) { throw 'Power request unavailable.' }
}

function Start-OwnedProcess($Role) {
    if (!('AdvertisingChild' -as [type])) {
        Add-Type @'
using System;
using System.Text;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Runtime.InteropServices;
public static class AdvertisingChild {
 [StructLayout(LayoutKind.Sequential)] struct SA { public int length; public IntPtr descriptor; [MarshalAs(UnmanagedType.Bool)] public bool inherit; }
 [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Unicode)] struct SI { public int cb; public string reserved,desktop,title; public int x,y,xSize,ySize,xChars,yChars,fill,flags; public short show,reservedBytes; public IntPtr reservedPtr,input,output,error; }
 [StructLayout(LayoutKind.Sequential)] struct PI { public IntPtr process,thread; public int pid,tid; }
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern IntPtr CreateFile(string path,uint access,uint share,ref SA security,uint creation,uint flags,IntPtr template);
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern bool CreateProcess(string app,StringBuilder command,IntPtr pa,IntPtr ta,bool inherit,uint flags,IntPtr environment,string cwd,ref SI startup,out PI info);
 [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
 public static Process Start(string exe,string arguments,string cwd,IDictionary overrides) {
  var sa=new SA {length=Marshal.SizeOf(typeof(SA)),inherit=true};
  IntPtr output=CreateFile("NUL",0x40000000,3,ref sa,3,0,IntPtr.Zero);
  IntPtr input=CreateFile("NUL",0x80000000,3,ref sa,3,0,IntPtr.Zero);
  if(output==new IntPtr(-1)||input==new IntPtr(-1)) throw new InvalidOperationException("Null stream unavailable.");
  IntPtr env=IntPtr.Zero; PI pi=new PI();
  try {
   var variables=new SortedDictionary<string,string>(StringComparer.OrdinalIgnoreCase);
   foreach(DictionaryEntry pair in Environment.GetEnvironmentVariables()) variables[(string)pair.Key]=(string)pair.Value;
   if(overrides!=null) foreach(DictionaryEntry pair in overrides) variables[(string)pair.Key]=(string)pair.Value;
   var block=new StringBuilder(); foreach(var pair in variables) block.Append(pair.Key).Append('=').Append(pair.Value).Append('\0'); block.Append('\0');
   env=Marshal.StringToHGlobalUni(block.ToString());
   var si=new SI {cb=Marshal.SizeOf(typeof(SI)),flags=0x100,input=input,output=output,error=output};
   if(!CreateProcess(exe,new StringBuilder("\""+exe+"\" "+arguments),IntPtr.Zero,IntPtr.Zero,true,0x08000400,env,cwd,ref si,out pi)) throw new InvalidOperationException("Child launch failed.");
   var child=Process.GetProcessById(pi.pid);
   // Acquire the managed handle BEFORE closing our original native handle.
   // A lazy PID-only wrapper otherwise loses the exit code on .NET Framework.
   var retainedHandle=child.Handle;
   return child;
  } finally { if(pi.process!=IntPtr.Zero) CloseHandle(pi.process); if(pi.thread!=IntPtr.Zero) CloseHandle(pi.thread); CloseHandle(output); CloseHandle(input); if(env!=IntPtr.Zero) Marshal.FreeHGlobal(env); }
 }
}
'@
    }
    $environment=$null
    if ($Role.ContainsKey('Environment')) { $environment=$Role.Environment }
    return [AdvertisingChild]::Start($Role.Exe,$Role.Arguments,$Role.Cwd,$environment)
}

function Invoke-LocalAdvertisingSupervisor($Settings, [string]$Mode) {
    $armedPath = Join-Path $Settings.Directory 'enabled.json'
    $stopPath = Join-Path $Settings.Directory 'stop.json'
    $lockPath = Join-Path $Settings.Directory 'supervisor.lock'
    if ($Mode -eq 'Enable') {
        if (!$Settings.SafeToArm) { throw 'Pause or reschedule overdue advertising before initial enable.' }
        # Do not erase a Stop request while a resident supervisor is still draining.
        try { $enableLock=[IO.File]::Open($lockPath,'OpenOrCreate','ReadWrite','None') }
        catch { throw 'Wait for Status running=false before enabling.' }
        try {
            $control=Enter-SupervisorControl $Settings
            try {
                Write-SupervisorJson $Settings 'enabled.json' @{enabledAt=[DateTime]::UtcNow.ToString('o')}
                if (Test-Path -LiteralPath $stopPath) { Remove-Item -LiteralPath $stopPath }
            } finally { $control.Dispose() }
        } finally { $enableLock.Dispose() }
        Write-Output 'Local supervisor enabled. Start the reviewed scheduled task; advertising settings were not changed.'
        return
    }
    if ($Mode -eq 'Stop') {
        $control=Enter-SupervisorControl $Settings
        try {
            if (Test-Path -LiteralPath $armedPath) { Remove-Item -LiteralPath $armedPath }
            Write-SupervisorJson $Settings 'stop.json' @{requestedAt=[DateTime]::UtcNow.ToString('o')}
        } finally { $control.Dispose() }
        # If the old supervisor died, drain only identities it durably recorded.
        $orphanLock=$null
        try { $orphanLock=[IO.File]::Open($lockPath,'OpenOrCreate','ReadWrite','None') } catch { }
        if ($orphanLock) {
            try {
                $prior=Read-SupervisorJson $Settings 'owners.json'
                foreach ($role in @($Settings.Roles | Sort-Object @{Expression={if($_.Name -eq 'worker'){0}elseif($_.Name -eq 'node'){1}else{2}}})) {
                    if ($prior -and $null -ne $prior.PSObject.Properties[$role.Name]) {
                        $child=Restore-OwnedProcess $prior.($role.Name) $role
                        if ($child) {
                            if ($role.Name -eq 'worker') {
                                try { Invoke-PrivateCommand $Settings.WorkerStop.Exe $Settings.WorkerStop.Args $Settings.WorkerStop.Cwd | Out-Null } catch { }
                                $child.WaitForExit($Settings.DrainSeconds * 1000) | Out-Null
                            }
                            if (!$child.HasExited) { $child.Kill(); $child.WaitForExit() }; $child.Dispose()
                        }
                    }
                }
                Write-SupervisorJson $Settings 'owners.json' @{}
            } finally { $orphanLock.Dispose() }
        }
        Write-Output 'Stop requested; bounded active work drains before owned dependencies stop. Scheduled triggers remain disarmed.'
        return
    }
    if ($Mode -eq 'Status') {
        $held=$false
        try { $probe=[IO.File]::Open($lockPath,'OpenOrCreate','ReadWrite','None'); $probe.Dispose() } catch { $held=$true }
        @{enabled=(Test-Path -LiteralPath $armedPath); running=$held; metadata=(Read-SupervisorJson $Settings 'status.json')} | ConvertTo-Json -Depth 8
        return
    }
    if (!(Test-Path -LiteralPath $armedPath)) { return }
    try { $lock=[IO.File]::Open($lockPath,'OpenOrCreate','ReadWrite','None') } catch { return }
    $processes=@{}; $owners=@{}; $lastEvent=''
    try {
        $prior=Read-SupervisorJson $Settings 'owners.json'
        foreach ($role in $Settings.Roles) {
            $identity=$null
            if ($prior -and $null -ne $prior.PSObject.Properties[$role.Name]) { $identity=$prior.($role.Name) }
            $restored=Restore-OwnedProcess $identity $role
            if ($restored) { $processes[$role.Name]=$restored; $owners[$role.Name]=Get-ProcessIdentity $restored $role; Write-SupervisorEvent $Settings 'child_recovered' $role.Name }
        }
        Write-SupervisorEvent $Settings 'supervisor_started'
        while ((Test-Path -LiteralPath $armedPath) -and !(Test-Path -LiteralPath $stopPath)) {
            Set-AdvertisingAwake $true
            $healthy=$true
            foreach ($role in $Settings.Roles) {
                $name=$role.Name
                if ($processes.ContainsKey($name) -and $processes[$name].HasExited) {
                    Write-SupervisorEvent $Settings 'child_exited' $name $processes[$name].ExitCode
                    $processes[$name].Dispose(); $processes.Remove($name); $owners.Remove($name)
                    Write-SupervisorJson $Settings 'owners.json' $owners
                }
                if (!$healthy) { continue }
                if (!$processes.ContainsKey($name)) {
                    if ($role.Port -and (Test-RolePort $role)) { $healthy=$false; if ($lastEvent -ne ('foreign_' + $name)) { Write-SupervisorEvent $Settings 'foreign_port' $name; $lastEvent='foreign_' + $name }; continue }
                    if ($name -eq 'worker' -and $Settings.EngineCheck) {
                        try { Invoke-PrivateCommand $Settings.EngineCheck.Exe $Settings.EngineCheck.Args $Settings.EngineCheck.Cwd | Out-Null }
                        catch { $healthy=$false; if ($lastEvent -ne 'engine_not_ready') { Write-SupervisorEvent $Settings 'engine_not_ready'; $lastEvent='engine_not_ready' }; continue }
                    }
                    # Readiness may have awaited a slow dependency. Serialize this final
                    # gate and launch with Stop's disarm, rather than merely rechecking.
                    # Never hold the control gate across readiness or child drain.
                    $control=Enter-SupervisorControl $Settings
                    try {
                        if (!(Test-Path -LiteralPath $armedPath) -or (Test-Path -LiteralPath $stopPath)) { $healthy=$false; break }
                        # Independent inherited NUL handles survive supervisor death.
                        $child=Start-OwnedProcess $role
                        $processes[$name]=$child; $owners[$name]=Get-ProcessIdentity $child $role
                        try { Write-SupervisorJson $Settings 'owners.json' $owners } catch { if (!$child.HasExited) { $child.Kill() }; throw }
                    } finally { $control.Dispose() }
                    Write-SupervisorEvent $Settings 'child_started' $name
                    Start-Sleep -Milliseconds 250
                }
                if ($processes[$name].HasExited -or !(Test-RolePort $role)) { $healthy=$false }
            }
            Write-SupervisorJson $Settings 'status.json' @{pid=$PID; heartbeat=[DateTime]::UtcNow.ToString('o'); dependenciesReady=$healthy; roles=@($processes.Keys)}
            Start-Sleep -Milliseconds $Settings.PollMilliseconds
        }
        Write-SupervisorEvent $Settings 'stop_requested'
    } finally {
        # Never use PID-only kill, port-owner kill, or recursive process-tree kill.
        if ($processes.ContainsKey('worker') -and !$processes.worker.HasExited) {
            if ($Settings.WorkerStop) { try { Invoke-PrivateCommand $Settings.WorkerStop.Exe $Settings.WorkerStop.Args $Settings.WorkerStop.Cwd | Out-Null } catch { Write-SupervisorEvent $Settings 'worker_stop_failed' } }
            if (!$processes.worker.WaitForExit($Settings.DrainSeconds * 1000)) { Write-SupervisorEvent $Settings 'worker_drain_deadline'; $processes.worker.Kill(); $processes.worker.WaitForExit() }
        }
        foreach ($name in @('worker','node','bridge')) {
            if ($processes.ContainsKey($name)) { $p=$processes[$name]; if (!$p.HasExited) { $p.Kill(); $p.WaitForExit() }; $p.Dispose() }
        }
        Write-SupervisorJson $Settings 'owners.json' @{}
        Set-AdvertisingAwake $false
        Write-SupervisorJson $Settings 'status.json' @{pid=$PID; stoppedAt=[DateTime]::UtcNow.ToString('o'); dependenciesReady=$false; roles=@()}
        Write-SupervisorEvent $Settings 'supervisor_stopped'
        $lock.Dispose()
    }
}
