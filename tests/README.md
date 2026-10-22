# API regression tests

From the API directory:

```powershell
node --test tests/regression.test.mjs
node tests/run-auth.mjs
```

The client exposes the same commands as `npm run test:api` and `npm run test:auth`; `npm run test:all` runs both after the client tests. See the sibling client's TESTING.md for the combined coverage matrix.

## Requirements

- Node with native fetch/FormData and node:test (the client requires Node 22.12+).
- The existing local MariaDB instance on 127.0.0.1:3307, with its ignored `.local/mysql-admin.ini` administrator settings.
- API `.env` configured for APP_ENV=local, DB_HOST=127.0.0.1, DB_PORT=3307. The harness refuses a different environment or port.
- PHP with pdo_mysql, Argon2id and fileinfo. The feature test server enables the installed GD extension with an app-only argument and 512M memory, matching serve.ps1. Global PHP configuration is not edited. Set PHP_BINARY for a different executable; Windows default C:/xampp/php/php.exe.

## Layout

- `regression.test.mjs`: lifecycle and module registration using node:test.
- `regression/saving.mjs`: save/read/idempotency, authorship, images/reactions and poll results.
- `regression/privacy.mjs`: ownership, anonymous rejection, session revocation and conditional private reads.
- `regression/permissions.mjs`: administrator/creator rules, CSRF/origin and validation rejection.
- `regression/concurrency.mjs`: competing revisions, stale deletes, no-op history and ballot protection.
- `regression/isolation.mjs`: refuses another create/cleanup operation against the current fixture.
- `support/environment.mjs`: one loopback test server, independent authenticated HTTP clients, startup/stop and fixture ownership.
- `support/regression-fixture.php`: guarded creation/migration/cleanup of the disposable database and private files.
- `run-auth.mjs` / `fixture.php`: existing authentication suite, unchanged and separately isolated.

Tests call the production HTTP routes. They do not bypass server ownership/permission checks by calling domain mutations directly. Fixture accounts are generated only inside `theallinfamily_regression_test`, never in the family database. A fresh schema applies all migrations, and a least-privilege runtime user receives only SELECT/INSERT/UPDATE/DELETE on that schema. Generated passwords/CSRF/cookies are not printed.

The server binds to a free loopback port, uses APP_ENV=test and private `.local/regression/sessions` / `.local/regression/photos`, and stops before cleanup. Cleanup verifies the exact fixed schema/account, configuration paths and matching per-run token stored both privately and in the schema. It refuses unexpected directories, symlinks/files and another runner's token. It removes only known fixture files, without recursive deletion. Ordinary assertion failures still run teardown; a hard process/OS crash may require manual recovery.

## Interrupted-run recovery

If the harness reports that a fixture already exists, inspect it first. Do not delete or repoint the family's database. To clean this harness's marked disposable fixture from the API directory:

```powershell
$regressionFixture = Get-Content .local/regression/fixture.json | ConvertFrom-Json
# Inspect DB_NAME (theallinfamily_regression_test), APP_ENV and private paths first.
# Stop any leftover PHP process for this test run before cleanup.
& C:/xampp/php/php.exe tests/support/regression-fixture.php cleanup $regressionFixture.TEST_RUN_ID
```

The cleanup script verifies the database marker against that token before removing anything. A missing/mismatched marker requires investigation; never bypass the guard. The legacy auth fixture has its own documented `php tests/fixture.php --cleanup` command and database.

## What this proves

Saving/reloading and server enforcement are exercised with a real database; competing stale revisions must yield one 200 and one 409, and stale deletes cannot remove the winner. Tests are sequential by default, with explicit Promise.all for competing sessions. This is not a multi-worker lock stress test. The 20-second wait and Undo are frontend policies tested with virtual timers against all five real Redux removal adapters; the API itself correctly processes a valid removal immediately when it arrives. No artificial 20-second server delay is added.


Poll deadline coverage lives in regression/deadlines.mjs. It verifies required future timestamps, persistence, database-enforced expiry, late-vote/change/withdrawal rejection, reopening with retained votes, permissions, version conflicts and actor/deadline audit history. Existing poll payloads include closesAt. The short expiry case waits for a real two-second cutoff in the disposable regression database.

## Database-release safeguards

Run `node tests/run-database-release.mjs` here, or `npm run test:database-release`
from the client. It is included in test:all. Windows defaults to the existing
XAMPP PHP binary; override PHP_BINARY as needed. The suite requires the configured
local127.0.0.1:3307 environment and reads the ignored local administrator settings.
It creates random synthetic databases named allin_release_test_<token>, private
fixture files, loopback HTTP/CLI workers and guarded restore-verification databases.
No real local family database or production database is opened.

It exercises real dump/restore/data comparisons, version/checksum/target guards,
locks, SQL failures after earlier DDL, preservation checks, backup corruption,
request draining/timeouts, HTTP503 before auth/analytics writes, real CLI
plan/apply/resume and the cPanel precreated-empty restore mode. Database utility
paths currently point to the installed local XAMPP tools. Fixtures contain synthetic
accounts/content only, never cloned real accounts. Normal failures clean owned
databases/private files. After a hard OS/process crash, inspect the exact private
release-tests-<token> fixture and restore-scratch.json ownership records before
manual recovery; never delete databases by prefix alone.

See [DATABASE-RELEASE.md](../DATABASE-RELEASE.md) for the operator workflow,
hosting prerequisites and intentionally manual production restore procedure.
