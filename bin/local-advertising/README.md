# Local Windows advertising supervision

This optional local-only setup is owned by Windows Task Scheduler, rather than a Codex command session. It keeps the existing PHP callback bridge, Node engine and PHP worker alive. It does not host the main API/Vite, change advertising settings, reset usage, Resume an unknown outcome, install a Windows service or deploy anything.

## Why this is needed

The missed18November09:00 run had no provider calls or publication. All three previous Codex command sessions completed with exitCode-1 within3ms at17November21:24:25, one second after the worker's last heartbeat. The same chat was unsubscribed/inactive at21:23:25, exactly60seconds earlier. This strongly ties their joint exit to command-session lifecycle; the exact internal cleanup trigger is not established. Later Windows sleep events do not explain that earlier disappearance. No scheduler algorithm failure is established.

## Operating boundary

Gary must remain **logged in**. Locking the desktop is fine; signing out is not. The PC must be **plugged in and powered on**. The supervisor requests `ES_CONTINUOUS|ES_SYSTEM_REQUIRED` only while AC power is present, preventing automatic idle system sleep while allowing the display to turn off. It releases the request on battery, stop or process exit. It does not change power plans, require an administrator, request display-on, wake the PC every minute or override manual sleep/shutdown. Manual sleep, hibernation, reboot before Gary logs in, network/provider outage and hardware power loss can still miss a scheduled time. After resume/recovery the existing scheduler may perform one late cycle; that is not evidence of on-time success.

Task policy: current Gary Interactive principal, Limited privileges, logon plus indefinite once-per-minute watchdog, `IgnoreNew`, execution time limit0, restart up to3 times at1minute, StartWhenAvailable, allowed to start/continue on battery. No WakeToRun, idle/network condition, password, SYSTEM account or execution-policy bypass. Battery running allows controlled supervision but does **not** prevent battery sleep. Existing CurrentUser RemoteSigned permits these locally authored scripts; do not weaken global policy.

## Parent-run installation and commissioning

Run these reviewed commands from the API repository in an ordinary Gary PowerShell. They are explicit operations, not actions performed by reading this document.

1. In Gary's Advertising admin, **Pause** the service before first enable. The overdue daily09:00 configuration must not silently generate during setup. Enable also refuses an unpaused initial setup with no current advert, overdue publication, or work already within preparation lead. It never changes these settings itself.
2. Provision the already approved Node executable once (no download/global upgrade):

```powershell
.\bin\local-advertising\Provision-Node.ps1 -SourceNode 'C:\Users\garya\AppData\Local\npm-cache\_npx\387698761821791d\node_modules\node\bin\node.exe'
```

This copies exact Node24.22.3 into `%LOCALAPPDATA%\TheAllinFamily\runtime\node24\node.exe` and verifies SHA256 against the supplied trusted binary. It records `sha256.txt`. This protects against accidental drift, not an administrator altering both files. Runtime startup rechecks hash/version and performs no package download. Never overwrite a different existing runtime while children are active.

3. Preview, review, then register (registration refuses an existing task rather than overwriting it):

```powershell
.\bin\local-advertising\Install-Task.ps1
.\bin\local-advertising\Install-Task.ps1 -Register
.\bin\local-advertising\Supervisor.ps1 -Mode Enable
Start-ScheduledTask -TaskName 'The Allin Family - Local Advertising'
.\bin\local-advertising\Supervisor.ps1 -Mode Status
```

4. Confirm the supervisor PID is Task Scheduler-owned and not descended from Codex; inspect `Get-ScheduledTask`, `Get-ScheduledTaskInfo` and process parentage. Confirm bridge127.0.0.1:8001 and Node127.0.0.2:8000, worker heartbeat and signed engine credentials. The main PHP127.0.0.1:8000 and Vite5173 are independent and untouched. Then parent sets a future daily test time/lead through the normal admin and Resumes. Record candidate generation/publication timestamps and the actual Home pair; do not call an overdue catch-up an on-time test.

Node receives pinned nonsecret HOST127.0.0.2, PORT8000, NODE_ENVdevelopment and the exact callback URL. Node loads its existing ignored `.env` for credentials. PHP reads its existing local settings. No secrets are copied, put into task arguments or printed. The signed status check validates engine configuration/credential presence, not provider entitlement or billing. A health check does not call a model.

## Stop, recovery and diagnostics

```powershell
.\bin\local-advertising\Supervisor.ps1 -Mode Stop
.\bin\local-advertising\Supervisor.ps1 -Mode Status
```

Stop commits disarm under a short cross-process control lock, even with missing/broken Node or PHP configuration. Every final child launch uses the same lock and rechecks enabled/stop markers after readiness. No new child can launch after disarm commits; readiness/network checks and worker drain never hold this lock. A launch that wins the lock before disarm is already-started work, not a cancelled launch.

Stop is an **asynchronous request**, not proof that processes or paid work have already stopped. An active supervisor observes it after its current bounded readiness check (up to12seconds) and polling delay (up to5seconds), asks the normal worker to stop, then waits up to350seconds (maximum lease332seconds plus margin) before stopping verified owned dependencies. An already-launched worker may reach its first/next claim before it observes the normal worker-stop request; an already-sent provider request may finish and be charged. Use admin **Pause** first when an immediate domain fence against new paid work is needed. Stop never changes pause settings, usage, grants or unknown-outcome fences. If the supervisor already died, Stop verifies saved process identity and drains surviving owned children synchronously before returning.

Check Status until `running=false`, stopped metadata/empty roles and expected owned-process exit before calling shutdown complete. Enable refuses while a supervisor still owns its lifetime lock, so it cannot erase an active drain's stop marker. Enable is required again before task triggers restart it. Do not use End Task as normal stop; it can interrupt active work. Disabling/deleting the scheduled task alone does not stop already running children—use Stop first.

The supervisor owns a singleton file handle and durable child PID+start-time+executable+exact arguments. After a crash it reattaches only those exact identities; mismatches fail closed. Alive children retain independent inherited Windows NUL output handles, avoiding broken stdout pipes after their old supervisor dies. Raw child output/errors are discarded, never saved. Child exits are recorded and restarted in dependency order; foreign occupied ports are reported and never killed/adopted. Brief probe failures do not kill a living child or an active paid call. Worker heartbeat can legitimately stall through bounded network work, so there is no five-second heartbeat kill policy. A living but hung process requires operator diagnosis/Stop; task recovery targets exits rather than claiming to repair every application hang.

Private operational metadata lives in `%LOCALAPPDATA%\TheAllinFamily\Advertising\supervisor`, separate from advert content. Keep write access restricted to Gary and trusted administrators/SYSTEM; never move it under htdocs. Commissioning found inherited Gary/Admins/SYSTEM FullControl and CodexSandboxUsers ReadExecute, with no broad write access. This is not a literal Gary-only read ACL, and these tools do not change ACLs. `events.jsonl` records fixed event/role/exit-code/time only, rotates at64KiB with one previous file. A retained process handle preserves actual child exit status after process termination; an unavailable/not-applicable status is JSON`null`, never a fabricated successful0. `status.json` gives heartbeat/readiness; `owners.json` has process identity, never credentials/ad bodies. No per-tick verbose logs or archived adverts. Status/Stop resolve this control directory without requiring healthy runtimes/configuration.

Read task State, supervisor/worker heartbeats, dependency readiness and bounded events together rather than relying on LastTaskResult alone. During healthy commissioning, a minute trigger reported `0x800710E0` (`ERROR_REQUEST_REFUSED`) while the task remained Running. This is consistent with `IgnoreNew` declining a duplicate trigger, but the exact cause was not proven by an operational event log. A failed startup otherwise reports a fixed diagnostic; do not interpret a single result code as unique proof of failure or health.

## Isolated verification

```powershell
node --test tests/advertising-supervisor.test.mjs
```

Windows-only tests use random temporary directories, ephemeral loopback ports and dummy Node processes. They cover exited-child restart, supervisor death/recovery with retained streams still writing, singleton, stop, foreign listeners/PID-reuse identity rejection, no raw logs, and refusal to arm unsafe initial work. A deterministic stalled-readiness test completes Stop before releasing readiness, verifies no new worker launches and existing children drain; Enable cannot clear the active owner's stop request. No scheduled task is registered, no actual `.env`/advert state/provider is used, and no persistent power policy is changed. Parent performs installation, real restart/idle resilience and scheduled live/UI checks separately.

Microsoft references: [task settings](https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtasksettingsset), [triggers](https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtasktrigger), [Interactive principal](https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/new-scheduledtaskprincipal), [SetThreadExecutionState](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-setthreadexecutionstate).
