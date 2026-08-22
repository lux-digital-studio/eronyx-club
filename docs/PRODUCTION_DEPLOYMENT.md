# ERONYX production deployment

Technical checklist for staging/production. Contains **no secrets**. Do not copy `.env` values into this file, Git, or backups under the web root.

## Requirements

- PHP 8.2+
- MySQL/MariaDB with `utf8mb4`
- Apache with `mod_rewrite`, `mod_headers` (recommended), `mod_deflate` (recommended)
- Document root must be `public/` (or `public_html` mapped to the contents of `public/` plus the rest of the app **outside** that directory)
- Composer **lockfile** (`composer.lock`) governs installs — never `composer update` on the server

## PHP extensions

Required: `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `json`, `session`, `ctype`, `filter`, `hash`.

TOTP/QR uses OpenSSL (AES-256-GCM) plus Composer packages `spomky-labs/otphp` and `bacon/bacon-qr-code`. Sodium is **not** required.

## Apache / document root

Point the vhost at `public/`. `TraceEnable Off` belongs in the vhost if the host allows it; `.htaccess` also forbids TRACE/TRACK via rewrite so the PHP app is not executed. Some Apache builds still answer TRACE at the protocol layer (`message/http`) before rewrite.

If Hostinger only offers `public_html`:

1. Keep `app/`, `config/`, `storage/`, `database/`, `vendor/`, `.env` **outside** the public directory.
2. Place only the contents of `public/` (index.php, css, js, `.htaccess`) in `public_html`.
3. Adjust `public/index.php` autoload path if the app root is one level above — do this as a hosting-specific change, not by moving `storage` into the web root.

Must not be web-accessible: `app/`, `config/`, `database/`, `storage/`, `tests/`, `vendor/` metadata, `.env`, `composer.json` / `composer.lock` if avoidable.

## Environment variables

Copy `.env.example` → `.env` on the server. Fill real values there only.

| Variable | Production |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (forced off if `APP_ENV=production`) |
| `APP_URL` | `https://…` (canonical, OG, sitemap, emails) |
| `DB_*` | dedicated user, not `root` |
| `MAIL_MAILER` | `smtp` (`array` is a preflight **FAIL**) |
| `MAIL_HOST`, `MAIL_FROM_ADDRESS` | required |
| `MFA_ENCRYPTION_KEY` | 32 bytes (64 hex or base64). Generate **once**, back up offline. Do not rotate casually. |
| `SECURE_COOKIES` | production HTTPS also forces Secure cookies in code |
| `COOKIE_SAMESITE` | `Lax` |
| `TRUSTED_PROXIES` | empty unless a known reverse-proxy IP is required |
| `VERIFICATION_MODE` | `manual_review` until a real KYC provider exists. `provider` + empty/`test` **fails closed**. |

Local-only: `MAIL_MAILER=array`, HTTP `APP_URL`, empty MFA key (TOTP setup will fail until set).

## HTTPS and cookies

Production requires HTTPS `APP_URL`. Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` when `APP_ENV=production` **and** the request is HTTPS. `session.use_strict_mode=1`. Lifetime `0` (browser session). Host-only cookie (`domain` empty). Do not trust `X-Forwarded-Proto` unless `TRUSTED_PROXIES` contains the proxy IP.

HSTS is sent only for production HTTPS (`max-age=31536000; includeSubDomains` — existing SECURITY-1 policy). Do not add `preload` until subdomains are fully HTTPS.

## Security headers

CSP (`script-src 'self'`, `img-src 'self' data:` for MFA QR), `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy. JSON-LD is `application/ld+json`, not inline JS.

## Storage and uploads

Private media lives in `storage/private/media` and is served only by `MediaController` after authorization. Do not put uploads in `public/`. PHP/scripts in uploads are rejected (real MIME via `fileinfo`; no SVG; videos only as private content). App image limit: 5 MB. PHP `upload_max_filesize` / `post_max_size` must be **≥** the app limit; the app limit wins.

Permissions (typical): directories `0755`, files `0644`, owned by the PHP user. **Not** `0777` unless a host emergency. Writable: `storage/logs`, `storage/cache`, `storage/private/media`.

## Sessions

PHP file sessions (not under `public/`). Default `session.save_path` is the PHP/host path. Redis is out of scope. Hostinger shared hosts may recycle workers — treat session files as ephemeral; users re-login after recycle.

## Mail

Production SMTP with TLS peer verification. Timeout 10s. Array transport is for local/tests only. If a reset email cannot be delivered, the reset token is invalidated.

## MFA key

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Store the value only in server `.env` and an offline backup. Rotating it without a migration will make existing TOTP secrets unreadable.

Production clock/NTP must be correct (TOTP window ±1 period).

## Age verification

Until a real provider is integrated: `VERIFICATION_MODE=manual_review` is a **compliance risk**, not a silent pass. `VERIFICATION_PROVIDER=test` cannot activate in `production` or `staging`.

## Database

PDO `ATTR_EMULATE_PREPARES=false`, charset `utf8mb4`. Production user: `SELECT/INSERT/UPDATE/DELETE` on app tables. Optional separate account for `CREATE/ALTER` during migrations. Never use `root` for the app.

## Migrations and seeds

```bash
php database/migrate.php
php database/seed.php
```

Migrate: pending files only, unique migration names, fail with exit 1. Seeds: roles + categories, **idempotent**. Do not run test fixtures.

Pre-deploy: backup DB + `storage/private` → maintenance window if needed → migrate → smoke.

## First admin

No bootstrap user is shipped. Create an account via register, then insert `user_roles` for `admin` (and typically `moderator`) in a secured DB session. Do not commit credentials. Enable MFA for staff before launch (recommendation; not enforced in code).

## SEO

`APP_ENV=production` → public routes `index,follow`; private remain `noindex`. Staging/local/test: global `noindex` and robots `Disallow: /`. `APP_URL` must already be the HTTPS production host before going live. Default OG image is a **visual** gap, not a technical blocker.

## Composer / OPcache

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

OPcache: enabled in production (`opcache.enable=1`, `validate_timestamps=0` after deploy, or `1` with a restart policy). Do not change local `php.ini` for this project.

## Backups

DB dump + `storage/private` on a schedule (daily DB, media with uploads). Store **outside** `public_html`. No secrets in filenames. Test restore before launch. Not implemented in-app.

## Cron / cleanup (future)

Useful jobs, not shipped as daemons:

- Expired password-reset and email-verification tokens
- Rate-limit JSON files under `storage/cache/rate-limits` (hashed names)
- Log rotation of `storage/logs/app-YYYY-MM-DD.log`

## Maintenance

No in-app maintenance mode. For risky migrations: hold traffic at the proxy/host, run migrate, smoke, reopen.

## Timezone

PHP/DB currently use server defaults (no app override). Set PHP and MySQL to the same zone (recommend `UTC` or `Europe/Madrid`) and keep them consistent.

## Health

No `/health` endpoint (avoids leaking DB/env). Operational check: HTTP 200 on `/` and `/login` plus DB connect from a private probe.

## Test/dev features (fail closed)

| Feature | Production |
|---|---|
| `POST /account/orders/{id}/test-pay` | 404 (`APP_ENV` must be `local`) |
| Dev password-reset URL | only `local`/`test` |
| `VERIFICATION_PROVIDER=test` | throws `provider_not_configured` |
| Array mailer | preflight FAIL |

## Preflight

```bash
php scripts/preflight.php --local
php scripts/preflight.php --staging
php scripts/preflight.php --production
```

Exit `1` on any `FAIL`. Output never includes secret values.

## Deployment steps

1. Backup DB and private media.
2. Set `.env` from `.env.example` (production values).
3. `composer install --no-dev --prefer-dist --optimize-autoloader`
4. `php scripts/preflight.php --production` — must exit 0 (legal identity must be filled for launch).
5. Permissions on `storage/`.
6. `php database/migrate.php` then `php database/seed.php` if roles/categories missing.
7. Reload PHP/OPcache.
8. Smoke: `/`, `/marketplace`, `/login`, `/robots.txt`, `/sitemap.xml`, `/css/app.css`.

## Rollback

Restore previous release files + previous `.env` (same MFA key) + DB backup. Do not “fix” a bad MFA key rotation by guessing.

## Post-deploy checks

- `APP_DEBUG` cannot leak traces
- HTTPS canonical/sitemap
- test-pay 404
- private `/media/{id}` 404 without grant
- login/MFA on a staff account
- robots allow + sitemap HTTPS in production

## Launch blockers (non-code)

- Legal placeholders `[PENDIENTE DE REVISIÓN LEGAL: …]` and draft policies
- No real SMTP
- No real payment provider
- Manual age verification without a KYC provider
- Staff MFA not enabled (operational)
- No OG fallback image (visual)
- Hostinger DNS/TLS not configured (out of this phase)
