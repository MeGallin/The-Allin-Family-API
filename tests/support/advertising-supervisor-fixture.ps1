param([Parameter(Mandatory=$true)][string]$Directory,[Parameter(Mandatory=$true)][string]$Node,[Parameter(Mandatory=$true)][int]$BridgePort,[Parameter(Mandatory=$true)][int]$NodePort,[ValidateSet('Run','Stop','Enable')][string]$Mode='Run',[switch]$UnsafeToArm,[switch]$ReadinessBarrier)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../../bin/local-advertising/Supervisor.Core.ps1')
$fixture=Join-Path $PSScriptRoot 'advertising-supervisor-child.mjs'
$settings=@{
 Directory=$Directory; SafeToArm=(!$UnsafeToArm); PollMilliseconds=100; DrainSeconds=1; WorkerStop=$null; EngineCheck=$null
 Roles=@(
  @{Name='bridge'; Exe=$Node; Arguments=(Join-NativeArguments @($fixture,'bridge',"$BridgePort")); Cwd=$Directory; Host='127.0.0.1'; Port=$BridgePort},
  @{Name='node'; Exe=$Node; Arguments=(Join-NativeArguments @($fixture,'node',"$NodePort")); Cwd=$Directory; Host='127.0.0.1'; Port=$NodePort},
  @{Name='worker'; Exe=$Node; Arguments=(Join-NativeArguments @($fixture,'worker','0')); Cwd=$Directory; Host=''; Port=0}
 )
}
if ($ReadinessBarrier) { $settings.EngineCheck=@{Exe=$Node; Args=@($fixture,'readiness','0'); Cwd=$Directory} }
Invoke-LocalAdvertisingSupervisor $settings $Mode
