# Routine backups — installed operation and recovery

**Active, 23 October 2026:** nightly encrypted database/photo captures, restricted
FTPS laptop collection and the existing signed-in OneDrive client. A real isolated
restore passed: **15 tables and 12 photo files**, matching schema semantics, row
hashes and photo hashes. Live family content was not restored, replaced or edited.

## Exact successful installation and recovery steps

1. Inspected hosting as recorded below. Used private PHP 8.5 CLI tools and PDO
   export because subprocess execution is disabled. Uploaded versioned tools to
   `/home/theallinfamilyco/private/allin-backup-tools-20261023b/`, outside the web root.
2. Gary created `family-backup@theallinfamily.co.uk`, confined to
   `/home/theallinfamilyco/private/routine-backups/exports`, quota 2000 MB.
   This is directory-confined FTP access, not server-enforced read-only access.
   The collector downloads only, never deletes. Verified public_html inaccessible.
3. Gary entered the FTPS password and chose a separate recovery passphrase in the
   masked local setup form. DPAPI CurrentUser protects credentials and the local
   recovery key under `%LOCALAPPDATA%\AllinFamily\Backups`; NTFS grants Gary and
   SYSTEM only. Hosting has only the RSA public key. The portable passphrase-encrypted
   recovery key is `C:\Users\garya\OneDrive\Backups\AllinFamilyRecovery\allin-recovery-key.pem`.
   Keep the recovery passphrase safe. It is not the Microsoft password and is not
   recorded in documentation, source or logs.
4. Configured private `routine-backups/capture-settings.json` with the exact live
   database, site root, public key and `dumpMethod: "pdo"`. A guarded one-shot cron
   made real captures, encrypted with AES-256 and RSA-OAEP key wrapping. Its brief
   exclusive app request lease coordinates writes; no persistent maintenance marker
   or database mutation. Pause duration was not separately measured.
5. Verified explicit FTPS on `cloud752.thundercloud.uk:21`. Windows curl requires
   TLS and certificate verification; credentials pass through a private stdin config
   pipe, never command arguments, environment or plaintext credential files.
   Disabled curl's user config. No certificate bypass or host restriction changes.
6. Downloaded and SHA256-verified ZIP, key envelope and completion receipt to
   `C:\Users\garya\OneDrive\Backups\AllinFamily`. Latest rehearsed archive:
   `allin-20261023T124701Z-471455503ff6.zip`, 6,275,717 bytes, SHA256
   `b4a9a9fdaaac4798775ac76c49c1172abd6d242ef65ef0c6c9ffbec6419198f1`.
   Keep each `.zip`, `.zip.key.json` and `.zip.receipt.json` together.
7. Used PowerShell 7 `bin/backups/rehearse-local.ps1 -StateRoot <private-state>
   -Archive <accepted-zip>`. Decrypted into a NEW private directory. Provisioned an
   isolated empty local MariaDB 127.0.0.1:3307 restore database and dedicated scoped
   user, imported and compared every schema/table/photo. Temporary DB/user and
   plaintext private key removed in finally blocks. Live connection and retained
   hosting restore database untouched. Cross-version SHOW CREATE formatting differs,
   so compared complete schema semantics including collations/indexes/constraints;
   negative tests detect actual differences. Real result: **15 tables/12 files**.
8. The read-only Windows Cloud Files API reported ZIP/envelope/receipt and portable
   recovery key IN_SYNC through the existing OneDrive session. No new Microsoft
   login or cloud grant. This is provider acknowledgement, **not an independent
   browser/cloud download test**. Private `cloud-verification.json`,
   `last-restore.json` and comparison reports retain evidence. Decrypted rehearsal
   SQL/photos remain under the protected local state directory pending cleanup.
9. Replaced guarded capture cron with the nightly command below. Installed the
   hidden current-user Startup collector and verified its actual process returned
   **exit 0**, checked two sets and confirmed OneDrive IN_SYNC. Task Scheduler launch
   tests failed on this laptop; the experimental task is disabled. The working
   Startup collector uses the bundled PowerShell 7 runtime, no Windows password.
10. Final local suites: **71 PHP checks and 24 collector checks** passed. Live API
    health HTTP 200. No application/client deployment required for backup tooling.

### Active nightly hosting command

cPanel Cron Jobs: `15 2 * * *`, **02:15 UTC** (03:15 BST / 02:15 GMT):

```sh
umask 077; /usr/local/bin/ea-php85 /home/theallinfamilyco/private/allin-backup-tools-20261023b/bin/routine-backup.php --settings=/home/theallinfamilyco/private/routine-backups/capture-settings.json >>/home/theallinfamilyco/private/routine-backups/routine.log 2>&1
```

### Laptop operation and failure checks

- Gary's Windows Startup folder contains `AllinFamily Encrypted Backups.lnk`.
  Program/config/DPAPI state stay outside OneDrive under
  `%LOCALAPPDATA%\AllinFamily\Backups`. Resident source: `bin/backups/collector-resident.ps1`.
- Runs immediately at start/sign-in, then hourly after success; failures retry
  after ten minutes. Single-instance locks prevent duplicate collection. Laptop
  must be awake, online and Gary signed in. Sleep delays collection; after waking
  the loop resumes. After sign-in it catches up missing completed sets. Hosting
  captures continue independently; cloud delivery is not guaranteed with laptop off.
- Success requires exact ciphertext sizes/hashes, a capture at most 36 hours old,
  and OneDrive IN_SYNC for latest ZIP/envelope/receipt plus portable recovery key.
  Pending sync waits briefly then fails/retries rather than claiming success.
- Check private `collector-status.json` (success, attemptedAt, latest,
  oneDriveReportsInSync), `resident-status.json` (lastExitCode/next attempt),
  `resident-error.log` and hosting `routine.log`. No email/push alert configured;
  staleness is checked on collection. An off laptop cannot report failure.
- Manual collection: PowerShell 7 `bin/collect-backups.ps1 -Settings
  <private-state>/collector-settings.json`. Repeat the isolated recovery in step 7
  periodically. New-machine recovery needs the portable encrypted key and Gary's
  recovery passphrase; DPAPI is tied to this Windows account.
- Startup references the Codex bundled PowerShell 7 executable. If relocated or
  removed, update the shortcut to an installed PowerShell 7 executable.
- No automatic pruning. Monitor hosting's 2000 MB quota and OneDrive capacity;
  agree retention before deleting, and preserve verified recovery sets.
- Bundles cover database and memory photos, not .env/application code or all hosting
  files. Keep configuration separately protected. Secrets never belong in Git.

The sections below retain historical inspection/preparation details and safeguards.
The active installation above supersedes historical pending steps.

## Hosting inspection — 23 October 2026

1. Signed-in cPanel account `theallinfamilyco`: JetBackup 5 showed 13 existing
   host-managed backups. Its account UI exposes restore/download and notification
   settings, not a configurable OneDrive destination. These are not evidence of
   our own encrypted off-server copies or an independently verified restore.
2. Cron Jobs initially had no jobs. Uploaded two CLI-only, secret-free capability
   probes through cPanel File Manager into `/home/theallinfamilyco/private`, outside
   all public site folders. A temporary minutely cron executed each probe once;
   each script exits without further work when its report already exists.
3. The default `/usr/local/bin/php` ran PHP 8.3.35. MultiPHP Manager separately
   confirmed the family domain uses PHP 8.5. The site-specific command
   `/usr/local/bin/ea-php85` ran PHP 8.5.11. Both reported ZIP AES-256, OpenSSL and
   PDO MySQL available; both have `proc_open` disabled. The probes found executable
   `/usr/bin/mariadb-dump` and `/usr/bin/mysqldump`, but PHP cannot launch them.
   Host clock/PHP timezone was UTC. No PHP version/security settings were changed.
4. Added an explicitly selected `dumpMethod: "pdo"` export for this hosting
   restriction. It streams schema/rows directly through the existing SELECT
   connection, uses hex SQL values and UTC, and needs no subprocess or credential
   staging. It retains the existing request lease, workflow locks, source-change
   checks and encrypted publication. Generated columns and cross-database foreign
   keys are rejected; views, routines, events and non-InnoDB tables remain rejected.
   Do not silently broaden this supported schema set.
5. Repeated the disposable local restore suite for BOTH external dump and PDO
   export: **65 checks passed** (21 encryption, 22 per export mode). Fixtures include
   foreign keys, Unicode, quote/backslash/NUL/binary bytes, decimal values, dates
   and NULL. These are local fixtures, not a production/cloud restore rehearsal.
6. Disabled further diagnostic execution by editing the same cron row into an
   inert placeholder: schedule `0 0 1 1 *`, command
   `: # Allin backup diagnostic completed; production backups NOT enabled`.
   This is NOT an active backup job. Private probes/reports remain for reference.

Pending transfer choice: a dedicated FTPS credential confined to encrypted exports
plus a scheduled laptop collector is the proposed least-access route. A sleeping
laptop delays the off-server/cloud copy until it wakes, although hosting captures
would continue daily. If cloud transfers must continue with the laptop off, choose
an unattended server-to-cloud route instead. Do not create credentials or grant
OneDrive access without the required action-time confirmation/user handoff.

## Shared implementation

- Private CLI: `php bin/routine-backup.php --settings=/private/routine-backup.json`.
  Never expose tools/settings/archives through a web route. Normal packages exclude
  `bin/` and tests. Hosting requires PHP ZIP AES-256, OpenSSL, PDO MySQL, process
  execution and a verified SQL dump executable for the external method; the
  explicit PDO export method does not need process execution.
- Reuses release configuration, database inventory, deterministic row hashing,
  dump process, verified photo copying and migration/maintenance coordination.
  Captures every table and the complete private photo directory, including originals
  and derivatives. Does not archive `.env`, session files or the private recovery key.
- Checks explicit DB identity, source/output boundaries, free-space estimate,
  source changes and concurrent jobs. Local tests prove SELECT-only capture.
- Holds the app's exclusive request lease only while capturing SQL/photos. Requests
  can return the existing maintenance response during that interval. Encryption
  runs after releasing the lease. Measure this pause and schedule overnight before
  enabling production. No persistent routine-maintenance marker is created.
- Private staging and temporary credential files are removed on normal success or
  failure. An interrupted/killed process can leave private staging for explicit
  operator inspection; never blindly delete leftover folders.
- All ZIP entries, including manifest values, use a random AES-256 password wrapped
  with RSA-OAEP for a separate recovery key. Hosting needs only its public key.
  ZIP filenames remain visible; contents are encrypted. Keep `.zip` and `.key.json`
  together in protected storage. Never place them in public_html.
- Publishes unique archives without overwriting earlier backups. No automatic
  retention/deletion runs in this preparation phase. Capture success does not mean
  off-server transfer or restore verification has succeeded.
- A `.zip.receipt.json` file is published last, with both ciphertext files' sizes
  and SHA256 hashes. Transfer clients must ignore pairs without a receipt, verify
  both hashes before acceptance and retain immutable earlier copies. Receipts are
  integrity metadata over authenticated transport, not a signature or cloud-upload
  confirmation.

## Private capture settings

Example placeholders only; not installed production configuration:

```json
{
  "config": "/home/ACCOUNT/public_html/api/.env",
  "database": "EXACT_EXISTING_DATABASE",
  "siteRoot": "/home/ACCOUNT/public_html",
  "directory": "/home/ACCOUNT/private/routine-backups",
  "publicKey": "/home/ACCOUNT/private/backup-recipient.pem",
  "dumpMethod": "external",
  "dump": "/verified/path/to/mariadb-dump"
}
```

For the inspected restricted host, explicitly choose `"dumpMethod": "pdo"` and
omit `dump`. The installed schedule still must wait for a verified real recovery
key, off-server transfer and production-data restore rehearsal.

The backup directory must already exist, outside the site root and separate from
photo/release storage. POSIX directories/files are 0700/0600. Windows needs private
NTFS ACLs; chmod does not establish them. Existing writers must honour the request
lease; unrelated SQL writers must be coordinated. Credentials stay in private
files, never command arguments or cron output. Failures exit nonzero with a generic
message; utility details remain private.

## Restore safeguards

`bin/backups/bundle.php` verifies archive checksum/recipient, encryption, regular
file types, exact manifest membership, safe paths and recovered file hashes. It
opens only into a new directory; existing folders cannot be overwritten. Always
choose a private folder outside every web root, never the live photo directory.

`bin/backups/verify.php` imports only into the exact source DB name plus `_restore`,
which must be empty. It requires a dedicated restore-only user, rejecting global
admin grants, roles, delegation and access outside that scratch database. Schema,
row hashes and every recovered photo hash must match. It never replaces/drops the
source DB. Do not connect restored sessions/data to the live application.

## Local rehearsal

From API: `node tests/run-routine-backup.mjs`.

The encryption test opens no database. The integration test refuses anything except
APP_ENV=local and MariaDB 127.0.0.1:3307. It creates random synthetic source/restore
databases, a SELECT-only backup user and a separate scratch-only restore user.
After encryption it copies to another test directory (simulated transport), decrypts,
restores and compares SQL schema/rows/photos and unchanged source state. Cleanup
removes only owned fixtures. No real family DB or photo is copied or changed.
The simulated directory copy is **not** a verified cloud/off-server transfer.

Checks cover encryption, wrong passphrase, tampering, unsafe paths, existing output,
source mismatch, storage overlap, migration/concurrency locks, failed dump, plaintext
cleanup, lease release and source-preserving restore guards.

## Historical checklist before automatic live backups were enabled

1. Sign into cPanel. Inspect host-managed backup options, cron support, private tool
   execution, PHP extensions/dump paths and storage allowance.
2. Confirm off-server storage and key custody with Gary. Suggested: immutable
   encrypted bundles in his OneDrive, recovery key outside the sync folder plus a
   separately protected recovery copy. Never keep the only key on hosting or beside
   the cloud archive.
3. Configure authenticated encrypted transfer for the verified host capabilities.
   A laptop may sleep and local OneDrive storage does not prove cloud upload. Add
   catch-up, missing/stale-copy detection and failure reporting.
4. Rehearse a real production backup in a separately provisioned empty scratch DB
   and private photo folder. Compare source/schema/rows/photos without changing the
   live app connection. Record the measured pause and recovery time.
5. Activate the agreed daily schedule/retention after off-server recovery succeeds.
   Never prune the last verified recovery set. Verify job status and storage bounds.
6. Record installed commands, locations and recovery-key custody here and in memory;
   never record passwords or private keys. Keep application configuration separately
   protected for disaster recovery.

During the initial local preparation, no production job, scheduled task or backup
transfer had been installed. The active installation and verified restore are
recorded at the top of this document. Automatic retention deletion remains disabled.
