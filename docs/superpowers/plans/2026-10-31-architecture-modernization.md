# Pudhu Padam (tentukotta.in) — Architecture & Refactoring Plan

> **Document type:** Senior software architect / tech-lead design & roadmap.
> **Readers:** CTO, lead developer(s), DevOps.
> **Goal:** Upgrade the stack, refactor the monolith into "best-structured production code", and eliminate the live production errors — without breaking streaming, payments, or the Android app.
> **Prerequisite:** The security refactoring plan (`2026-10-31-security-refactoring.md`) should be run first; this plan assumes the app is hardened in place but not yet re-architected.

---

## 0. Current State (as-received, verified by static analysis)

### 0.1 Tech stack
| Layer | Current | Status |
|---|---|---|
| Language | PHP 8.3.6 (hosting), 8.3.6 CLI | OK (but CI3 core classes violate the no-dynamic-properties rule) |
| Framework | CodeIgniter 3.1.13 | **EOL** — not CI4-ready, dynamic-property deprecations, no typed models |
| Database | MySQL 8 (InnoDB + one MyISAM table `download_link`) | OK |
| Web server | Apache 2.4 + mod_rewrite | OK |
| JS | jQuery 3.2.1, video.js + contrib-hls/dash, dash.js | No framework, acceptable but dated |
| Cache | `ci_sessions` in DB, no Redis/Memcached | OK |
| Mobile | Android APK (Google Play / direct), pushes via OneSignal | In scope for API contract |
| Payments | PayPal (IPN), Stripe (legacy token source API), Razorpay (signature verify) | Live, business-critical |
| External | TMDB scraping, Google OAuth, Google reCAPTCHA, Google Analytics, Cloudflare | Third-party dependent |
| Tooling | **None** — no composer.json, no PHPUnit, no static analyzer, no CI/CD | Zero |

### 0.2 Code surface
- **~97,000 lines of PHP** (`system/` = 67k from the vendor CI3 core; `application/` = ~30k).
- **25 controllers** — one giant 5,089-line `Admin.php`; 8 REST controllers in `rest_api/` (v100, v130, legacy, and four sub-versions); 13 models.
- **147 views** split across `theme/default/` (75 front-end views) and `admin/` (72 admin views).
- **Assets:** `assets/` (33MB CSS/JS/player/plugins/theme) + `uploads/` (~62MB media + 1,801 files).
- **Media model:** Movies/TV served **raw** from `uploads/` via `<video>`/video.js (mp4/webm/mkv), HLS (`m3u8`) and DASH (`manifest.mpd`); **download links** (`download_link`/`episode_download_link`) point to Google Drive / OneDrive / Telegram / third-party CDNs. No streaming backend, no CDN edge caching on video.

### 0.3 Data model (43 tables)
`user`, `videos`, `episodes`, `seasons`, `video_file`, `download_link`, `episodes_download_link`, `subscription`, `plan`, `rating`, `comments`, `request`, `config` (key–value), `ci_sessions`, `keys`, `logs`, plus 27 content tables (genre, country, star, director, tag, live_tv, slider, pages, posts, etc.).

**`user` table:** `user_id`, `name`, `slug`, `username`, `email`, `is_password_set` (0/1/2), `password` (LONGTEXT), `token` (MEDIUMTEXT), `role` (admin/subscriber/gate_man), `firebase_auth_uid`, `phone`, `status`. 123 seeded rows; all `password` values are **32-hex MD5** (including the admin and `is_password_set=0` "unknown" accounts — they appear to store the MD5 of the email, i.e., a constant).

### 0.4 Live production errors (from Hostinger error log)
```
Severity 8192: Creation of dynamic property CI_URI::$config is deprecated   (core/URI.php:102)
Severity 8192: Creation of dynamic property CI_Router::$uri is deprecated     (core/Router.php:128)
Severity 8192: Creation of dynamic property Home::$benchmark / $hooks / $config / $db ... is deprecated  (core/Controller.php:83)
Severity Warning: mysqli::real_connect(): (HY000/1045) Access denied for user 'u473259675_pp_stream_1'@'localhost' (using password: YES)
Severity Warning: Cannot modify header information - headers already sent (system/core/Exceptions.php:272)
Severity Notice: A Database Error Occurred ... Core_Controller.php:7
```

Root causes, one per line:
1. **CI3 core declares no properties** (`CI_URI`, `CI_Router`, `CI_Controller`), but PHP 8.2+ forbids creating them at runtime → 8192 deprecation notices on *every* page load, including `Home::$benchmark`/`$hooks`/`$config`/`$db`/`$router`/`$output`/`$security`/`$input`/`$lang`/`$log`/`$uri`/`$exceptions`. These flood the log and — worse — count as **output**, so PHP can no longer send redirects/cookies (the "headers already sent" cascade).
2. **`ENVIRONMENT=development`** with display_errors on → CI3 prints all of the above as HTML, and the deprecation output precedes `session_start()` → header errors throughout the app.
3. **Database user `u473259675_pp_stream_1` no longer matches** whatever the current MySQL user/host grant is (`Access denied`) → `A Database Error Occurred` at every `Core_Controller` bootstrap → site broken until the credentials are fixed (or the dump/user migrated).
4. **Credentials are stale in the repo copy** — the local DB dump is from 2024-11-03, the live host errors reference `7010066.xyz` and a different DB name pattern. Any config migration must be done against the live host, never by committing real secrets.

### 0.5 Security posture (from `SECURITY-AUDIT.md`, corroborated)
- `application/config/database.php` plaintext credentials in the repo.
- `rest.php`: `rest_auth = FALSE` on v100/v130, `strict_api_and_auth = true`, `rest_valid_logins = ['admin' => '1234']`.
- 62 usages of `md5()` across controllers/models; password column is LONGTEXT so upgrades are trivially possible.
- `Updater.php`: unauthenticated-zip-extraction-then-`mysqli_multi_query(database.sql)` inside a session-guarded endpoint (auth is present but trivially bypassable / the `mysqli` object is created from the CI `db` object without error-checking; `temp_config` logic corrupts `config` on collisions).
- `save_payment()`: reads `plan_id` from DB but the grant is decided purely by the client-posted `payment_info`/`payment_method` with no server-side gateway call.
- Open redirects via `$url` in `Comments.php`; direct `move_uploaded_file()` to `uploads/` with `allowed_types: jpg|png|mp4|webm|mkv|m3u8` and `max_size = 0`.
- Output: raw `echo $row['...'];` and `echo $slider->title;` with no escaping; `html_escape()` exists in helper but is not used in views.
- 43 tables, one `download_link` indexed on MyISAM (transactional oddity), session data in DB with no TTL cleanup.

### 0.6 The three REST API versions
- **v130** (`/rest-api/v130/...`): `user_info_by_code_post` (connection-code login), `config_get`, `store_payment_info_post`, TV code connect, `get_user_info_by_user_id`, `get_all_*` catalog endpoints, payment config.
- **v100** (`/rest-api/v100/...`): `login_post`, `get_apk_version_info`, older catalog.
- **legacy**: `login`, subscription-status helpers.
- No API key check today; mobile app authenticates via stored `token` in DB and connection-code flow.

---

## 1. Why we are refactoring (the business case)

1. **Platform risk:** CI3 on PHP 8.3 is working *against the language*. Every future PHP/security release widens the gap; deprecation noise breaks HTTP semantics (headers already sent).
2. **Scale limit:** one 5,089-line controller, a 2,745-line model, ad-hoc config; new features (live TV categories, admin RBAC, payment reconciliation) each touch the whole monolith.
3. **Observability = zero.** No error logging, no structured logs, no tracing; "it works on my machine" is the only debugging aid.
4. **Reliability:** DB sessions, no connection pooling config, no queue for email/cron/image-grabbing (`Cron::image` hits external URLs synchronously in admin requests).
5. **Streaming economics:** raw `uploads/` serving via Apache; video bandwidth is the dominant cost and is currently unoptimized (no CDN, no signed URLs, no transcoding control).

---

## 2. Target architecture

### 2.1 Guiding principles
- **Correctness first, cost second:** no new vendor content, no paid services required for baseline.
- **Preserve the URL contract** — public SEO URLs and API endpoints live behind an adapter layer so the migration is invisible to users and the app.
- **Incremental strangler:** the monolith is *too big and too live* to rewrite. New features and the next release cycle run in the new system; the old system is retired endpoint by endpoint.
- **One source of truth:** the database schema is the contract. Migrations are versioned and reversible.
- **Structure over cleverness:** every component is small, testable, and follows the same shape as its neighbor.

### 2.2 Recommended target stack
| Layer | Decision | Rationale |
|---|---|---|
| Framework | **Laravel 12 (PHP 8.4)** | Typed architecture, built-in migrations/validation/auth/rate-limiting, huge talent pool, CI3 → Laravel path is well documented; CI4 was considered but Laravel's ecosystem for a PHP team is larger and more hireable. |
| Language | PHP 8.4 | Latest stable; matches current hosting capability. |
| DB | MySQL 8 | Keep; MyISAM `download_link` must be converted to InnoDB during migration. |
| Cache | **Redis** | Sessions (5–10× faster than DB sessions), route/config cache, job queues, rate limits. |
| Queue | Redis + Laravel queues | Offload email, image fetching, notification push, subscription expiration checks, video thumbnail regeneration. |
| Web | Apache/Nginx + PHP-FPM (VPS) | Shared-hosting .htaccess tricks don't apply to queues/CLI; a single low-cost VPS (2–4 vCPU, 4–8 GB RAM) is the cheapest way to get cron + CLI + Redis. |
| Frontend | Keep jQuery UI today; **strangler front-end first** via Blade in the same Laravel app (same DB). |
| Observability | Laravel logs → file/MySQL; optional Sentry later. Structured logging from day one. |
| CI/CD | GitHub Actions (or GitLab CI) → deploy script. No composer.dev; vendor dir committed or installed via CI. |
| Mobile | Android app remains **native Java/Kotlin** consuming the **new** Laravel API. |

### 2.3 Alternative options considered (and why they were rejected)
1. **In-place CI3 patching (PHP 8.2+ compatibility + `ENVIRONMENT=production`).** Cheapest, fastest, but you get a patched EOL framework forever. Only the prod-error layer is fixed; nothing else improves. **Rejected as the end-state** — acceptable as a *temporary* bridge (< 4 weeks) while the new system ships.
2. **Laravel 11 → 12 upgrade only (monolith, monolithic MVC).** Good middle ground; removes CI3 pain, adds quality baselines, keeps all 97k lines + DB. **Rejected** — the business wants a *restructure*, and a monolithic Laravel 12 with the same 147 views / giant controllers buys you a better framework but not better structure. This plan makes Laravel 12 the *foundation* of the refactor, not a destination by itself.
3. **Symfony monolith.** Stronger standards compliance, but smaller PHP talent pool than Laravel and a steeper migration curve from CI3 conventions. **Rejected** for team/productivity reasons; Laravel 12 chosen.
4. **Headless API + separate SPA / Flutter app.** Maximum modernization but multiplies scope (two apps, auth tokens, CDN strategy) and delays the business value. **Deferred** — keep the server-rendered Blade front-end as the strangler's first phase; the API becomes headless-ready only after the new backend is proven.
5. **Full re-architecture with event sourcing / clean arch / microservices.** Over-engineered for a single-streaming-site business. **Rejected.**

### 2.4 Target topology (single VPS)
```
Cloudflare (DNS + TLS + WAF)  →  Load balancer (Traefik/Nginx)  →  Laravel 12 app
                                                                →  Redis (sessions, queue, cache)
                                                                →  MySQL 8
                                                                →  Object storage (MinIO/S3) for video  [phase 2]
```
- **Video path (phase 0):** continue serving `uploads/` from the same disk (NFS-equivalent bind mount to the VPS) so the switch is seamless.
- **Video path (phase 2):** move video + poster images to S3-compatible object storage behind signed URLs; keep download links as-is (they are already external).

### 2.5 Data & domain boundaries (the new structure)
The domain splits into **bounded contexts**, each a Laravel module/package (Laravel 12 supports native packages in `/modules`):

1. **Content** — videos, episodes, seasons, video_files, download_links, tvseries, genres, countries, stars, directors, languages, tags, years, live_tv, live_tv_category, sliders, homepage_sections, quality, video_type. (The 70% bulk of the CMS.)
2. **User / Auth** — users, ci_sessions → `sessions` table, password reset, firebase_auth_uid, token, device, roles (admin/subscriber/gate_man), gate_man RBAC.
3. **Subscription** — plan, subscription, coupon, transaction_history, payment_verifier (gateway-agnostic adapter: Stripe/PayPal/Razorpay), renewal/expiration checker.
4. **Catalog API** — v130/v100/legacy adapters that read Content + User and emit the exact current JSON shape.
5. **Admin** — dashboards, CRUD controllers, backups, updates, moderation, reports, notifications (OneSignal push).
6. **Front-end** — Blade views (today: `theme/default`), routes mirroring existing SEO URLs.

---

## 3. Production-outage first aid (run this before any refactor)

The host is currently **down or degraded**. Nothing else matters until this is fixed. Do it in 10 minutes, from the Hostinger panel, no code change:

1. **Confirm the current MySQL user/host** in the billing panel (the `pp_stream_1` user may have been rotated, dropped, or moved off `localhost`).
2. **Update `application/config/database.php`** with the working credentials — on the host. (Or create the missing user with GRANT ALL and note it in `docs/runbooks/production-db.md`.)
3. **Set `ENVIRONMENT = 'production'`** in `index.php` — this silences the deprecation flood and the "headers already sent" cascade instantly.
4. **Set `$config['db_debug'] = FALSE`** in `database.php`.
5. Verify: one full page hits 200, no 8192 lines in the error log.

**Note:** the local code copy and the live site may be different hosts (`tentukotta.in` vs `7010066.xyz`). Treat the repo as a *reference copy only*; all config changes happen on the host, never by pushing `database.php` to git.

---

## 4. Modernization roadmap (strangler pattern)

### Phase 0 — Bridge & baseline (1 week)
Goal: restore green prod + establish a defensible baseline so the refactor has a safety net.
1. Fix DB credentials + production mode (see §3).
2. Initialize git at repo root, `.gitignore` (exclude `uploads/`, `db_backup/`, `.sql`, `database.php`, `application/logs/cache`), baseline commit.
3. Ship the security-refactoring plan first (13 tasks) — hardening the *existing* CI3 app before touching its shape. This also produces the `tests/security/check.php` harness used throughout.
4. Install **baseline tooling** (optional but recommended): PHPStan `@level 5` rule configuration + a simple `phpcs` PSR-12 set, plus a GitHub Actions workflow that runs them. No PHPUnit yet.

### Phase 1 — Laravel 12 skeleton + content read-model (3–4 weeks)
Goal: a working Laravel 12 app that **reads the existing MySQL DB** and serves public catalog pages + movie/TV/watch URLs — same URLs, same HTML output, no write paths yet.
1. Laravel 12 install; environment via `.env` (never commit); Redis connection.
2. **Schema migration plan:** audit each of the 43 tables; write idempotent, reversible migrations for the new shape. Key schema changes:
   - `download_link` MyISAM → InnoDB (rebuild as InnoDB, then rename).
   - `user`: add `email_verified_at`, `remember_token`, normalize `role` (enum or tinyint); keep `firebase_auth_uid` and `token`.
   - `subscription`: add `gateway`, `gateway_transaction_id`, `status` (pending/granted/expired/cancelled) — the current `status=1` is opaque.
   - `ci_sessions` → `sessions` (Laravel shape) and deploy the Redis session driver.
   - Add `password` algorithm hint column? **No** — keep the `LONGTEXT` and detect by prefix (`$argon2id$`, MD5 32-hex). Transparent upgrade at login (§5).
3. Eloquent models for all 43 tables, following one convention (singular, snake_case, `__construct` loads nothing).
4. Routes: exact mirror of existing SEO URLs (`/watch/{slug}.html`, `/tv-series/watch/{slug}`, `/movies`, `/search`, `/request-movies`, `/live-tv/...`).
5. Controllers: thin controllers that delegate to **service classes** (`ContentService`, `VideoService`, `SubscriptionService`). No DB queries in controllers.
6. Views: **Blade port of `theme/default/`** (75 views) — first-pass copy, same markup/classes, then layer escaping.
7. **Verification gates per route:** compare old CI3 page vs new Laravel page (title tag, meta description, og tags, video player markup, cast/crew lists, episode list). Automated diff script in CI.
8. Deploy to a **staging VPS**; run the full public crawl; fix until output parity. **Go/No-Go gate: public read-path parity.**

### Phase 2 — Admin read + identity migration (2–3 weeks)
Goal: admins can log in and *read* everything in the new system; users can authenticate.
1. Authentication: Laravel Breeze/Jetstream-derived flow, `Auth::user()`, session guard.
2. Port the session keys (`admin_is_login`, `user_is_login`, `gate_man_is_login`, `login_type`) into a compatibility shim so **existing admin sessions keep working** — no forced re-login at go-live.
3. Admin base controllers → thin admin controllers delegating to admin **action classes** (`ManageVideos`, `ManageUsers`, `ManageLiveTV`, `ManageSubscription`, `ManageSystemSettings`, `Moderation`).
4. **Read-only migration of the admin UI:** Blade-ify `admin/` views; wire the existing jQuery DataTables / modal AJAX to Laravel controller actions returning JSON (`admin_ajax` route prefix).
5. Rate-limit the admin AJAX + login (Laravel built-in throttle middleware) — replaces the brute-force gap.
6. Gate: 100% of existing admin read endpoints produce identical JSON/pages vs CI3 (crawl the protected admin area with a seeded test account).

### Phase 3 — Write paths strangler (4–6 weeks)
Goal: every admin write action now hits Laravel; CI3 writes are disabled route by route with a compatibility fence.
1. For each admin module: Laravel controller → form request validation → service/model → DB update → flash → redirect.
2. **Compatibility fence:** each legacy route that still exists is wrapped: if the request target matches a "migrated" route → Laravel; else CI3. The fence logs a deprecation counter per route; routes with zero traffic for 30 days get killed.
3. Critical paths in priority order:
   - Videos/episodes CRUD (highest traffic, highest breakage risk — do first).
   - TV series / seasons / episodes.
   - Live TV (category, URL, cron-triggered thumbnail grab).
   - Users / moderation / comments.
   - Subscriptions / coupons / plan / payment settings.
   - Slider / pages / posts / seo / language.
4. **The giant Admin.php → 12–14 focused controllers.** One controller per domain screen; ≤ 350 lines each, no `function __construct` re-declarations, no session-check sprinkles (base controller gates).

### Phase 4 — Payments & subscriptions, API, mobile (3–4 weeks)
1. **Payment gateway adapter:** `PaymentGatewayInterface` with `StripeGateway`, `PaypalGateway`, `RazorpayGateway` implementing `verify(string $payload, float $amount): bool`. All three gateways go through it — the `save_payment()` trust-client bug never reappears because the adapter is the *only* grant path.
2. Restore/replace `store_payment_info_post` and `save_payment` with the verified path; keep Razorpay's existing `verifyPaymentSignature` (it's already correct — reuse, don't rewrite).
3. Subscription checker (`subscription_model` logic) → `SubscriptionService` with a queued daily expiration job; add `status` column for reconciliation reporting.
4. **API layer:** `Api\V130`, `Api\V100`, `Api\Legacy` controllers under one `ApiCore` (auth by `mobile_apps_api_secret_key` from `config`, hashed compare via `hash_equals`, Redis key cache). Response classes mirror the current JSON keys — nothing renamed, nothing dropped except secrets/PII.
5. Mobile release: ship the new `X-API-KEY` header requirement; Android app gets a config bump (or remote config) to send it.

### Phase 5 — Front-end modernization (ongoing, optional business cases)
Only after backend stability is proven.
1. Video delivery: signed URLs from object storage (S3/MinIO) + a CDN edge layer; per-resolution HLS transcoding for bandwidth savings (FFmpeg, queued job).
2. Front-end: migrate Blade views → Alpine/HTMX for the interactive bits, or a single Vue/React SPA that consumes the headless API.
3. Performance: route/config/query cache, OPcache tuning, image webp conversion, lazy-load below-the-fold assets.

### Phase 6 — Decommission CI3 (the kill switch)
1. Route-fence traffic counter → confirm each legacy CI3 route has zero traffic for 60 days.
2. Delete CI3 routes one week after the last zero-traffic gate.
3. **`Updater.php` kill:** replace the zip-extract + `mysqli_multi_query` update mechanism with Laravel **database migrations** + a signed-artifact release process (Artifactory/GitHub Releases + `php artisan migrate --force` + file sync via verified tarball). Document the new update runbook.
4. Archive the repo: `application/` and `system/` under `legacy/` with a one-page "how the old system worked" note. Delete from web root.

---

## 5. Data migration strategy (big-bang is wrong; this is right)

**Golden rule:** never rewrite a row twice. The two systems share **one database** — the Laravel app reads/writes the *same* MySQL instance as CI3. This is the strangler's superpower: no downtime, no dual-write, no data reconciliation.

- **Schema changes are migrations, executed once, reversible** (`migrate:rollback`), run in CI on a staging clone first, then on production during a brief write-window.
- **PII migration:** no migration needed for structure (ALL `LONGTEXT`/`MEDIUMTEXT` columns already exist and are UTF-8). The *value* migration is the password upgrade: first successful login of each of the 123 accounts triggers `password_needs_rehash` → store Argon2id; accounts with `is_password_set = 0` keep their login (password was a saltless email hash — on upgrade, force a reset via email on next login).
- **Session migration:** CI3 DB sessions → Laravel session table or Redis. During Phase 1, keep the CI3 `ci_sessions` reader alive until Phase 2 goes live; then point the driver to Redis and expire the old table.
- **No bulk reseed** of content. Everything is read from production. Only new content written during migration lands in Laravel (tracked via `migrated_at` audit columns added in Phase 1).
- **Backup snapshot** before Phase 1 migrations: full `mysqldump` + `uploads/` tarball, stored off-host.

---

## 6. Security baseline (carried over from the security plan)

The security plan's 13 tasks are the **foundation**; the Laravel rebuild re-instantiates them natively:
- Middleware `auth`, `admin`, `api_key`, `throttle`, `csrf`, `strict-transport`, `x-frame-options`.
- Laravel `Hash::argon2id()`, `password_needs_rehash()` — the legacy MD5 detection is a legacy-adapter concern; in Laravel you store Argon2id and migrate at login, but the *concept* (never trust stored hash format) is the same.
- Form Request validation + Laravel's built-in CSRF (every form: `@csrf`); no manual `security->get_csrf_hash()` glue.
- Uploads: store in object storage or `storage/app` (never public), validated MIME via `finfo`, re-encode with GD/Intervention, size caps in the service layer.
- `config/` is never written from DB `INSERT` into a `config` table used as config — that `config` key–value table becomes an **admin-managed settings module**, not framework configuration. (`ovoo_config()` is rewritten as a typed Settings service.)
- `Updater.php` → migrations (see Phase 6).

---

## 7. Observability & operations

| Concern | Approach |
|---|---|
| Logging | PSR-3 `Log` facade, channels: daily file + stderr for queues; structured `[event][user_id][context]`. |
| Errors | `APP_DEBUG=false` on prod; error reporting to a channel; optional Sentry DSN in env. |
| Health | `/up` endpoint (Laravel's `php artisan up` / `down` maintenance mode), Redis+liveness probes for deploy tooling. |
| Queues | `redis` driver; `queue:work --sleep=3 --tries=3`; supervised by `supervisor` (or the hosting process monitor). |
| Cron | Replaces `Cron::image` image-download jobs: Laravel scheduler (`schedule:run --watch`) + queued jobs. |
| Backups | Daily `mysqldump` + `uploads/`/`storage/` snapshot → off-host storage; test restore quarterly. |
| Deploy | Blue/green or zero-downtime swap script: pull → `composer install --no-dev --optimize-autoloader` → `migrate --force --step` → `config:cache` → `view:clear` → symlink public → `up`. Rollback = symlink back + `migrate:rollback`. |
| Metrics | Basic counters: daily streams, daily watch counts, subscription grants, error rate — a small `metrics` table or export to CloudWatch/Prometheus in Phase 2+. |

---

## 8. Risks, dependencies & blockers

| Risk | Mitigation |
|---|---|
| **Hostinger shared-hosting limits** (no CLI, no Redis, no long-running processes) block Phase 1+. | Buy a cheap VPS ($10–20/mo); the refactor *requires* it anyway. Keep CI3 running on shared until the Laravel URL layer is green. |
| **Stale/repo credentials** — the local dump and live host disagree. | All config changes made on the live host only; repo keeps a `database.php.example`. |
| **API contract drift breaks the Android app** | API layer is a strict adapter with a response-parity test matrix; every change reviewed against the current JSON keys. |
| **The 5,089-line Admin.php is a dependency knot** | Split by *screen* (not by function); each screen becomes its own route/controller; the fence catches misses. |
| **TMDB scraping breaks** (free API limits changed) | Decouple into `TmdbService` with its own retry/timeout/caching and a fallback to manual admin entry; no shared-memory coupling. |
| **Payment verification regression** | Adapter unit tests + a staged "test mode" sandbox with test cards on all three gateways before go-live. |
| **GDPR/PII** — 123 user emails/phones in plain text | Encrypt `phone`, `firebase_auth_uid` at rest (Laravel `enc:` prefix) in Phase 4; retention/purge policy in Phase 5. |

**Known data-quality oddities to plan for:** `download_link` table is MyISAM (convert in migration); `videos.rating` is `varchar(5)`; `subscription.paid_amount` is `float` and `price_amount` is `double` (use `DECIMAL` in Laravel models); `ci_sessions` stores serialized blobs.

---

## 9. Quality bar — "best structured production code" means

Every Laravel component produced must satisfy:
1. **Single responsibility** — controller does not query DB; model does not render; service holds business rules.
2. **Explicit types** — method signatures typed, `strict_types=1`, no `var_dump`/`print_r`/`// TODO` in committed code.
3. **Validation boundary** — every write enters through a Form Request; all `$_POST` is dead code.
4. **No SQL strings** — Eloquent or query-builder only; no `DB::select("...{$var}...")`.
5. **No hardcoded secrets** — everything via `config()`/`env()`.
6. **Tested** — each service has at least one unit test; each public route has one integration test; CI blocks merge if coverage < 60% of new code (grows over time).
7. **Reviewable** — one concern per PR, ≤ 400 lines, with the verification matrix entry checked off.
8. **Documented** — one `README.md` per module: purpose, public interface, key tables, known quirks.

---

## 10. Assumptions & open decisions (ask the stakeholder)

1. **Hosting model:** do we move to a VPS now (recommended — it enables queues/Redis/CLI)? Or stay on Hostinger shared as a short bridge?
2. **Mobile app replacement:** keep the existing Android app and adapt it to the new API (Phase 4), or replace it later? (Plan assumes keep-and-adapt.)
3. **Video cost strategy:** accept current raw-serving economics now and optimize in Phase 5, or do S3 + CDN + HLS transcoding up front? (Savings only, no user-facing benefit — Phase 5.)
4. **Budget/timeline:** is this a 4–6 month phased program or a 2-week crash to "Laravel on top"? The plan is written for the phased program; it can be compressed only by accepting lower test coverage and skipping Phase 2/5.
5. **Team:** who will implement? (This plan assumes at least one senior PHP engineer; the security plan should run first by whoever has host access.)

---

## 11. Verification matrix (what "done" looks like)

| Milestone | Exit criteria |
|---|---|
| M0 — prod restored | 200 on all public routes; no 8192/500 lines in the host error log; DB user confirmed working. |
| M1 — public read parity | Automated page-diff of every public URL: CI3 vs Laravel identical (SEO meta, og, player, cast/crew, episodes); staged VPS green. |
| M2 — auth parity | Admin + subscriber + gate_man login, session preservation, 100% admin read endpoints identical JSON/pages. |
| M3 — write parity | All admin CRUD green in Laravel; CI3 write routes fenced off; zero data divergence (spot-check row counts + key rows). |
| M4 — payments live & verified | Stripe/PayPal/Razorpay verification adapter passes sandbox tests; `save_payment` + `store_payment_info_post` grant only on server-verified success. |
| M5 — API parity | All v130/v100/legacy endpoints return identical payloads with auth enforced (401 without key); mobile app builds and authenticates. |
| M6 — CI3 retired | All legacy routes zero-traffic for 60 days; CI3 code archived; `Updater.php` replaced by migrations + signed releases. |
| M7 — hardening audit pass | Security plan's 19 findings all closed *and* re-checked against the Laravel codebase (a second, smaller audit pass). |

---

## 12. First actions (next 5 working days)

1. **Fix the prod outage** (§3) — credentials + `ENVIRONMENT=production` — on the live host. [Owner: ops / whoever has Hostinger access]
2. **Confirm hosting decision** (§10-Q1): order VPS with PHP 8.4 + MySQL 8 + Redis, or stay shared for 2 more weeks.
3. **Start the security plan** (`2026-10-31-security-refactoring.md`) — run it against CI3 so we don't re-architect unhardened code.
4. **Provision staging Laravel 12 skeleton** and the first content read-model (Phase 1, step 2).
5. **Schedule go/no-go gates** with the business before each migration window — Phase 1 and M4 are the highest-risk, highest-reward gates.
