# The Allin Family - API

Small PHP API for theallinfamily.co.uk, intended for Trilogy hosting alongside
the separate React client. No Composer dependencies are required for this starter.

## Run locally

From this repository in PowerShell:

```powershell
# First checkout only. Keep an existing .env when updating the repository.
Copy-Item .env.example .env
./serve.ps1
```

The server listens on `http://127.0.0.1:8000`. The script uses PHP from PATH,
or falls back to `C:\xampp\php\php.exe`. You can provide another executable:

```powershell
./serve.ps1 -PhpPath 'C:\path\to\php.exe'
```

Alternatively, run `php -S 127.0.0.1:8000 router.php` from this folder.
Keep the terminal running; Ctrl+C stops the server. It is for local development.
The code works with the installed PHP 8.0; use a currently supported PHP release
before implementing real accounts or deploying. PHP's `pdo_mysql` extension will
be required for database features.

## Available endpoint

`GET /health` returns:

```json
{"status":"ok","service":"The Allin Family API"}
```

The React development server forwards `/api/health` to this endpoint. HEAD is
also supported. Unknown routes return JSON with HTTP 404; other methods on
`/health` return HTTP 405. This is a public liveness check: it does not prove
that MySQL is configured or reachable.

## Environment and database

`.env` belongs only to this API repository and is ignored by Git. `.env.example`
is a safe template. Values use PHP INI syntax: keep strings in double quotes,
use semicolon comments, and do not rely on dotenv variable expansion. Operating
system environment variables override the file.

MySQL host, port, database and credentials are configured here. The included
`connectDatabase()` helper opens a PDO connection only when called by a future
feature. No database is created, no credentials are assumed, and no queries are
executed by the starter. No database credentials go into the client environment.

## Hosting boundaries

`index.php` is the API entry point; `src/` contains server code. The local router
passes every request through that entry point and never serves repository files.
The Apache `.htaccess` blocks dotfiles, Git metadata and source directories,
then routes requests to PHP. Apache 2.4, mod_rewrite and permitted `.htaccess`
overrides are needed; verify these protections on the actual Trilogy account
before deploying. When hosted under `/api`, the health URL is `/api/health`.

No login, authorisation, private family data, uploads or calendar features are
implemented yet. Add server-side session and access checks before any private
feature. Deploy only the runtime files; do not upload Git metadata, local scripts
or local environment files to the live website.

Repository: https://github.com/MeGallin/The-Allin-Family-API

