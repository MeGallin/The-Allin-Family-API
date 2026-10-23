# The Allin Family - API

Encrypted nightly database/photo backups and the signed-in laptop OneDrive
collector are active. A real isolated restore passed 15 tables and 12 photo files
without replacing live data. Exact installation, schedules, recovery steps and
limitations are documented in [ROUTINE-BACKUPS.md](ROUTINE-BACKUPS.md).

PHP and MySQL API for the separate React client. Account creation and password
resets are administrator CLI actions. There is no public registration endpoint.

## Food alerts

Authenticated `GET /food-alerts` returns the three newest distinct published FSA
recall/allergy/food-action notices, their publication dates, safe official HTTPS
links and the last successful provider check time. No API key, user preference or
database migration. Source: `https://data.food.gov.uk/food-alerts/id?_limit=20&_sort=-created&_view=full`.
Withdrawn, invalid and future notices are excluded. The client links to full FSA
advice rather than making product-safety judgements from partial headlines.

Public transport/cache helpers live in `src/public-feeds.php`, shared with weather
and bank holidays. Requests verify HTTPS certificates, have bounded time/response
size, and never send family data. FSA cache lives beside private session storage
in `public-feeds`, refreshed on demand after 30 minutes, with a nonblocking lock,
five-minute failure backoff and a six-hour maximum stale fallback. Stale data is
labelled; unavailable is distinct from an empty feed. Windows paths are canonicalised
before locked writes so configured `C://...` paths persist actual cache files.

Each notice includes optional normalised `details`: affected product names, pack
sizes/product codes, batch/date descriptions, risk statements and consumer advice.
HTML is converted to plain text before storage; React also renders it as text.
Missing/invalid optional fields stay empty/null for explicit UI fallbacks. The v2
cache key forces a fresh full-view fetch after upgrading from headline-only data.

Tests: `C:/xampp/php/php.exe tests/food-alerts-unit.php` (34 checks), plus
`C:/xampp/php/php.exe tests/week-context-unit.php` (7 shared-feed checks).
Initial FSA integration requires API and client packages together; there is no
database change. The later dialog/accordion restyle is a client-only deployment.
The client opens these notices in the shared Modal from Home's calendar badge;
there is no longer a dedicated Alerts page or navigation entry. The historical
`/alerts` usage label remains readable in admin reporting. No alert titles or IDs
are recorded in usage telemetry.

## Week Ahead weather and bank holidays

The authenticated `GET /week-context` endpoint supplies optional public context;
it never sends family events, account details or notes to external providers.
No API keys or seed data are needed. Migration `016_week_preferences.sql` adds
the per-account `user_week_preferences` table. In Your profile, each person can
search/select a town, select a UK holiday region, or switch either feed off.
Guildford, Surrey and England and Wales apply until that person saves a choice.
Town search uses Open-Meteo/GeoNames town centres, not a street address or GPS.
Forecasts use the selected town's time zone, displayed in forecast details.

GET/POST `/profile/week-preferences` access only the session user's row; the
browser cannot supply an owner ID. Updates use optimistic versions and record
the actor, town label and region in the existing admin activity log. Full
coordinates are not exposed to other profiles or recorded in the activity log.
`GET /week-context/towns?q=...` returns signed, expiring choices tied to the
current user, so a save cannot invent coordinates or substitute an arbitrary URL.
Location search is explicit (no request on every keystroke), cached and throttled.

PHP cURL with working CA certificates and verified outbound HTTPS is required.
Weather comes from Open-Meteo (free non-commercial service; attribution displayed)
and bank holidays from GOV.UK. Daily weather is in Celsius and the town's local time.
Only fixed provider hosts are used; request parameters cannot supply a URL.

The private cache defaults to `dirname(SESSION_DIRECTORY)/week-context`, or set
`WEEK_CONTEXT_DIRECTORY` to a PHP-writable directory **outside public_html**.
Weather refreshes after 30 minutes; holidays after 24 hours. Concurrent refreshes
use a non-blocking lock; failures back off for five minutes. Saved forecasts are
usable for at most six hours and holiday dates for seven days, with stale labels.
Each provider has a four-second timeout, HTTPS certificate verification, bounded
response size and validated response fields. Either provider can fail independently.

Deploy the new additive migration using the separate verified-backup database
release workflow before deploying both the API runtime and client. Retain the
production `.env`, database and private storage. Only WEEK_CONTEXT_DIRECTORY may
need server configuration; town/region choices are in the database. Deployment
packages deliberately exclude the active configuration and migrations.

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
be included. Shopping, calendar and memories now write activity events; polls are
still a frontend preview. This app currently models one family, not multiple tenants.
Unknown routes, including registration routes, return 404. Password hashes and
configuration are never included in responses. POST bodies use JSON, except the
multipart memory forms; both require an `X-CSRF-Token` header. All responses disable caching.

## Shared memories and private photo storage

For owner-only personal notes, see the separate section below; they are not shared
memories and never enter the family activity feed.

Apply migration 009 before deploying this increment. `/memories` returns the family
album, newest first, plus the latest 100 audit events. `/memories/add`, `/edit`,
`/remove` and `/react` accept multipart forms with a JSON `payload` field and an
optional `photo` file. Every route, including `/memories/photo?id=...&v=...`, requires
an active signed-in family account. All family members may add/edit; removal is
restricted to the creator or an administrator, enforced on the server.

Each memory needs an 80-character title and a photo, story or both. Stories allow
5,000 Unicode characters; photo descriptions allow 160. Edits/removals lock the row
and require its version, returning 409 `memory_conflict` on a stale write. Addition
keys are user-scoped and unique so retries do not duplicate a saved memory. Creator
and updater IDs/timestamps come from the session. Full before/after text/photo
references and actor metadata are recorded in the same database transaction.

Photos are stored as original raster files in `MEMORY_PHOTO_DIRECTORY` (defaults
to ignored `.local/memory-photos`). Files never have public static URLs: random
storage keys map to authenticated private image responses with nosniff headers.
Photo cache entries must revalidate authentication; see the performance section below.
Filenames supplied by the user are not used for storage. MIME type and image headers
are validated server-side: JPG/PNG/WebP only, up to 10 MiB, 24 megapixels and 8192px
per side. SVG, arbitrary files and unsupported phone formats are rejected. File
validation uses PHP `fileinfo` and `getimagesize`; GD is required for resized previews,
with original delivery retained as a fallback. Originals are
not resized or stripped of embedded metadata. Upload errors preserve the form draft.

Configure writable private storage outside the public web root on hosting. If using
the local default, retain the provided Apache `.local` denial rules and PHP router.
Ensure PHP `upload_max_filesize` is at least 10M, `post_max_size` is at least 11M and
the web server permits that request size. Back up the database **and** private photo
directory together; photos are not stored in MySQL. Do not deploy or commit uploads.
Failed writes clean up newly staged files; superseded photos are removed after a
successful edit. An uncertain COMMIT outcome retains the file rather than risking
a missing saved photo. Soft removal hides the memory/photo and retains the photo
and audit history for recovery/retention management; no restore screen is provided.

Love reactions are stored once per memory/user and survive refresh. Setting the
same desired reaction twice is idempotent; changes record the actor/time. Reactions
do not modify content creator/updater dates or cause false edit conflicts.

## Private personal notes

Apply migration 010. `GET /notes` returns only the active session user's notes and
the latest 50 entries in their private note history. `POST /notes/add`, `/notes/edit`
and `/notes/remove` require JSON and CSRF. Every SELECT, row lock and UPDATE is
scoped to the owner ID from the authenticated session. There is no administrator
bypass or target-user parameter. Missing/foreign note IDs return the same 404;
valid owner requests with stale versions return 409 `note_conflict`.

Title/body are required, with optional header/footer and a required civil note date
in YYYY-MM-DD form. Limits are 120 title characters, 500 each for header/footer,
20,000 for body and a bounded 100,000-byte JSON request. Other JSON routes retain
their existing 8,192-byte limit. Plain text is validated as UTF-8, control characters
are rejected and rendered as text. Civil note dates are stored separately from UTC
created/updated timestamps. User-scoped UUID addition keys make retries idempotent.

`personal_note_history` stores owner/actor ID, note ID, action, UTC time and full
before/after data in the same transaction. This content is never written to the
shared `activity_events` table or returned by family/admin feeds. The notes activity
API sends only the owner's title/action/time summaries. Owner IDs also identify
the author/editor: only that owner can write. Deletion sets deleted_at and preserves
private history; there is no restore UI. No-op saves create no history event.

Notes are database records protected by server-side ownership checks, not an
end-to-end encrypted vault; database/server operators and backups remain within
the hosting trust boundary. Keep backups private and exclude database files from
Git. No sample personal notes are seeded.

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
`APP_ORIGIN="https://www.theallinfamily.co.uk"` to the exact frontend origin and set
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
running deployment tools, then use the restricted runtime credentials for web
requests. Production `bin/migrate.php` is now blocked: use the separate private
`bin/database-release.php` workflow described in [DATABASE-RELEASE.md](DATABASE-RELEASE.md).
It requires an explicit target/reviewed plan, maintenance, a fresh database/files
backup and a successful scratch restore before applying pending SQL. It leaves
maintenance enabled for verification and explicit resume. Hosting prerequisites
must be checked before first use; app packaging does not include/run this tool.

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


## Family polls and administrator activity

Apply migration 011 before deploying the polls client. GET /polls returns active
polls, fixed choices, aggregate results and only the signed-in person's current
ballot/revision. There is no sample poll seed. All signed-in family members may
POST /polls/add with a title (1-160 characters), optional detail (up to 1000),
two to six distinct choices (1-80 each) and a UUID requestKey. Text is plain UTF-8
and the existing bounded JSON/CSRF/origin checks apply. User-scoped keys make
unchanged addition retries idempotent.

POST /polls/vote accepts id, poll version, voteVersion and optionId. A null option
withdraws the vote. The voter ID always comes from the session; client actor/user
fields cannot change it. A primary key enforces one ballot per person/poll and a
composite foreign key keeps choices inside their own poll. A poll-row lock
serialises voting with close/remove; independent ballot revisions reject stale
changes from another window without making other people's votes conflict.
Repeated identical votes are no-ops. Voting does not alter the poll editor/version.

POST /polls/edit changes only title/detail. /polls/close takes a closed boolean and
can close/reopen voting. /polls/remove sets deleted_at. These three operations are
creator/admin only and require the latest poll version. Closed/removed polls reject
new votes. Choices are immutable so edits never silently reinterpret existing
votes. Soft removal retains ballots and the activity history. Client record removal
uses the common 20-second countdown/Undo; the API applies the final authorised,
version-protected request after the delay.

Poll creates/edits/close/reopen/removals and votes/changes/withdrawals write actor,
UTC time and before/after details to activity_events in the same transaction.
Voting is not anonymous: the client explicitly tells participants that
administrators can inspect voter names and choices.

GET /admin/activity requires an authenticated administrator. It returns newest
shared changes in pages of 50, with optional type, actor and before-ID filters and
nextCursor. Types are explicitly limited to shopping_item, calendar_event, memory,
poll and user_profile. Memory likes/unlikes are already recorded here. Current
nicknames identify actors, and profile changes include the affected person's name.
Personal note content/history is never included, including for administrators.
Only existing shared-content snapshots are exposed; credentials and sessions are
not part of this feed. No super-admin role was introduced.

Generated local examples were removed at Gary's explicit request. Maintenance
recovery copies remain private under ignored .local/cleanup-backups; those files
and real database/media content must never enter Git or deployment bundles.

## Administrator insight aggregation

GET /admin/insights requires the existing admin role and validates days=7,30,90.
It returns a consistent read snapshot of the selected period: daily shared actions
by category, successful sign-ins, distinct daily contributors, feature rankings,
all-member contribution/login/estimated-time figures and coverage metadata.
Aggregation covers complete audit history in the date range, not an audit-feed
page. SQL aggregates by UTC hour/actor/type/action; PHP groups into Europe/London
calendar dates, including DST boundaries. Only aggregate counts leave this route;
audit snapshots, credentials and private note records are not fetched.

sharedActivityTypes() supplies one explicit allowlist to both the audit feed and
insights. Categories are created, updated (including shopping checks), responded
(votes/reactions including changes/withdrawals), removed. No shared note category
is permitted. Before available history begins, daily values are null rather than
invented zeros. Prior-period action totals are withheld until recorded history
predates that full prior period. This is observed coverage, not a guarantee that
unrecorded historic usage can be reconstructed. Today is partial.

reporting.php owns the shared London calendar-day period bounds. Usage details now
uses the same bounds, so an overview member drill-down keeps the same period. Active
time remains the sum for sessions started in that period; it is not fabricated into
daily time allocations or per-feature dwell time. No migration or fixture data is
needed for this increment.

## Administrator platform usage

Apply migrations012 and013 before deploying this increment. Successful /auth/login creates
one platform_sessions record with a login timestamp. Password changes rotate the
authentication session while preserving the usage record, so they do not inflate
login counts. Sessions already open when tracking is introduced are first observed
by presence and have a null login_at. No earlier login history is invented.

POST /auth/presence uses existing authentication, CSRF and origin checks, accepting
only a section page and boolean active flag. The user and tracking key come from
the server session. An atomic update credits the previous active interval only
when the server-clock gap is no more than60 seconds. Overlapping heartbeats from
tabs sharing a login cannot double-credit the same elapsed interval. Missing or
long-delayed heartbeats do not credit offline time. Activity estimates are not
exact attendance records and can undercount interrupted/closed tabs.
The heartbeat clock is separate from last active time: inactive/background signals
do not make a member appear recently active or overwrite the last active section.

The client sends presence about every30 seconds while visible and on navigation,
idle recovery and visibility changes. It considers input recent for60 seconds;
ordinary content refresh/polling does not count as input. Hidden tabs stop periodic
presence requests. Explicit logout records its server time and current section.
Expiration, revocation and replacement are distinguished from logout. Sessions
with no signal for over120 seconds display inactive, with no claimed logout time.
No unreliable browser-unload request is required.

GET /admin/usage is server-enforced admin-only. Validated filters days (7,30,90,0
for all history), user and before-ID return all member summaries and up to25 recent
sessions with a cursor. Totals cover sessions started in the selected period;
last-login and last-seen timestamps cover all history. All accounts are included,
including ones with no recorded use. The private tracking key is never returned.
Only allowlisted section names are saved: no note IDs/content, queries, IPs,
device fingerprints or raw authentication identifiers. Usage is separate from
the shared content audit feed. Times are UTC; the UI formats them locally.

## Administrator family accounts

- GET /admin/members: all active and paused accounts as public member profiles,
  with enabled and createdAt. Hashes and authentication versions are never returned.
- POST /admin/members/add: username, displayName, nickname, role (member/admin),
  enabled=true. Creates an account requiring a password change and returns a
  generated temporaryPassword once, alongside member and the actor's self profile.
- POST /admin/members/edit: the same editable fields plus integer id and version
  (profileVersion). Can pause/restore access and change roles. No-op saves do not
  produce audit entries. Stale versions return409; duplicate usernames return409.
- POST /admin/members/password: integer id and version. Resets another active
  member's password, requires a change at next sign-in and returns the generated
  temporary password once. The admin must share it privately. Own passwords use
  the existing current-password flow, not the support reset endpoint.

All routes use existing authenticated server roles and CSRF/origin checks. Account
writes lock the family directory in ID order, revalidate the actor inside the
transaction and compare profileVersion to prevent stale writes. Admins cannot
pause or demote themselves, so concurrent edits cannot remove the final active
administrator. Password resets, role changes and access changes increment
existing auth_version and revoke tracked sessions atomically. Changing one's own
password revokes other sessions, retains the current usage record and increments
profile_version as well, so a pending admin edit cannot mask the changed state.

recordActivity stores user_account events in the same transaction: creation,
edits, password_reset and password_changed. Only explicitly projected public
fields reach before/after history. Passwords, hashes, auth versions and session
keys are excluded. The existing allowlist, account target join and insight
aggregation include these events. Paused users retain content and audit history.
The generated credential is hashed with the existing Argon2id helper; plaintext
is returned only in the no-store response and never saved to disk. Lost responses
cannot recover a password; inspect the directory before retrying creation or issue
a fresh reset if necessary. No email delivery or migration is part of this feature.

Database connections explicitly set the session time zone to UTC. This keeps
CURRENT_TIMESTAMP defaults (including account creation) aligned with explicit
UTC audit/usage timestamps, avoiding future-looking join times on BST servers.
Existing stored DATETIME values are not rewritten.

## Shared reporting-period contract

Overview insights, usage and shared activity use familyReportingPeriod and the
Europe/London calendar window in src/reporting.php. Valid periods are 7, 30 and
90 days (default 30); Usage and Activity additionally accept days=0 for all recorded
history. Windows include today, begin at London midnight and end exclusively at
next London midnight; UTC SQL bounds preserve daylight-saving transitions.
GET /admin/activity now applies days alongside actor/type/before and returns days,
from (null for all history), and through (London calendar dates). Existing shared
entity allowlists exclude personal notes. Usage aggregate/session rows have both
start and exclusive end bounds on started_at; lifetime last-login/seen fields and
session-start cohort semantics remain unchanged. No migration or data rewrite.

## Performance: media, conditional snapshots and larger collections

Apply migration014_media_performance.sql before this increment. Locally:

```powershell
C:/xampp/php/php.exe bin/migrate.php --local
C:/xampp/php/php.exe bin/optimise-photo-metadata.php
C:/xampp/php/php.exe -d extension=gd -d memory_limit=512M bin/prepare-photo-previews.php
./serve.ps1
```

Production migrations use appropriate deployment credentials; the web application
account retains its existing SELECT/INSERT/UPDATE/DELETE permissions. The migration
adds nullable oriented photo dimensions and an (entity_type,id) recent-history index.
The metadata command fills missing dimensions in100-row batches without changing
user text, authors, versions or timestamps. It is repeatable; missing files are
reported without deleting their rows. Uploads store dimensions for new photos.

The local serve script enables the installed GD extension and512MiB request memory
for this app only, without editing global php.ini. Hosting should enable GD and
EXIF, with enough memory for supported24-megapixel uploads. Private derivatives
are generated at allowlisted widths160/480/960/1600, preserving aspect ratio and
JPEG orientation, avoiding upscaling, preferring WebP with JPEG/PNG fallbacks.
Memory estimates prevent unsafe decoding; encoder/storage failures retain original
delivery. A derivative larger than its original is not sent. Originals are never
resized or rewritten. First requests use locked atomic cache creation; the optional
preparation command warms caches ahead of visits. Superseded image cleanup includes
derivatives. Cache files/locks stay in private storage and Git-ignore boundaries.

The existing photo endpoint accepts optional width; unrecognised sizes are404.
Every request checks the active session, current memory and matching photo key
before streaming or304. Responses use private,no-cache plus ETag: browser reuse
requires authenticated revalidation, and no public static route is introduced.
The no-width URL remains the original for the existing View full photo action.

GET shopping/calendar/memories/polls/notes now return an authenticated ETag based on
the domain's latest audit revision, account/profile revisions and current user.
An unchanged conditional request returns304 before rebuilding the full snapshot.
JSON still uses no-store; the React transport keeps only the last five allowlisted
snapshot responses in session memory and clears them on sign-out/session reset.
Normal content writes must keep recording their existing transactional audit event;
maintenance that bypasses those writes must reload clients or revise the snapshot
version. SQL edits without audit events are not part of this cache contract.

Family memory activity now projects titles and story/photo/description change flags,
instead of repeatedly transferring full historical stories. Full before/after audit
records remain unchanged in the database and admin activity endpoint. Actual current
stories remain available in full. Recent family history limits stay100 (private50).
The new feature-history index supports both bounded feeds and revision lookups.

Collection windows are client render windows, not server pagination: initial and
changed snapshots still return the complete active collection so global filtering,
counts, audit attribution and versioned writes retain their existing meaning.
Conditional reads and route-scoped subscriptions reduce repeated transfer/work;
very large archives would require a separate server-pagination/search contract.

## Repeatable feature regression

Run `node --test tests/regression.test.mjs` for real HTTP/database saving, ownership, permissions and competing-version checks. [tests/README.md](tests/README.md) documents the isolated fixture, commands, cleanup safeguards and recovery. The existing authentication suite remains `node tests/run-auth.mjs`. The client now owns a shared **20-second** delayed-removal/Undo policy; API mutations remain immediate when submitted.


## Poll closing times (migration 015)

Run bin/migrate.php --local for the isolated local installation; deployment requires applying 015_poll_deadlines.sql before the new client/API. polls.closes_at is a required UTC DATETIME(6). Legacy open polls receive a four-hour transition window from migration time; closed legacy polls retain closed status with a cutoff four hours after creation. No poll contents or votes are removed.

Add/edit require closesAt as an explicit ISO timestamp with timezone. New or changed deadlines must be in the future, checked against database UTC time. An unchanged historical cutoff may be retained when editing a closed result. Reopening via edit requires reopen:true and a future closesAt. Ordinary edits preserve closed status. Creator/admin permission checks, versions, ballot conflict handling and idempotency remain in place; retrying an existing addition is still accepted after its deadline.

GET snapshots return effective closed status (manual close OR deadline passed). Vote writes recheck the database clock after obtaining the poll lock, rejecting new votes, changes and withdrawals at/after cutoff. Expiry is computed from the stored timestamp and needs no cron worker or open browser. Scheduled expiry does not invent a human actor/activity row. Creation, edits and reopening record closesAt in existing before/after admin history with the session actor. Final vote totals remain available.

## Manual cPanel/phpMyAdmin database release (no SSH)

This is an explicitly approved manual alternative to the automated procedure in
[DATABASE-RELEASE.md](DATABASE-RELEASE.md). It does not run the CLI's lock/checksum
state machine. Do not claim CLI verification, create a public migration endpoint,
import the development database into live, or grant schema permissions to the
runtime app user. Prefer additive migrations compatible with the previous runtime.

### Procedure used on 22 October 2026 for migration 016

1. **Prepare and test locally.** Commit the client/API and run the regression,
   authentication, database-release, build, formatting and PHP syntax checks.
   This release used client `05e1058` and API `f44d409`.
2. **Enable maintenance before taking backups.** In cPanel File Manager, use the
   account-relative location `private/allin-family/database-releases` (the absolute
   server location is `/home/theallinfamilyco/private/allin-family/database-releases`).
   Confirm no existing maintenance release is in progress. Create `maintenance.json`
   with **+ File**. This API checks file existence, so an empty file is sufficient.
   Confirm `/api/health` reports code `maintenance`; all API routes are paused before
   authentication, database and usage writes. Let existing requests finish before
   taking the backup; check the paused snapshot against live again before migration.
   This manual process does not acquire the CLI's exclusive request lease.
3. **Download both backups.** cPanel **Tools > Backup > Partial Backups**:
   - Under **Download a Database Backup**, click `theallinfamilyco_family_app`.
   - Under **Download a Home Directory Backup**, click **Home Directory**.
   - Successful downloads were `theallinfamilyco_family_app.sql.gz` (5,160 bytes)
     and `backup-theallinfamily.co.uk-10-22-2026.tar.gz` (284,923,790 bytes).
   - Copy into private, Git-ignored local recovery storage, never an upload package:
     `api/.local/production-releases/20261022-week-ahead/`, as `before.sql.gz` and
     `home-before.tar.gz`. Keep them protected: the SQL includes account hashes and
     private data, and the home archive includes the production `.env`.
4. **Verify downloaded files.** Calculate SHA256 for both originals; decompress
   the SQL successfully (27,710 bytes) and list the complete home archive (4,146
   entries). Confirm it contains `public_html/api/.env`, the current application,
   and `private/allin-family/photos/` including originals and previews. The archive
   also contains the maintenance marker; any later restore therefore needs an
   explicit maintenance/recovery review. Check the SQL contains no `USE` or
   `CREATE DATABASE` directive before importing into a differently named database.
5. **Create a separate empty restore target.** cPanel **Manage My Databases >
   Create New Database**, enter `family_app_restore`, yielding
   `theallinfamilyco_family_app_restore`. Do not assign the runtime app user.
   Open phpMyAdmin from cPanel; select that exact scratch database and confirm
   **No tables found**. Never import a backup over the live database to test it.
6. **Restore and verify.** In scratch **Import**, choose `before.sql.gz`, UTF-8/SQL,
   and click **Import**. This backup restored successfully with 153 queries.
   Through the SQL tab, compare every source/restored row using primary-key joins
   (including composite keys) and NULL-safe equality for every column; compare
   both directions so missing/extra rows are caught. Compare information_schema
   columns, index definitions, table constraints, engines and collations as well.
   All 14 original tables matched, with zero mismatched/extra rows and zero
   metadata differences. Counts alone are not sufficient verification.
7. **Rehearse the exact migration in scratch.** Explicitly target
   `USE theallinfamilyco_family_app_restore;`, run the reviewed
   `migrations/016_week_preferences.sql`, then record its exact filename in
   `schema_migrations` only after successful DDL. Confirm 16 migration rows, an empty
   `user_week_preferences`, primary key `user_id`, and its foreign key to `users.id`.
   This migration creates only a new table and changes no existing family records.
8. **Apply to live while maintenance stays enabled.** Select the live database,
   open **SQL**, clear the editor and explicitly target
   `USE theallinfamilyco_family_app;`. Execute the same reviewed migration and its
   history insertion. Confirm no SQL errors and verify the new table/history.
   Compare all original rows with the restored snapshot again, along with the
   rehearsed schema. The new ledger row's `applied_at` timestamps legitimately
   differ between rehearsal and live; compare that row's name while retaining
   exact timestamp comparisons for older history. All original records matched,
   both schemas matched, and both new preferences tables were empty.
9. **Upload matching runtime files under maintenance.** Run `npm run build:deploy`
   from the client. In FileZilla, verify local
   `C:\xampp\htdocs\WebSitesDesigns\live\theallinfamily\` and remote `/public_html`.
   Upload the package contents (`assets`, `api`, `.htaccess`, `index.html`) without
   remote mirroring/deletion. Preserve the remote `api/.env`, private photos,
   sessions, release storage and unrelated sub-sites. Do not upload SQL, backups,
   CLI tools, `.git`, tests or development credentials. Check the queue is empty,
   failed transfers are zero, and uploaded bytes match the built package.
10. **Resume and smoke-test.** After all checks, rename the private maintenance
    marker to a dated completed-release filename (reversible) and confirm API
    health recovers. Clear the hosting frontend cache if it still serves the old
    entry point. Test live under Gary/Dad on desktop/mobile: profile preferences,
    weather details, holidays, 4–360-day scrolling, refresh, date-prefilled event
    creation, navigation and browser errors. Avoid generating family content;
    document any actual test changes and their cleanup. Keep restore data private;
    removing the scratch database requires explicit, reviewed cleanup.

### Evidence and recovery

- **Completed release result (22 October 2026):** migration016 was applied only
  after the successful scratch restore/rehearsal and original-data comparisons.
  FileZilla completed all 117 files with an empty queue and no failed transfers.
  SHA256 checks matched all 93 public client files, all 23 API package files and the
  root routing file. The remote production `.env` matched its pre-release backup
  byte-for-byte. The maintenance marker was renamed to
  `maintenance-completed-20261022-week-ahead.json`; health returned 200.
- Live checks passed under Gary/Dad: saved Scotland/refresh/regional holiday
  changes, restored/saved England and Wales and refreshed; Guildford forecast;
  360-day end boundary and 16 October 2027 event-form date (cancelled, no event
  created); mobile 382px document/382px scroll width, four-day minimum, weather
  dialog keyboard opening and Escape focus restoration. Admin displays Dad and
  the correct Scotland-to-England-and-Wales before/after values. The two genuine
  preference QA audit entries remain; no family test content was created.
- A final binary-safe comparison (`BINARY a.column <=> BINARY b.column`) also
  verified every preserved account/family-content value against scratch, with
  zero mismatches or extra/missing rows. This avoids case-insensitive collation
  hiding a changed string. Exclude legitimately changing usage/rate-limit/audit
  tables from this final post-smoke comparison; they were compared under
  maintenance before reopening. Retain the reviewed new-ledger timestamp exception.
- Anonymous checks passed: health/session 200, private Notes/admin/photo 401,
  environment/source 403 and secure HttpOnly/SameSite cookies. Browser warning/error
  logs were empty. Existing live Gary session was retained; no password was read,
  reset or changed. Local Home was restored and live Home left open.
- The restore-verification database is retained as a private recovery copy with
  no runtime-user assignment; do not reconnect the application to it. Archive
  downloads also remain in Downloads and in the protected local backup directory.
- Private local backups and comparison SQL live under
  `api/.local/production-releases/20261022-week-ahead/` (Git ignored).
- Non-secret release inventory, comparison results and screenshots live in the
  shared workspace `work/hosting/week-ahead-release-20261022/`.
- If backup, restore, migration or preservation checks fail, stop with maintenance
  active. Never blindly retry partial DDL or restore production. Inspect the saved
  state, then agree a forward repair or an explicitly authorised restore of the
  matching database, application, configuration and photos. Restoring a database
  can discard later writes; it is not a routine deployment step.
- The database backups and home archive contain sensitive information. Do not put
  their contents, credentials, cPanel session URLs or private keys in this README,
  chat, Git or the public website.

## Public Met Office weather warnings

Authenticated `GET /weather-warnings` reads the existing user's saved week preferences, closes the session before network work, and returns `{status, updatedAt, location, data}`. It performs no database writes and requires no migration or API key. A missing town (weather switched off) or non-UK location returns `unconfigured`.

`src/weather-warnings.php` reads the official UK RSS feed at `https://www.metoffice.gov.uk/public/data/PWSCache/WarningsRSS/Region/UK`. The shared public-feed transport enforces HTTPS/TLS verification, bounded connection/total timeouts and a 1 MiB response limit, without following redirects. XML rejects DOCTYPE/entities and external network resolution. Unknown notice formats fail visibly rather than silently reporting no warnings. Official URLs are validated and retained; enclosure URLs are never fetched.

One private shared national cache is refreshed after five minutes, with the existing lock/backoff and a maximum 15-minute stale window. The user's town is not sent to the Met Office. Filtering is applied per authenticated request, matching normalised town/county names to named warning areas and deduplicating warning IDs across regions. This is an area-name match, not a polygon/location guarantee; the client explains that exact coverage must be checked on the official map. Expired notices are removed, severity is ordered red/amber/yellow, and UTC RSS validity becomes UK calendar dates with an exclusive end boundary. The client displays local BST/GMT times beside the original summary and official advice link.

Deploy with the accompanying client change. No production upload, migration or data change was performed during implementation. PHP lint and a read-only real Guildford feed snapshot passed; local browser checks confirmed the matched Surrey wind warning and correct BST conversion. No automated regression suite was run in this request.

### Warning release review — 23 October 2026

Normalisation trims punctuation before the Greater London alias comparison. Added CLI-only `tests/weather-warnings-unit.php`: 14 checks cover UTC/UK day boundaries, New Year rollover, coverage names, empty feeds, malformed dates/intervals, untrusted URLs, unsafe XML and size limits. Existing public-feed (7) and FSA (34) isolated checks also pass. These checks perform no external requests or database writes. Changed PHP files pass syntax validation. Release both packages together; upload src dependencies before the API entry point. No schema or production configuration change is required.

## Personal TfL travel service (local implementation)

- `GET /profile/travel-preferences` and `POST /profile/travel-preferences` always address the authenticated user. A fixed19-line catalogue is returned with the saved choices/version; no row means off. Strict allowlisted unique IDs, CSRF, locking the owning user for first-save races and optimistic version409 handling protect changes. `travel_preferences_changed` activity uses user_profile with before/after readable line names; passwords/locations are never included.
- Additive migration017 creates user_travel_preferences with a user foreign key, lines JSON, version and updated_at. Local database was backed up under the Git-ignored .local/travel-release-20261023-173138 before applying it. No production migration, backup restore or upload occurred. Production must use the documented verified-backup/migration workflow, then release the accompanying API/client together; preserve existing family rows/configuration/photos.
- `GET /travel` authenticates, reads only the user's selection and closes the session before external work. Missing choices return unconfigured without contacting TfL. Shared provider calls request a fixed national TfL network, not an individual user's selections. No new API key/configuration is needed.
- Official endpoints: `https://api.tfl.gov.uk/Line/{fixed-ids}/Status?detail=false` and `/Line/{fixed-ids}/Status/{today}/to/{today+30days}?detail=false`. Current cacheTTL30seconds/max90seconds; plannedTTL1800/max3600. Common provider lock/5minute failure backoff, HTTPS verification,4second request timeout and1MiB body bounds remain. Detail=false avoids large route/stop expansions (the full detailed30day response exceeded the transport cap). Official developer guidance: https://api-portal.tfl.gov.uk/ . Current anonymous requests were verified successfully; future provider access changes must fail visibly.
- `travelNotices` requires a complete allowlisted response, normalises bounded text, retains official severity/status/description/advice, validates explicit timezone intervals and deduplicates notices. Only provider-classified PlannedWork with dates projects into future calendar days. Live undated notices are valid for today only. Expired notices are excluded at both boundaries; no clearance/recovery estimate is invented. Client independently enforces freshness and UK calendar dates.
- Verification:19 CLI checks in tests/travel-unit.php (no network/database writes),36 disposable API regressions including four new preference cases (saving/new session, ownership isolation/spoof resistance,CSRF,validation,version conflicts and admin audit),122 client tests, successful production build/formatting. Regression harness cleaned its test database/account/sessions/photos. Manual UI testing used Gary/Dad only; no family-content records were created. Local Gary preference tests were returned to off, with their normal local audit records retained.

TfL release verification (23 October 2026):36 isolated API regressions,30 authentication checks,56 local database-release safeguards and19 standalone TfL parser checks pass. Client companion has131 passing tests including timeline fields, UK clock changes, bulk preferences and planned-day tint. Production requires a fresh verified backup and scratch restore/rehearsal before applying017; do not import development data. Runtime deployment preserves environment and private photo/session/release storage. Defaults remain off until each live account chooses its lines.

## Migration017 production release - 23 October 2026

Runtime API034e7be and client8de6dc0 were pushed and deployed. Final131 client tests,36 API regressions,30 auth checks,56 local release-safeguard checks and19 TfL parser checks passed.

Exact cPanel procedure for this release:

1. Confirmed live had16 migration rows and no user_travel_preferences table. In File Manager at private/allin-family/database-releases, confirmed no active maintenance.json, then created it. Anonymous API health returned503/code maintenance before any backup was taken.
2. cPanel Tools > Backup: downloaded the database and Home Directory backups. Stored private copies in api/.local/production-releases/20261023-tfl/before.sql.gz and home-before.tar.gz (Git ignored, never included in upload packages). Database archive6843bytes, decompressed35750bytes; home archive302737375bytes. SHA256 recorded locally. SQL has no USE/CREATE DATABASE directive. Full home archive listing passed and includes API.env, current runtime, private original photos and derivatives.
3. Manage My Databases > Create Database: family_tfl_restore, yielding theallinfamilyco_family_tfl_restore. Did not assign the runtime app user. phpMyAdmin confirmed no tables before import. Imported before.sql.gz successfully (165queries).
4. In scratch SQL, ran verify-restore.sql: compared all15 source/restored tables by primary keys and binary NULL-safe equality for every original column, both directions. Zero changed/missing/extra rows. Columns, indexes, table constraints, engines and collations matched.
5. Rehearsed017 in scratch, then added its schema_migrations row only after DDL success. Confirmed17 migration rows, empty user_travel_preferences and its user foreign key.
6. Cleared phpMyAdmin's SQL editor with its Clear button before each query. Important: filling the CodeMirror input alone can append to an auto-filled SELECT. Also use fully qualified database/table names for every DDL, INSERT and verification query; do not rely solely on USE or the visible breadcrumb. One read-only preflight initially targeted information_schema due to phpMyAdmin context handling and was rejected; no live writes occurred until the explicit qualified table migration was reviewed.
7. Created only theallinfamilyco_family_app.user_travel_preferences with its foreign key explicitly referencing theallinfamilyco_family_app.users, then inserted017_travel_preferences.sql into the live migration ledger. No existing family table was altered. Verified17 migrations and an empty new table. Compared all preserved rows again and full schema against rehearsed scratch, allowing only017's expected differing applied_at timestamp. All checks passed.
8. Built client/API separately. FileZilla existing site destination /public_html: uploaded24 API src files before API index.php; then106 client assets and index.html last. Existing API.env, private storage, root routing and other sub-sites retained. Successful transfer counter1213->1345 (132files), queue empty and no failures observed. Private server API verification ZIP was created outside public_html and downloaded; all27 package files match, including unchanged example/routing files, and production.env matches the pre-release archive byte-for-byte. All107 public client files match SHA256 over HTTPS.
9. Renamed the private maintenance marker to maintenance-completed-20261023-tfl.json. Health returned200. Anonymous travel/preferences401, API.env/source403. No schema permissions were granted to the runtime app user.
10. Live Gary/Dad desktop390px phone QA: saved/refreshed temporary Central/Circle/Northern lines; live and future Circle notices; actual descriptions/advice fallbacks; official timeline colours; BST/GMT dates; one-open accordion, Enter/Space, Escape/focus return; admin actor/before/after. Restored original live off preference and verified refresh. Two normal audit records remain; no family content created. Twelve stable account/content/history/preference tables were compared against scratch after QA, with zero changes/missing/extra rows (dynamic usage/rate/audit tables excluded only after reopening). Local nineteen-line preferences were not copied to live.

Private recovery directory also contains before.sql, verify-restore.sql, verify-after.sql, verify-family-after-smoke.sql, runtime-api-after.zip, downloaded API and original environment extraction. Keep these private/Git ignored. Scratch restore remains retained with no runtime-user assignment; do not delete it without an explicit cleanup decision. Non-secret evidence and comparison JSON: shared workspace work/hosting/tfl-release-20261023. Live screenshot evidence includes desktop/mobile timelines and future works. Restore of live data is not a routine rollback; agree recovery explicitly before replacing saved family rows.
