# ERONYX staging deployment (Hostinger)

Package and operator checklist for a **preview** environment. Contains **no secrets**.

This file does **not** mean staging is live. Codex has **no authenticated Hostinger access** from the local workspace (no hPanel session, no Hostinger API token, no SSH host configured, no staging hostname in repo). Deploy requires **manual Hostinger intervention**.

Base procedure: `docs/PRODUCTION_DEPLOYMENT.md`. Staging differences are listed here.

Do **not** treat this host as public production. Do **not** connect payments, payouts, CCBill/Segpay, GA4/GTM, Search Console, or a real KYC provider.

## Status of this package

| Item | State |
|---|---|
| Local git | `main` @ `2166dbf` = `origin/main` |
| Hostinger access from this environment | **No** |
| Staging hostname | **Unknown — set on the server only** |
| App code changes for STAGING-1 | **None required** |
| Production domain in repo | **Unchanged** |

Use placeholders below. Replace `STAGING_HOST` with the real HTTPS host after you create it (subdomain or temporary Hostinger URL). Do not invent `eronyx.es` or any other live domain in Git.

## Staging architecture

```
Preferred (custom document root):

  ~/eronyx/                 ← git clone of this repo
    app/ config/ database/ storage/ vendor/ .env
    public/                 ← document root
      index.php css/ js/ .htaccess

Hostinger public_html fallback (do not dump the whole repo into public_html):

  ~/eronyx/                 ← app root (outside the web root)
  ~/domains/STAGING_HOST/public_html/   ← ONLY the contents of public/
```

`public/index.php` loads `dirname(__DIR__) . '/vendor/autoload.php'`. That works when the document root **is** `public/` (parent = app root).

If Hostinger forces `public_html` one level above a different folder, **do not commit** a path change. On the server only, either:

1. Point the vhost / website document root at `…/eronyx/public` (preferred), or
2. Symlink `public_html` → `eronyx/public` if the plan allows it, or
3. Copy `public/*` into `public_html` and adjust **only the server copy** of `index.php` so `require` points at `~/eronyx/vendor/autoload.php`.

Never place `.env`, `app/`, `storage/`, `vendor/`, or `.git/` inside a web-reachable directory without the existing deny rules as a last-resort safety net.

## Hostname / HTTPS

Manual in hPanel:

1. Create a **separate** website or subdomain for staging (not the future public production vhost if you can avoid it).
2. Issue the Hostinger SSL certificate for that host.
3. Force HTTPS in hPanel (HTTP → HTTPS redirect).
4. Set `APP_URL=https://STAGING_HOST` with **no** trailing slash issues: the app trims trailing `/`.

Do not enable HSTS preload. Staging **does not** send HSTS: the app only adds `Strict-Transport-Security` when `APP_ENV=production` **and** the request is HTTPS (SECURITY-1). That is expected.

## PHP and extensions

hPanel → Advanced → PHP configuration (or SSH `php -v` / `php -m`):

- PHP **8.2 or newer** (do not lower)
- Enable: `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `json`, `session`, `ctype`, `filter`, `hash`
- `upload_max_filesize` and `post_max_size` ≥ **5M** (app image limit wins)

Record the exact `php -v` on the server in the validation log (not in Git if it includes host paths you do not want public).

## Composer

From the **app root** (never `composer update`):

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

If Hostinger SSH has no Composer: use hPanel “PHP Composer” / “Run composer” on that same directory, or run Composer locally for Linux and rsync `vendor/` (avoid uploading a Windows `vendor/` if you can install on the server).

Lock file (`composer.lock`) governs versions.

## Git deploy method

1. In Hostinger Git (or SSH): clone `https://github.com/lux-digital-studio/eronyx-club.git`
2. Checkout `main` at `2166dbf` (or current `origin/main` after a reviewed pull)
3. Do **not** deploy `_private_prelaunch/` (it is local-only and must stay untracked)

Auth to GitHub is configured in hPanel (deploy key or GitHub connection). Do not paste PATs into this file or into chat.

## `.env` on the server only

Copy `.env.example` → `.env` **on the server**. Never commit `.env`.

```
APP_NAME=ERONYX
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://STAGING_HOST

DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_NAME=eronyx_session
SECURE_COOKIES=false
COOKIE_SAMESITE=Lax
TRUSTED_PROXIES=

MFA_ENCRYPTION_KEY=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=ERONYX
MAIL_TIMEOUT=10

VERIFICATION_MODE=manual_review
VERIFICATION_PROVIDER=
VERIFICATION_SESSION_TTL=86400
VERIFICATION_REQUIRE_FOR_CREATOR=true
```

Keep `LEGAL_*` as in `.env.example` for staging (draft / empty identity → preflight **WARN**, not FAIL).

`TRUSTED_PROXIES`: leave empty unless PHP sees HTTP while the browser uses HTTPS. Then set **only** the observed proxy IP (`REMOTE_ADDR` of the TLS terminator). Do not set a wildcard and do not trust `X-Forwarded-*` globally.

### MFA key

Generate **once** on a trusted machine, not in Git:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use a **staging-only** key. Do not reuse the future production key unless you explicitly decide to. Back it up offline. Do not print it in tickets or this doc.

## Database

Create a **dedicated** MySQL database and user in hPanel (not the local XAMPP DB, not a future production DB). Prefer a non-`root` user with access only to that schema.

```bash
php database/migrate.php
php database/migrate.php
php database/seed.php
```

Second migrate must print nothing new (idempotent). Seeds are only:

- `2026_08_14_000001_seed_roles.php`
- `2026_08_14_000002_seed_marketplace_categories.php`

Expect `roles=4`, `categories=5`. No test fixtures.

## Storage permissions

Writable by the PHP user, **not** `777`:

- `storage/logs`
- `storage/cache`
- `storage/private/media`

Typical: directories `0755`, files `0644`, correct owner. Record `ls -ld` of those paths after deploy.

## Preflight on the server

```bash
php scripts/preflight.php --staging
```

**Stop public validation** if any `FAIL`. Legal placeholders are **WARN** in `--staging`. `--production` is not used for this host.

Expected FAIL examples if `.env` is wrong: `MAIL_MAILER=array`, missing MFA key, `APP_DEBUG` true, empty DB name/user.

Note: `--staging` **WARNs** (does not FAIL) on `http://` `APP_URL`. Operators must still use HTTPS.

## Sessions and cookies (code behavior)

Always: `HttpOnly`, `SameSite=Lax`, host-only (`domain` empty), `session.use_strict_mode=1`.

`Secure` is set only when `APP_ENV=production` **and** the request is HTTPS (`Session::cookieSecure`). Staging therefore keeps HttpOnly/Lax but **does not** set `Secure`. That is existing SECURITY-1 policy, not a silent hotfix. Documented as a staging warning. Changing it requires a separate FIX phase.

If Hostinger terminates TLS and PHP sees HTTP, `isHttps()` is false unless `TRUSTED_PROXIES` contains the proxy. Canonical/OG/emails still use `APP_URL`.

## Indexation (mandatory)

With `APP_ENV=staging` (not `production`):

- HTML `meta robots`: `noindex, nofollow`
- `X-Robots-Tag: noindex, nofollow`
- `/robots.txt`: `User-agent: *` + `Disallow: /`
- `/sitemap.xml` may return 200; it must not be treated as an indexation signal. Canonicals use `APP_URL` (staging host), not the `Host` header.

Do not submit this host to Search Console.

## Fail-closed features

| Feature | Staging |
|---|---|
| `POST /account/orders/{id}/test-pay` | 404 (`isLocal()` is only `APP_ENV=local`) |
| Dev password-reset URL | hidden (`local`/`test` only) |
| `VERIFICATION_PROVIDER=test` | `provider_not_configured` |
| `MAIL_MAILER=array` | preflight FAIL |

Age verification: `manual_review` is allowed for staging with a **compliance warning**. It is not production KYC.

## Operator validation checklist (after the site responds)

Run from your machine against `https://STAGING_HOST` (no secrets in saved output):

```bash
curl -sI "https://STAGING_HOST/"
curl -sI "http://STAGING_HOST/"          # expect redirect to HTTPS
curl -sI "https://STAGING_HOST/.env"     # 403/404
curl -sI "https://STAGING_HOST/storage/" # 403/404
curl -sI "https://STAGING_HOST/composer.json"
curl -s  "https://STAGING_HOST/robots.txt"
curl -sI "https://STAGING_HOST/css/app.css"
curl -sI "https://STAGING_HOST/js/app.js"
curl -sI -X TRACE "https://STAGING_HOST/"
```

Confirm by hand:

1. Register / login / logout on a **controlled test mailbox** only.
2. Email verification and password reset via real SMTP (no dev URL on the page).
3. MFA setup QR is a data URI (no external QR fetch). Do not store raw TOTP/recovery codes in tickets.
4. `POST /account/orders/{id}/test-pay` → 404.
5. Guest `/media/{id}` for private media → 403/404; authorized → `Cache-Control: private, no-store`.
6. Public media (if you upload a throwaway image): `200`, public cache, ETag, `304` on `If-None-Match`.
7. Range on a throwaway private video if you upload one: `206` / invalid `416`. Delete the file after.
8. `/account`, `/admin` (guest → login), buyer → `/admin` 403.
9. Sensitive routes: `Cache-Control` contains `no-store`.
10. 404/403 pages: no stack, no `SQLSTATE`, no disk paths.
11. `Host: evil.example` must not appear in canonical, OG, or sitemap URLs.

Delete all staging fixtures after QA unless you get **explicit** approval to keep an admin. Target empty app tables (users/listings/orders/… = 0) with `roles=4`, `categories=5`.

Do **not** create a permanent admin in this phase without approval.

## SMTP

Staging must use `MAIL_MAILER=smtp` with TLS peer verification (already in `MailService`). Send only to a mailbox you control. If SMTP is not ready, preflight `--staging` FAILs — do not open the host for QA.

## Security headers to expect

Always (HTML app responses): CSP (`script-src 'self'`, `img-src 'self' data:`), `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy.

Staging extra: `X-Robots-Tag: noindex, nofollow`.

HSTS: **absent** on staging (production-only).

## Performance (measure, do not invent)

`curl -sI` on `/css/app.css` and `/js/app.js`: status 200, `Cache-Control`, optional `Content-Encoding: gzip`/`deflate`. Listing cards should keep `?v=` on CSS/JS from the app. Record byte sizes from response headers if present. Do not invent Lighthouse scores.

## Backup procedure (staging)

Even if the DB is empty after seed-only:

- hPanel → Databases → export, **or** `mysqldump` over SSH
- Copy `storage/private` if any uploads exist
- Store archives **outside** `public_html`
- No secrets in filenames

## Rollback

1. Keep the previous `git` commit handy (`2166dbf` is the STAGING-1 baseline; previous app commit is `17f1adc`).
2. Restore files: `git fetch` + checkout the known-good commit (or Hostinger Git revert).
3. Restore `.env` **unchanged** if the MFA key is the same (do not rotate to “fix” a bad deploy).
4. Restore DB dump + `storage/private` if schema/data changed.
5. `composer install --no-dev --prefer-dist --optimize-autoloader` from the lockfile of that commit.
6. `php scripts/preflight.php --staging`

Do not run rollback unless the deploy is broken.

## Known warnings (not silent fixes)

- Legal draft / `[PENDIENTE DE REVISIÓN LEGAL: …]` — WARN on `--staging`, FAIL on `--production`.
- `manual_review` age verification — not production compliance.
- Session `Secure` flag is production-only (SECURITY-1). Staging HTTPS cookies stay HttpOnly + Lax.
- Hostinger may answer HTTP `TRACE` at the protocol layer (`message/http`) before `.htaccess` rewrite; the PHP app must not run.
- `SECURE_COOKIES` in `.env` is loaded but the Secure flag follows `APP_ENV=production` + HTTPS.
- No default OG image (visual gap).
- Shared-host PHP workers may drop file sessions; users re-login.

## Launch / next-phase blockers (unchanged)

Real SMTP still required before any public launch. Payments, payouts, real KYC, staff MFA enforcement, production DNS/TLS, and analytics are **out of scope**.

## Manual actions required (Hostinger operator)

1. Create staging website + DNS + SSL.
2. Set document root (or public_html mapping) as above.
3. Clone `main` @ `2166dbf` (or current reviewed `origin/main`).
4. PHP 8.2+ and extensions.
5. `composer install --no-dev --prefer-dist --optimize-autoloader`.
6. Create isolated DB + user; write `.env` on the server.
7. Generate and store a staging MFA key offline.
8. Configure SMTP; do not use `array`.
9. `php database/migrate.php` twice; `php database/seed.php`.
10. Permissions on `storage/*`.
11. `php scripts/preflight.php --staging` → 0 FAIL.
12. Run the HTTP/auth/media checklist; delete fixtures.
13. Confirm robots `Disallow: /` and noindex.
14. Confirm test-pay 404.

## Record after a real deploy (fill locally, never commit secrets)

| Field | Value |
|---|---|
| Hostname | *(set after DNS)* |
| Document root | *(path)* |
| PHP version | *(php -v)* |
| Composer | install --no-dev … |
| Migrate | first / second run |
| Seed | roles=4 categories=5 |
| Preflight --staging | FAIL= / WARN= |
| SMTP | configured / blocked |
| MFA key | present (do not paste) |
| HTTPS | yes/no |
| robots | Disallow: / |
| test-pay | 404 |

---

STAGING-1 from this workspace: **package only**. No production code change. No Hostinger deploy executed.
