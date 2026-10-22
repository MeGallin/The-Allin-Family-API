# Database release procedure

This is a separate CLI workflow. App packaging never runs it or uploads its tools.
No production changes were made while implementing it. Test locally with
`npm run test:database-release` from the client, or
`php tests/database-release.php` from this API. The Node wrapper honours PHP_BINARY.
The tests require the isolated local MariaDB at127.0.0.1:3307 and refuse other
environments. They create random synthetic databases, not copies of family data.

## Hosting prerequisites (verify before the first production use)

- Deploy this version's maintenance-capable API **before** a schema-changing release.
  Every request acquires a shared filesystem lease before health/auth/session/DB
  activity. The CLI writes a persistent maintenance marker and takes the exclusive
  lease, draining existing requests for up to15seconds before backing up. New
  requests return503 with Retry-After60. Health intentionally also returns503.
- API and CLI must use the SAME runtime configuration and shared
  DATABASE_RELEASE_DIRECTORY. Its default is the parent of SESSION_DIRECTORY plus
  `/database-releases`; with current production storage this is outside public_html.
  Local default API .local storage is web-blocked, but the release CLI requires its
  configured release storage outside the entire selected site root. Set an explicit
  private path for local release rehearsals, as the tests do.
- Only a single hosting filesystem/shared maintenance path is supported. Scheduled
  jobs or other writers must also be paused/coordinated; an unrelated SQL client can
  bypass application maintenance/advisory locks. Do not change schema during backup.
- Use server CLI access (SSH/cPanel Terminal if the host offers it), matching PHP,
  mariadb-dump/mysqldump and mariadb/mysql executables. Tool paths are explicit; the
  default timeout is15minutes per external utility. Hosting CLI/tool availability
  has NOT been verified by this local implementation. FileZilla alone cannot run it.
- Install reviewed `bin/`, `src/` and `migrations/` from the same API commit in a
  separate private tool directory outside public_html, preserving their relative
  layout. Do not place release scripts/SQL/backups behind a public web endpoint.
- Supply a private deployment credential INI containing ONLY DB_USER and
  DB_PASSWORD. It must be outside public_html; never use the runtime user's file
  or broaden its CRUD grants. Deployment privileges must cover the reviewed DDL,
  dump reads and locking. The default scratch mode also needs database CREATE/DROP;
  on cPanel prefer the precreated verification DB option below to avoid those grants.
  Arrange scoped administrative access with the host. Credentials never go in
  shell arguments: utilities use a temporary0600 option file, removed in finally.
- POSIX backup directories are0700 and files0600. Windows local test protection
  relies on the private user's inherited NTFS ACLs; chmod is not an ACL substitute.
  Keep all backups private, excluded from Git/upload packages. No off-host copy,
  encryption or retention deletion is automated: arrange protected off-host backup
  and retention separately. Runtime configuration backups contain real secrets.
- Current automatic backup verification supports InnoDB base tables with primary
  keys. Views, other engines, routines, events and triggers are rejected before
  migration; extend and test the verifier before introducing those objects.

## Release commands

Use the following common arguments for **every** action. Paths shown here are
examples to adapt and verify on hosting; no credentials are present in them.

| Argument | Value/purpose |
| --- | --- |
| --config | Absolute active public_html/api/.env path |
| --credentials | Absolute private deployment-credential INI path |
| --database | Exact live DB name: theallinfamilyco_family_app |
| --site-root | Absolute public_html path (the current release is backed up) |
| --migrations | Private reviewed release bundle's migrations directory |

The explicit database must match runtime configuration and SELECT DATABASE(). Plans
pin server hostname/port/database, current ordered history and every SQL SHA256.
Both a filesystem lock and a server/database advisory lock reject concurrent runs.
Locks are released on exit; a failed release's maintenance marker persists.

### 1. Local rehearsal and plan review

Develop a new numbered migration without editing old files. Prefer additive schema
changes compatible with the previous API/client. Rehearse on disposable local data
and, when authorised, a private restored production copy. Review exact SQL, data
backfills, table locks, expected row changes and compatible application commits.

Run `php bin/database-release.php --action=plan` with the common arguments. For the
**first** adoption of an existing database history, add `--adopt-history` after
reviewing that its applied001–015 SQL files match the deployment baseline. The
existing ledger has no historical hashes, so this is an explicit trust baseline,
not retrospective proof. Thereafter edits/missing/out-of-order history are rejected.
Never delete/re-adopt history to bypass a mismatch; investigate it instead.

Planning reads the database and writes only private review/checksum files. It does
not alter schema or pause the site. Review the returned plan JSON and pending SQL.
New files must be contiguous numbered SQL migrations starting at001; applied names
must be an exact prefix. If none are pending, no apply is necessary.

Default verification requires all existing application field values/rows/ownership
to survive. New tables/columns are allowed. A reviewed backfill changing existing
values requires `--allow-data-change` **at plan time**, recorded in the plan for
explicit review. This is not a claim that destructive migrations are safe: removed
tables/columns still fail the original-field verification. Such changes need a
separate reviewed staged migration/verifier before use. SQL is trusted reviewed
deployment code, not sandboxed input: do not include USE, database-wide destructive
statements, user/grant changes or cross-database operations.

### 2. Backup, restore verification and apply

Run `php bin/database-release.php --action=apply` with the common arguments plus:

- `--plan=<absolute returned plan path>`
- `--confirm-plan=<returned SHA256, after review>`
- `--dump=<absolute server mariadb-dump/mysqldump executable>`
- `--client=<absolute server mariadb/mysql executable>`

For cPanel, an administrator can first provision an **empty, dedicated**
`theallinfamilyco_family_app_restore` database and grant the separate deployment
user the needed rights on it. Then add
`--restore-database=theallinfamilyco_family_app_restore`. This option requires the
exact source database name plus `_restore`, rejects the source itself and any
nonempty scratch before maintenance, and checks again before restore. It never
drops that precreated database: only the known tables imported by this attempt are
removed, leaving it empty for reuse. The default mode creates/drops an unpredictable
`allin_restore_<24hex>` database instead. Neither option grants privileges itself;
hosting database/user creation and grants need separate explicit authorisation.

Wrong identity, stale plan/history, changed SQL or missing utility paths are rejected
before maintenance. Once it starts, the tool:

1. Persists maintenance and drains active API requests. An undrained request leaves
   maintenance enabled and makes no migration attempt.
2. Backs up current site files (including runtime .env), private photos and reviewed
   SQL into a unique private release folder. Each copied file is SHA256 checked;
   links and overlapping backup sources are rejected.
3. Dumps the complete DB, including schema_migrations, using single-transaction/
   quick/hex-blob/UTF8 options. It checks exit status and non-empty output.
4. Uses the guarded empty scratch database, restores the dump and compares every
   table definition, row count and ordered row-data hash. Only that invocation's
   created scratch DB (or its known imported tables in precreated mode) is removed.
   Source data is rechecked
   after verification. No production restore is performed.
5. Executes each pinned pending migration, consuming all SQL results so later
   statement failures are detected. Records a ledger row/checksum only on success.
6. Checks the ledger and original application data. Writes private before/after
   evidence and stops at `awaiting-verification`, keeping maintenance enabled.

The backup and logs stay in `release-<id>/` outside the web root. The utility log
and status may contain SQL/private details; they are not printed by status output.
Dump SHA256 and restore-verification evidence are retained. Fresh verified backups
are mandatory for every release; a previous successful backup cannot be reused to
skip verification. No automatic pruning or schema rollback occurs.

### 3. Verify, deploy compatible files, then resume

Run `--action=status --release=<id>` with the common arguments. Review private
status/backup verification and, for approved backfills, expected before/after data
changes. Install the matching API/client while maintenance remains enabled. Keep
its configuration and maintenance directory intact. Rehearse feature checks against
the restored/private environment first; live API requests stay blocked until resume.

Then run `--action=resume --release=<id> --verified` with common arguments. This
requires a completed release, the matching maintenance marker, intact history/SQL
checksums, intact verified backup files and unchanged full post-migration schema/data
(including new columns/tables). It records the operator verification
and removes maintenance. Perform the live smoke checks immediately afterwards;
the --verified flag is an operator attestation, not automated visual QA.

## Failure and recovery

Do not blindly rerun partially applied SQL. MariaDB DDL can commit even if a later
statement fails. Failed/incomplete releases cannot use automatic resume; inspect
private status (including currentMigration/failedDuring) and preserve evidence.

For a failed backup before SQL, fix the cause and review the still-unchanged DB,
then perform an explicitly authorised maintenance recovery/new plan. For partial
DDL or unexpected data change, choose a reviewed forward repair or an explicitly
authorised restore from the verified backup. **Restoring production is deliberately
not automated**: preserve the failed state first, verify backup hashes/restore in
isolation, recover the matching DB/application/config/photos, invalidate stale
sessions, reconcile the private checksum history with the restored ledger, and
only then remove the matching maintenance marker and retest. A full DB restore
loses writes made after the backup; the enforced write pause minimises that risk.

If a verification scratch DB/tables cannot be cleaned, restore-scratch.json identifies it
for guarded manual cleanup. Never delete databases by a broad name/prefix match.
The old bin/migrate.php now refuses production; --local remains for isolated local
development. The release workflow never imports the development database into live.
