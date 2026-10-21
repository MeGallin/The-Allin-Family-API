# The Allin Family - API

PHP and MySQL API for the separate React client. Account creation and password
resets are administrator CLI actions. There is no public registration endpoint.

## Existing local installation

From this API repository, in PowerShell:

```powershell
./start-database.ps1
./serve.ps1
```

The API runs at `http://127.0.0.1:8000`. The React client uses port 5173 and proxies
`/api` requests to it. Use `127.0.0.1` in the browser to match `APP_ORIGIN`.

The project has an isolated MariaDB instance using the installed XAMPP engine,
with its own data directory under `.local/mysql`, bound to `127.0.0.1:3307`.
It runs independently of the XAMPP control panel's MySQL instance on port 3306.
No existing XAMPP databases are needed or replaced. The database persists across
application restarts. Do not delete `.local/mysql` to reset the application.

To stop this project's database gracefully:

```powershell
C:/xampp/php/php.exe bin/stop-local-db.php
```

Ctrl+C stops a foreground PHP API server. Start scripts use hidden background
windows for the database. Data and logs are in `.local/`, which Git ignores.

## First setup on another development checkout

Requirements: installed XAMPP binaries at `C:/xampp`, PHP with `pdo_mysql` and
Argon2id support, and free ports 3307, 8000 and 5173. The current starter remains
compatible with the old local PHP 8.0 runtime. Use supported PHP and database
versions before deploying; the bundled PHP development server is local-only.

```powershell
Copy-Item .env.example .env
./initialise-database.ps1
C:/xampp/php/php.exe bin/create-user.php --username=alex --name=Alex --role=admin
./serve.ps1
```

The initialiser refuses an existing database directory. It creates the local
schema, a dedicated application database user with only SELECT/INSERT/UPDATE/
DELETE access, random database credentials and an application key. It stores the
local database administrator password separately in `.local/mysql-admin.ini`.
Root credentials are never used by web requests. Existing local accounts and
passwords are preserved when `bin/setup-local.php` is rerun.

Each new account gets a different random temporary password in
`.local/account-USERNAME.txt`. The account must choose a new password on first
login. These plaintext handover files are temporary: remove them after the
password has been changed and handed over. Never commit or deploy them.

## Manage accounts

```powershell
# Defaults to the member role; no email service is needed.
C:/xampp/php/php.exe bin/create-user.php --username=person --name=Person
C:/xampp/php/php.exe bin/reset-password.php --username=person
```

Existing usernames are not overwritten by the creation command. Resetting a
password produces a new temporary password and revokes the account's sessions.
Administrators currently manage accounts through these commands, not a web UI.
The admin/member roles are stored for future permission rules.

## HTTP routes

| Method | Route | Access |
| --- | --- | --- |
| GET / HEAD | `/health` | Public liveness only; no database details |
| GET | `/auth/session` | Current user or null, plus a CSRF token |
| POST | `/auth/login` | Existing account, JSON body, CSRF token |
| POST | `/auth/logout` | CSRF token required |
| POST | `/auth/password` | Signed-in account and current password, CSRF token |
| GET | `/family` | Active account with its own password chosen |
| GET | `/shopping` | Shared active items and latest 100 shopping activity events |
| POST | `/shopping/add` | `title`, UUID v4 `requestKey`; authenticated creation |
| POST | `/shopping/edit` | `id` (string), `version` (integer), `title` |
| POST | `/shopping/check` | `id`, `version`, `checked` (boolean) |
| POST | `/shopping/remove` | `id`, `version`; soft removal, preserving history |
| GET | `/profile` | Own public profile, including nickname and profile version |
| POST | `/profile/nickname` | Own `nickname` (string) and `version` (integer) |
| GET | `/admin/users` | Administrator only; active accounts' public profiles |
| POST | `/admin/users/nickname` | Administrator only; target `id`, `nickname`, `version` |

## Shared calendar

Apply migrations `005_calendar_events.sql`, `006_calendar_all_day.sql` and `007_calendar_annual.sql` before deploying calendar support:
`C:/xampp/php/php.exe bin/migrate.php --local` for the isolated local installation.

All active family accounts can view and update the shared calendar. GET `/calendar`
returns active events ordered by start time and the latest 100 calendar activity
records. POST `/calendar/add` accepts `title` (1–80 characters), `detail` (0–300),
`startsAt` (UTC ISO timestamp with milliseconds), boolean `allDay`, an IANA `timeZone`, and a UUID v4
`requestKey`. Retries are deduplicated per session user. POST `/calendar/edit`
accepts the same event fields plus string `id` and integer `version`.
POST `/calendar/remove` takes `id` and `version` and preserves the event as a
soft-deleted record. All writes require an authenticated account and CSRF token.

Creator/updater IDs and UTC timestamps are set by the server. Version checks and
row locks reject stale edits or removals with HTTP 409 `calendar_conflict`.
Actual changes write `calendar_event` activity with actor ID, original full-name
snapshot, before/after values and UTC time in the same transaction. No-op edits
do not create activity. Display names resolve current nicknames/first names.
Timed start instants are stored in UTC; the supplied time zone is retained for
context. All-day entries encode their civil date at UTC midnight with `allDay: true`;
clients display that date without converting it to another day. A non-midnight
timestamp for an all-day entry is rejected. `allDay` defaults to false for older clients.
Boolean `annually` enables yearly recurrence for all-day events, including birthdays.
It defaults to false and is rejected for timed entries. One database record retains
the original date and annual rule; clients derive the next occurrence. No duplicate
rows or scheduled job are needed. Edits and removal affect the whole series and are
audited through the same version-protected endpoints. A 29 February entry falls on
28 February in non-leap years and returns to 29 February in leap years.
Other repeat patterns, occurrence exceptions, reminders and external calendar
synchronisation are not implemented.

## Shared shopping and attribution

Migration `008_shopping_important.sql` adds the shared boolean `important` flag.
Add accepts it optionally (default false); edit can change it alongside the title.
If an older client omits it on edit, the existing value is retained. Other writes
preserve importance. Invalid non-boolean flags are rejected. Reads order important
items first, then by newest creation time/id within each group. Importance edits
use the same version/row-lock checks and actor-attributed before/after activity.

### Nicknames

Apply migration 004 before deploying nickname support. Each account has an optional
nickname and a separate profile version. Members may update only their own nickname;
administrator endpoints enforce the admin role in PHP before listing or editing
other accounts. No name, role or credential editing is exposed by these endpoints.
Names are trimmed, spaces normalised and limited to 40 Unicode characters, without
line breaks or control/bidirectional override characters. A blank string clears the
nickname. The family-facing name falls back to the first word of the full name.
Nicknames need not be unique; permanent account IDs identify people.

Public user profiles include `displayName` (full account name), `nickname`,
`familyName` (nickname or first-name fallback) and `profileVersion`. Shopping actor
`displayName` values use the current family-facing name. Existing actor IDs and
original full-name audit snapshots are retained; shopping history resolves the
current nickname when presented. Changing a nickname never rewrites old events.

Nickname writes lock the target account and require its current version. Stale
edits return 409 `profile_conflict`. Actual changes append `nickname_changed`
activity for the target user with the acting account ID, timestamp and before/after
nicknames in the same transaction. No-op saves create no event. Existing username,
full name, password and session version are unchanged by nickname editing.

All active family members share one shopping list. The list starts empty; preview
fixtures are not imported. Shopping routes require an authenticated active account
that has completed first-login requirements. Every POST requires CSRF validation.
Actor IDs and names come from the server session and database, never request fields.

Apply migrations 002 and 003 before deploying this increment. Items contain creator,
last updater, UTC timestamps and an integer version. Edits, checks and removals lock
the row and compare that version; stale writes return 409 `shopping_conflict` instead
of silently overwriting another member. Add requests use a user-scoped request key
to make retrying a failed response safe. Names are limited to 100 Unicode characters.
Mutation responses return the current list and recent history, as GET does.

`activity_events` records entity type/ID, action, actor ID, actor name at the time,
UTC timestamp and the before/after item title and checked state. Item writes and
activity records share one transaction. Removal sets deleted_at rather than deleting
the item or its history. The latest 100 events are returned to family members;
older events remain available in the database for future authorised analytics.
Indexes support entity history and actor/time analysis. No analytics dashboard is
included yet, and the history is not a cryptographically tamper-evident ledger.

Future persisted input features should use `src/activity.php` inside their write
transactions and include creator/updater metadata in their own tables. Select only
appropriate domain fields for history; credentials and session secrets must never
be included. Calendar, memories and polls are still frontend previews and do not
write activity events yet. This app currently models one family, not multiple tenants.
Unknown routes, including registration routes, return 404. Password hashes and
configuration are never included in responses. POST bodies use JSON with an
`X-CSRF-Token` header. All responses disable caching.

Passwords use Argon2id. Sessions use host-only HttpOnly, SameSite=Lax cookies,
rotate after login and password changes, and expire after 30 minutes idle or
12 hours overall. Logging out invalidates the session. The server reloads account
status/version for private requests: disabling or resetting an account revokes
existing access. Login and password-check attempts are throttled server-side.
Nothing authenticates a user based on a React flag or browser storage.

## Environment and deployment

The API has its own ignored `.env`; `.env.example` is a safe template. Values use
PHP INI syntax: double-quoted strings and semicolon comments. Process environment
variables override the file. Generate a private `APP_KEY` of at least 32 characters.

For Trilogy, supply its MySQL settings, set `APP_ENV="production"`, set
`APP_ORIGIN="https://theallinfamily.co.uk"` to the exact frontend origin and set
`SESSION_COOKIE_SECURE="1"`. Insecure cookies are accepted only on loopback in
local/test mode. PHP needs Argon2id and `pdo_mysql`. Confirm the host provides a
supported PHP version. Serve the frontend and API on the same origin.

The Apache `.htaccess` blocks dotfiles, Git metadata, `.local`, source code,
scripts, tests and migrations. It requires Apache 2.4, mod_rewrite and enabled
.htaccess overrides. These rules have been verified on the local XAMPP Apache
server; verify them again on the live account before deployment. The local PHP
router never serves files directly. Session storage must be writable and private.
Deploy only runtime PHP files and server configuration. Exclude `.git`, local
database files, handover credentials, tests and developer scripts. Prefer placing
private configuration/session storage outside the live web root where possible.

Apply migrations with a database administrator credential, not the runtime user:

```powershell
# Uses the isolated local administrator settings without printing passwords.
C:/xampp/php/php.exe bin/migrate.php --local
```

On hosting, supply migration credentials through the process environment when
running `bin/migrate.php`, then use the restricted runtime credentials for web
requests. Back up the database before applying future schema changes.

## Verification

```powershell
node tests/run-auth.mjs
```

Node is only used to run this development test script; it is not the backend.
The suite creates a separate disposable test database and user, starts a temporary
PHP server, checks authentication and access controls, and removes the fixture.
It never changes family accounts. Local database administrator settings are
required, and the suite refuses to overwrite an existing test database.

Repository: https://github.com/MeGallin/The-Allin-Family-API
