# Feature advertising

## Automatic local generation and live rotation

The human now authorises local ongoing generation and **live** Home publication changes; this supersedes the earlier frozen-publication page policy. There is still one cadence, one current and one candidate, not an archive or separate display-frequency subsystem. Existing Gary Administration controls interval/daily timing, preparation lead, enabled services/weights, allowed layouts, manual approval and budgets. The initial requested cadence is3minutes with all15 implemented services/all3 layouts; parent configures/resumes it only after local verification. Manual approval must be off for automatic promotion, and valid nonzero currency/call/text-token/image caps are required. Disabling or pausing still stops paid work.

Feature selection uses bounded durable smooth weighted round-robin credits, rather than random chance. Equal weights cover all eligible services evenly; positive weights influence frequency. Immediate repeat is avoided when another feature is available, so an otherwise dominant >50% share is capped at the feasible half-share. Disabled/expired services do not participate. The metadata contains numeric feature credits only, never old copy/images. Adding it preserves current content, usage, config revision and pause controls. Each job cycles to the next administrator-allowed layout from the current composition; the LLM still invents its hook, creative idea and artwork for that chosen layout. A single allowed layout remains valid.

A due approved candidate publishes in its own committed transaction and the tick returns. The next tick may prepare another candidate; exhausted next-cycle budgets cannot roll back an already-paid publication. Before its first text request a cycle checks that all configured text/review/image reservations fit; image-cap exhaustion therefore does not buy unwanted text. Phase reservations/grants remain independently atomic and actual usage is never reset by starting/stopping the worker.

The local foreground worker is **not** an OS service or installed task. API cwd:

```powershell
C:/xampp/php/php.exe bin/advertising-worker.php run
C:/xampp/php/php.exe bin/advertising-worker.php status
C:/xampp/php/php.exe bin/advertising-worker.php stop
```

It requires APP_ENVlocal and exact Node loopback127.0.0.2:8000, checks normal due work every5seconds, holds a private singleton worker.lock and writes bounded PID/heartbeat/outcome metadata with no keys or bodies. Status reports whether the lock is held, rather than trusting a reused PID. Stop requests are owner-token bound and take effect between sequential jobs; an active bounded generation may finish before clean exit. The worker itself introduces no concurrent fire-and-forget jobs, task installation, provider fake flag or usage reset. The optional separate Task Scheduler supervisor described below keeps this foreground worker and its dependencies alive outside a Codex command session. Existing PHP8000, narrow callback8001 and Node listener are preserved. Parent owns actual worker launch/configuration/restarts. For an immediate paid-work stop use admin Pause; worker stop lets current work finish safely. A forced process exit leaves normal durable lease/reservation recovery in force.

GET `/advertising` includes monotonically increasing `version` with authoritative current paired ID. The client polls only while visible (and on focus), follows new publication rather than freezing forever, and stages/decode-loads new artwork before swapping copy+image together. Auth changes, disable, invalid references and expiry still suppress old content; stale/out-of-order responses are ignored. No browser retrieval generates. Retired Blob URLs are released after a safe swap/auth cleanup. If a missed image has already been removed, the client refetches latest safely instead of mixing artwork/copy. Production cron/hosting remains a separate unauthorised release matter; this task starts local work only.

Synthetic checks: `php tests/advertising-rotation-unit.php` covers15-feature fair coverage, weights, eligibility, layout cycle, publication under exhausted next budget, additive metadata and complete-cycle budget refusal. `node --test tests/advertising-worker.test.mjs` starts/stops only a disabled disposable local worker and verifies singleton/status/guards, with no provider request.

## Model-selected campaign composition

New image-enabled generation selects both its creative idea and `payload.composition` from administrator-approved `image.layouts`: poster, split or image-led. Default allowed set is all three. `image.maxHeadlineWords` defaults5 and permits2–8; the default creative aim is3–5 words, with an explicit maximum2 requesting exactly2 words. PHP and Node independently enforce the finite layout and word/character bounds. Hyphen/apostrophe-connected words count as one. Neither model can provide HTML, CSS, image URLs or arbitrary layout code.

The main text model art-directs one memorable visual idea, a short live HTML hook and the selected layout together. The retained message is for claims/admin/accessibility, rather than an explanatory visible feature paragraph. Image prompts target the complete nearly-square247×231 canvas with no baked text: poster protects its upper headline zone and bottom-left CTA patch; split protects left53% type/CTA with right47% hero; image-led keeps its dominant hero upper-middle/right, a lower40% headline plate and a small upper-left CTA patch. The renderer owns reviewed CSS for those three variants. A short service identifier and fixed CTA remain client-owned.

Explicit schema3 upgrade adds only these image configuration defaults to applied/draft state, preserving prior composition-less current payloads, private asset IDs, counters, pause settings and revisions. Legacy images use the client poster fallback without rewriting their stored bodies. Changing future layout choices does not create an archive or regenerate on GET. Provider/grants/auth/upload/budget/security bounds remain unchanged.

The coordination workspace's four former `work/advertising-sample/*.php` sample tools are **retired**: every invocation fails before loading application configuration or state. Historical sample runs used bounded manual Sol/image generation and config-only ownership markers; those reports are past QA evidence, not current operating instructions. Use Gary's existing advertising admin and the normal local worker for preparation, approval, publication and settings. Synthetic layout/word-limit/migration tests use no provider calls.

## Dynamic image capability — 17 November 2026

Images are part of the running service, not a static feature registry or a Codex image-generation skill. When enabled, the main text model supplies a bounded original visual brief alongside short campaign copy. After copy validation/optional text review, Node consumes a separately reserved durable **image grant**, calls the fixed OpenAI Images API, validates a single JPEG response and signs a fenced private upload. Candidate commit requires its exact leased image ID; promotion swaps copy and image as one pair. Existing text-only current adverts survive the schema1→2 default upgrade unchanged, with image generation disabled initially.

Image controls: gpt-image-2.6-flare or gpt-image-2.6-sunburst; low/medium/high quality and independent documented complexity;1024×1024 or1536×1024;15–120s timeout; bounded style/creative brief; per-image estimated USD reservation (default2USD). Requests use n1, JPEG, opaque background, no image URLs, reasoning effort, response_format, compression, input_fidelity or store parameter. A response model field is checked only if present: the Images response does not require it. Model listing does not prove inference entitlement. No arbitrary image provider/model/endpoint is accepted.

Images API has **no max_output_tokens parameter or verified hard output ceiling**. The configured per-image reservation is a conservative estimate, not an absolute provider-invoice guarantee. Calls and image counts have atomic UTC day/month caps. Existing hard text-token ceilings remain separate; observed image tokens are reported independently. Dated official image rates initially checked17November2026: text input5USD/M,image input8USD/M,image output30USD/M. Known valid usage settles the estimate; an actual cost exceeding it is recorded honestly and pauses the service. Unknown/malformed usage keeps the estimate and pauses; no silent retry. Default image count caps0 block work.

Private `assets/<opaque48hex>.jpg` files sit beneath ADVERTISING_DIRECTORY, outside public roots. JSON contains descriptors/IDs only, never base64 or image bytes. Only the exact `/advertising/machine/image` signed path permits up to6MiB JSON; other machine paths remain32KiB. Decoded JPEG max4MiB, approved dimensions/MIME/signature; no SVG, user path or remote URL. Upload is bound to begun/settled image grant, job fence and config revision. Files are staged under the stable lock, referenced by committed metadata, then unreferenced files are swept **after** commit. Interrupted writes cannot delete still-referenced current/candidate images. Temporary orphan files receive5minute grace; completed unreferenced images are removed on the next successful transaction. External host backup/filesystem retention limitations still apply.

Authenticated same-origin GET `/advertising/image?id=<opaque ID>` permits valid current to members and candidate only to admins, returning private/no-store/nosniff JPEG. Client uses session-memory Blob URLs, retains the displayed pair until a new authoritative pair is ready and revokes on safe replacement/authentication changes; no localStorage or model URL is used. New image fetch racing deletion safely refetches latest/falls back. Turning future image generation off does not hide an already published pair. Manual approval remains the initial default visual/semantic safeguard; automatic mode is an explicit admin choice and raster validation does not establish that artwork is accurate or free of unwanted text.

The full deadline is derived from configured text/review/image calls and each8s bridge handshake plus margin. Maximum allowed combination: PHP312s outer wait, lease332s, Node360s response inactivity, browser375s prepare timeout. The sample configuration Sol45s/reviewoff/image120s is PHP243s/lease263s. Header/body upload bounds remain10/15s. Real host/proxy/FastCGI limits must support the selected workflow or shorter settings; free Render cold starts still can yield an unknown paused result.

Synthetic image checks: `php -d extension=gd tests/advertising-images-unit.php`; standard `node --test tests/advertising.test.mjs` includes current/member vs candidate/admin JPEG auth; Node `npm test` mocks both real OpenAI adapters and exercises signed upload through actual isolated PHP processes and paired A/B/C replacement without a provider call. GD is used only to generate disposable test JPEGs; runtime validation uses PHP's built-in image header functions, not a new dependency.

Official references: [Images generation API](https://developers.openai.com/api/reference/resources/images/methods/generate), [GPT Image2.6 guide](https://developers.openai.com/api/docs/guides/image-generation).

The existing administrator login controls `/admin?view=advertising`; no second account is introduced. The browser uses the normal same-origin PHP session/role/CSRF gateway. PHP owns durable state, catalogue/configuration, scheduling, budgets and publication. The sibling Node24/Express service is a stateless signed generation/review engine. No remote database, LangChain/LangGraph, paid scheduler or production database migration is needed.

## Storage and retention

Production requires an absolute `ADVERTISING_DIRECTORY` outside public/API document roots. Local default: `%LOCALAPPDATA%/TheAllinFamily/Advertising`; missing LOCALAPPDATA fails closed. The known broad XAMPP htdocs root is also rejected, not merely PHP's narrower development document root. Requested permissions are0700/0600; Windows host ACL policy remains operator-owned.

One private `state.json` contains applied config, saved draft, CAS/revisions, **one current and at most one candidate**, one fenced lease/call grants, UTC day/month counters, bounded replay nonces and80 metadata audit entries. A separate stable `state.lock` survives data-file replacement. Transactions acquire that lock within2s; CAS/claim/reservation/publication are atomic. Reads/writes are capped512KiB. Same-directory temporary writes flush/fsync where available, then rename atomically. Orphan temporary payloads are removed under the lock. Corrupt/oversize state fails closed. Neither file nor PHP session locks are held during network work.

Promotion replaces the old current body and clears the candidate. Reject, disable and reference invalidation remove the relevant body slots. There is no advert archive, body history/receipt, body audit or automatic application backup. Config export excludes bodies, keys, grants and nonces. **This is not forensic erasure:** external host backups, filesystem snapshots and storage recovery may retain bytes independently. Existing database/photo backup jobs do not automatically include this new directory; define host retention separately.

## Configuration and lifecycle

Defaults are disabled, manual approval on, optional second-model review off, every currency/call/token cap zero. Saving a config draft changes no applied settings. Apply checks state/draft/config versions, increments applied revision and fences stale work/candidates. Matching feature facts/availability/expiry/exclusions changes invalidate affected current copy; selection weight alone does not. Audit records trusted admin IDs without names, keys or content.

The15 implemented subjects are Jobs, Shopping, Polls, Dinner, Where, Nonograms, Sudoku, Focus, Daily Word, Calendar, Memories, private Notes, Appearance, Days Ahead and TfL. Five proposed widget concepts remain excluded. The prompt contains approved public feature metadata only, never family DB records or private content. CTA identities and routes are fixed in code.

Prepare and CLI tick share atomic claims. Initial A is prepared, validated and manually approved by default. Eligible publication promotes A. B prepares while valid A remains visible. Once B is approved/due, one atomic swap replaces A, deletes its body and schedules the next cycle; C may then prepare. Failed/rejected generation preserves current. No catch-up queue or ad archive exists.

Formats are fresh creative headline/message, checklist, process and illustrative comparison. Scenario copy is explicitly hypothetical in the UI. Server checks fixed subject/CTA/format IDs, exact schema, approved fact references, bounded plain text, unapproved numeric/price literals and similarity. **These checks do not prove semantic accuracy.** Manual approval is the initial safeguard; optional model review is also fallible. Automatic mode permits validated candidates to publish without human approval.

Interval seconds (UI may show minutes) start at actual promotion. Daily HH:mm uses an IANA timezone; missing DST times normalise forward and repeated times publish once per local day. Windows can cross midnight. Idle policy continues or pauses automatic new work after a chosen Home inactivity threshold, retaining valid current copy. A visitor updates activity; later cron does due work. Preparation lead is relative to eligibility. Cron frequency limits precision; missed runs never cause a storm.

## Costs and execution

Budgets are integer microdollars internally and USD decimals in the UI, with independent UTC day/month currency/call/token caps. Zero blocks paid work. Each generation, review and retry reserves before calling. Requests are bounded conservatively to24K input tokens using UTF-8 body bytes plus framing allowance and capped output. Input cost reserves up to2.5×configured Standard pricing for applicable cache writes. Known usage may reduce the reservation, using conservative ceiling rates; this is an estimate, not a provider invoice. Estimates cannot fall below the verified baseline; stale/future checked dates block work and validity is at most30days.

Known rejected attempts can retry on later ticks after backoff, at most2 retries. Every retry/review reserves separately. Ambiguous failures retain reservations and pause. Cancelling/timing out a job fences it, and a block through its full deadline prevents overlapping a possibly still-running provider call even after Resume. Node restarts cannot reuse an already begun durable grant. No provider response bodies, keys or private prompts are logged.

Configured text/review/image phases determine the complete PHP deadline; maximum312s outer wait/332s lease, Node response inactivity360s and browser Prepare375s. Header/body upload deadlines remain10/15s. Apache/FastCGI/reverse-proxy timeouts must accommodate configured work or use shorter call timeouts. Free Render sleep/cold starts may consume this window and produce a conservative paused outcome; no unsupported fire-and-forget job acknowledgement exists.

## Machine auth and CORS

Matching environment-only `ADVERTISING_SIGNING_SECRET` signs timestamp, nonce, method, canonical mounted-relative path and SHA256 of exact raw body, newline separated. HMAC-SHA256 comparisons are constant-time; skew is±120s. PHP persists bounded nonces through signed timestamp+skew and fences one-use call grants. Node also keeps bounded in-process nonces; every provider request must first claim its durable PHP grant, protecting restart replay. Missing configuration fails closed.

PHP can call only configured Render HTTPS hostname/fixed routes (exact development `http://127.0.0.2:8000`). Node can call only family HTTPS `/api/advertising/machine` or exact local `http://127.0.0.1:8001/advertising/machine`. Redirects, arbitrary administrator URLs, external fetch tools and remote DB exposure are excluded.

Browser traffic stays same-origin: local Vite5173 proxies `/api` to PHP127.0.0.1:8000; production uses canonical family HTTPS. Advertising browser routes reject explicit mismatched/empty/null Origin plus normal CSRF checks. Machine routes reject browser Origins and OPTIONS/preflight cannot start work. There is no wildcard/reflected credentialed CORS or browser cookie use on Render. Origin is not authentication; machine HMAC is mandatory independently. A future direct embed needs a separate public read API with explicit allowed origins.

Home revalidates authoritative publication and safety on mounting, focus/visibility and periodic visible polling, replacing only a ready matching pair. Failed safety reads suppress it; expiry also hides locally. Browser reads/ordinary writes are bounded15s using the existing request-timeout helper; manual Prepare has a finite375s browser deadline beyond PHP's maximum312s wait. An uncertain result preserves the admin draft and requires status reload before retrying.

## Local setup and checks

For reliable overnight Windows operation, use the reviewed [Task Scheduler supervisor guide](../bin/local-advertising/README.md). Codex command sessions are temporary execution hosts; they are not an overnight service manager. The supervisor is local-only, preserves advert settings/usage, uses a stable pinned Node executable, and requires Gary logged in and AC power for idle-sleep prevention. Installation and real scheduled verification are explicit parent operations.

Existing local PHP is8.0; feature code remains compatible without upgrading unrelated runtimes. Production uses newer supported PHP. Existing PHP8000 and family data remain intact.

```powershell
# API cwd: LOCAL ONLY callback listener, always loopback
C:/xampp/php/php.exe -S 127.0.0.1:8001 bin/advertising-bridge-router.php
# Adverts cwd: Node24; npm start does not automatically load .env
node --env-file=.env src/index.js
```

Node environment: local HOST127.0.0.2/PORT8000, provider key, matching signing secret, `ADVERTISING_BRIDGE_URL=http://127.0.0.1:8001/advertising/machine`. PHP environment: matching signing secret and optional safe private directory override. The separate callback server avoids the single-worker PHP development-server deadlock. It serves no files and opens no DB/session. Implementation appended new signing settings without reading/copying/outputting the existing local provider key.

```sh
# API cwd: no DB/provider calls
php tests/advertising-unit.php
# API cwd: disposable existing test DB/accounts/sessions only
node --test tests/advertising.test.mjs
# Adverts cwd: synthetic providers and real ephemeral signed PHP bridge
npm test
# Client cwd
npx vitest run src/features/advertising src/components/advertising src/styles/no-inline-styles.test.ts --maxWorkers=1
npx tsc --noEmit
```

`tests/advertising-local-preview.php` is retired. All former modes now fail before loading configuration or state; use Gary's normal advertising admin and worker. Earlier visual QA reports describe historical fixture runs, not current instructions. Automated coverage uses disposable synthetic state only. Test directories remain denied by routing/.htaccess and excluded from runtime packaging; no fake provider/browser API switch is shipped.

## Deployment prerequisites (not performed)

Separately authorise reviewed PHP/client/Node releases. Configure matching signing secrets, approved fixed URLs, production private directory and Node provider key through host environment. Never upload local `.env` or private state. Render remains Node24/Free/Frankfurt with auto-deploy off. No production schema migration is required. The following cron installation is a **separate future approval**, not performed by local supervision or a normal API upload.

### Separate private cron runner

The approved API/full-stack package copies `src/` and HTTP entrypoints; it deliberately excludes `bin/`. Uploading that package does not install a cron command. Do not copy a tick script alone or upload all local Windows tooling. An explicitly approved existing-host installation must preserve this relative tree outside `public_html` (example names only):

```text
/home/ACCOUNT/private/allin-advertising-runner/
  bin/advertising-tick.php
  src/bootstrap.php
  src/advertising.php
  src/advertising/*.php    # complete advertising module set from the same API release
  .env                    # optional host-created private settings, never the local file
/home/ACCOUNT/private/allin-advertising-state/   # existing shared durable state/assets
```

The tick requires `../src/bootstrap.php` and `../src/advertising.php` relative to its `bin/` directory, and loads `.env` from that runner root. The loader requires every advertising module; keep all of them byte-matched to the approved deployed HTTP API release. Install/update this private source set alongside each API release under its separate approval, verify file hashes/release identity, and prevent public/private source drift. This does not change the single local deployment-package destination or authorise an upload.

Cron often lacks the web server's environment. Configure host-side environment injection or create the private runner `.env` with the **same effective production advertising settings as HTTP**: `APP_ENV=production`, exact approved Render `ADVERTISING_NODE_URL`, matching environment-only signing secret, and the **same canonical `ADVERTISING_DIRECTORY`**. Environment variables override file values in this loader; compare effective configuration privately without printing secrets. Never leave the CLI on local defaults or point it at a second empty store. State/assets stay in their existing private sibling directory, not inside the runner or web root; both HTTP and cron users need appropriate access to the same stable lock/state/assets. The private runner needs no OpenAI key or remote DB connection; provider credentials stay on Node. No keys belong in cron arguments, logs or upload payloads.

Before scheduling, verify supported PHP, required HTTPS/network support, private permissions, canonical shared-store identity, source hashes and hosting execution limits covering the configured workflow deadline. Keep advertising paused through Gary's production admin for a separately approved one-shot CLI verification: the tick should report `started:false`, with no provider call and the same admin current/config/usage visible. Confirm authenticated engine status separately; credential presence is not provider entitlement. Only after explicit spend/enable approval unpause and verify a real due cycle, actual publication and Home rendering. Do not run a first tick against an enabled overdue configuration as a harmless check.

Example cron after that approved installation and verification (host must supply its actual PHP binary/path):

```cron
* * * * * /path/to/php /home/ACCOUNT/private/allin-advertising-runner/bin/advertising-tick.php
```

Preserve the existing backup job; use no paid Render cron or Windows supervisor on production. Stable leases serialize overlapping ticks, but confirm host timeouts/outbound limits and cron output handling. Keep output limited to existing metadata/errors, with private bounded retention; define private-state backup/retention policy separately. Cron deployment, configuration, production enablement/spend and scheduled/live verification remain distinct approval gates.
