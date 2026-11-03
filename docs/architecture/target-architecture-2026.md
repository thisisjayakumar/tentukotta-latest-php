# Target Architecture Evaluation — November 2026

**Status:** recommendation only. No migration has started and none should start until the gate in §10 is met.

**Scope:** choose a supported target platform for the movie-streaming application currently running on CodeIgniter 3.1.13, and define a migration and rollback strategy that preserves the public URL contract, the admin workflow and the mobile API.

**Evidence date:** 2026-11-03. Version facts below were checked against the vendors' published support policies on that date.

---

## 1. Recommendation

Adopt a **modular monolith on Laravel 13, PHP 8.5, MySQL 8 and Redis**, migrated with a **route-by-route strangler** behind a reverse proxy.

Do **not** rewrite the application in one release, do **not** split it into microservices, and do **not** move to a single-page frontend at the same time. The application is a live content business; the only affordable path is to keep serving the existing URLs from the existing database while ownership of routes moves to the new application.

The immediate priority is not the framework. It is rotating the secrets that are already in git history and keeping the hardened CI3 application stable until Phase 1 ships.

---

## 2. Where the application is today

| Area | State | Consequence |
|---|---|---|
| Framework | CodeIgniter 3.1.13, end-of-life | No security patches. Every PHP release widens the gap. |
| Language | PHP 8.3.6 | Security support ends **December 2027**. |
| Database | MySQL 8, one `videos`-centric schema, one MyISAM table (`download_link`) | Works, but blocks transactional guarantees and foreign keys. |
| Admin | ~5 000-line `Admin.php`, jQuery 3.2.1, Bootstrap 3 | High change cost, weak testability, known-old frontend dependencies. |
| Mobile API | Three generations (`v100`, `v130`, legacy `/api`) sharing one database | Contract must be preserved; the shared-key model has no per-user identity. |
| Vendored libraries | Google API client with Guzzle 6.5.3 | Known CVE (CVE-2022-31042), tracked in `docs/security/dependency-remediation.md`. |
| Security baseline | Environment-driven secrets, Argon2id passwords with MD5 upgrade, central admin/REST gates, CSRF, upload validation, SSRF guards, security headers, updater disabled, test suites | Good enough to operate while the migration is planned. Not a substitute for a supported framework. |
| Test coverage | `tests/unit`, `tests/security`, `tests/integration`, `tests/browser` | Gives the migration a regression harness and a definition of "contract preserved". |

The application's real assets are the database, the URL structure and the catalogue. The framework is replaceable; those are not.

---

## 3. Supported-version matrix

| Component | Current | Target | Vendor support position |
|---|---|---|---|
| PHP | 8.3.6 | **8.5** | 8.3 security fixes end **Dec 2027**; 8.4 and 8.5 are current. |
| Framework | CodeIgniter 3.1.13 | **Laravel 13** | CI3 is EOL. Laravel 13 supports PHP 8.3–8.5 and receives security fixes into **Q1 2028**. Laravel 12 security fixes end **Feb 2027**. |
| Database | MySQL 8.0 | **MySQL 8.4 LTS** | 8.0 is in extended support; 8.4 is the LTS line. |
| Cache/session/queue | Database sessions, no queue | **Redis 7** | Removes DB session load and enables background jobs. |
| HTTP client | cURL calls and Guzzle 6.5.3 | **Guzzle 7.9+** (via Laravel HTTP client) | Removes the tracked CVE and the vendored dependency. |
| Frontend | jQuery 3.2.1 / Bootstrap 3 | **jQuery 3.7.1 / Bootstrap 5** | Security patches and browser support; can lag behind the backend. |
| Payments | Server-side verification added in the security pass | **Webhook-driven adapters** | Avoids trusting client-supplied status/payload. |

Laravel 13 is chosen over Laravel 12 because 12's security window closes in February 2027, before a realistic migration would finish. Starting on a version that goes out of support during the migration would repeat the current problem.

---

## 4. Options considered

| Option | Verdict | Why |
|---|---|---|
| **A. Stay on hardened CI3** | Rejected as an end state | Cheapest for another year, but the framework is EOL, the admin is untestable, and the team keeps paying for patches that upstream will never ship. Acceptable only as the bridge while Phases 1–2 land. |
| **B. Laravel 13 modular monolith** | **Recommended** | Best balance of hiring pool, built-in auth/validation/queues/migrations, documented CI3-to-Laravel path, and a large package ecosystem for payments, queues and object storage. |
| **C. Symfony LTS monolith** | Rejected | Strong engineering standards, but a smaller PHP hiring pool in this market and a steeper conceptual jump from CI3 conventions for the same delivery capacity. |
| **D. Headless API + SPA/mobile-first rewrite** | Deferred | Multiplies scope (new frontend, token auth, SEO handling) before the backend is proven. The API can become headless-ready after Phase 4 without committing to an SPA. |
| **E. Microservices / event sourcing** | Rejected | Operational overhead far exceeds the scale of a single streaming catalogue. A modular monolith gives the same boundaries without the distributed-systems cost. |

---

## 5. Target architecture

### 5.1 Shape

A **modular monolith**: one deployable Laravel application, one database, with explicit module boundaries that can later be extracted if traffic ever justifies it.

```
app/
  Modules/
    Content/        videos, episodes, seasons, genres, countries, people, live TV
    Accounts/       users, profiles, devices, Firebase identities, sessions
    Subscriptions/  plans, coupons, renewals, entitlements
    Billing/        Stripe / PayPal / Razorpay adapters + webhooks
    CatalogApi/     v100, v130 and legacy response adapters
    Admin/          CRUD, moderation, reports, imports
    Frontend/       Blade views for the public SEO pages
```

Each module owns its actions, form requests, policies and models. Cross-module calls go through a small service interface, not through another module's models.

### 5.2 Runtime topology

```
Cloudflare (DNS, TLS, WAF)
        |
Nginx / Apache  ->  PHP-FPM  ->  Laravel 13 (PHP 8.5)
        |                        |
        |                        +--> Redis (sessions, cache, queues, rate limits)
        |                        +--> MySQL 8.4 (same schema during migration)
        |                        +--> Object storage (S3-compatible) for media, phase 3
        |
        +--> static assets / media CDN
```

- **Sessions:** Redis. During the cutover, keep the CI3 `ci_sessions` reader alive and use a different cookie name for Laravel so users are not logged out en masse.
- **Queues:** image fetching (with the SSRF guard), TMDB imports, email, push notifications and subscription-expiry checks move off the request path.
- **Object storage:** keep local `uploads/` in Phase 1–2; move posters and video to signed object-storage URLs in Phase 3 with a compatibility redirect for existing links.
- **Observability:** structured logs, a request ID, failed-job alerts, uptime and error-rate checks. Sentry is optional and should not block the migration.

### 5.3 Data

- **One database, two applications, during the strangler.** Laravel reads and writes the same MySQL instance as CI3. No dual-write and no nightly reconciliation.
- **Additive migrations only** for the first two releases: new tables and nullable columns, never a rename or drop that CI3 still reads.
- **Convert `download_link` from MyISAM to InnoDB** before foreign keys or transactions are relied upon.
- **Move to `utf8mb4`** and explicit `utf8mb4_unicode_ci`/`utf8mb4_0900_ai_ci` collation during the same maintenance window; test emoji and non-Latin titles.
- **Password compatibility:** Laravel's hasher verifies Argon2id hashes; legacy MD5 rows need a custom `UserProvider` or a one-time rehash-on-login path, exactly as the current `Password` library does. Do not reset every password as part of the migration.
- **Encryption:** CI3's `encryption_key` must not be reused blindly. Laravel's `APP_KEY` is separate; encrypt any stored secret with an explicit migration, not by decrypting CI3 cookies.

### 5.4 API and mobile contract

- Preserve `/rest-api/v100/*`, `/rest-api/v130/*` and `/api/*` response shapes field-for-field. The integration suite is the arbiter.
- Keep the shared `mobile_apps_api_secret_key` during the transition; add `X-API-KEY` support (already implemented in CI3) so the new and old backends accept the same clients.
- Introduce per-user tokens (Laravel Sanctum) as an **additive** authentication path. Never remove the shared-key path until the published app has shipped a token-aware release.
- Version by URL, not by header. Never break an installed app.

---

## 6. Migration strategy — phased strangler

Route ownership moves prefix by prefix. At every point a single request is served by exactly one application.

| Phase | Duration (rough) | What moves | Exit criteria | Rollback |
|---|---|---|---|---|
| **0. Baseline** | done | Hardening, tests, secrets in `.env`, updater disabled | All five suites green; backup/restore drill passes | `git revert` per commit |
| **1. Read-only catalogue** | 3–4 weeks | Laravel skeleton, same DB, `/movies`, `/watch/*`, `/search`, `/genre/*`, `/year/*` | Byte-comparable titles/meta/canonical/JSON-LD on a sample; no CI3 writes on these routes | Proxy sends the prefix back to CI3 |
| **2. Accounts and subscriptions** | 3–4 weeks | Login, signup, profile, password reset, plans, entitlements | Login works for Argon2id **and** legacy MD5 users; session cookies separated; no logout storm | Keep CI3 auth routes live; flip cookie name back |
| **3. Admin write paths** | 4–6 weeks | CRUD modules one at a time, imports, moderation, reports | Every write action covered by an integration test; CSRF and policies enforced; audit log | Per-module route flag |
| **4. Mobile API** | 2–3 weeks | `/rest-api/*`, `/api/*` re-implemented as adapters | Contract suite passes for every endpoint; response diff clean | Proxy the prefix back to CI3 |
| **5. Retire CI3** | 1–2 weeks | Remove CI3, old sessions, updater, vendored libraries | No traffic to CI3 for 30 days; archives and logs retained | Re-deploy the last CI3 release image/tag |

Golden rules:

1. **Never rewrite a row twice.** One database, one owner per table at a time.
2. **No destructive schema change** while both applications are live.
3. **Every route has an owner.** The proxy configuration is the source of truth.
4. **The contract suite runs against both applications** before a prefix flips.
5. **Feature flags, not big-bang releases.**

---

## 7. Compatibility risks

| Risk | Impact | Mitigation |
|---|---|---|
| SEO URLs change | Loss of rankings and traffic | Mirror every route and add redirects only after the new route returns identical canonical/meta output. |
| Mobile JSON changes | Installed apps break silently | Contract tests; additive changes only; keep all three API generations until app telemetry shows retirement. |
| Session/cookie collision | Mass logout during cutover | Different cookie name and Redis namespace for Laravel; run both auth stacks in parallel. |
| Password hash mismatch | Users locked out | Custom provider that verifies Argon2id and MD5, then rehashes; test with a fixture account, never a real user. |
| MyISAM tables | Partial writes, no transactions | Convert to InnoDB in a measured maintenance window with a verified backup. |
| Payments | Double credit or missed renewal | Idempotency keys, webhook signature verification, replay tests, a reconciliation report. |
| File paths / uploads | Broken posters and playback | Keep `uploads/` as a shared volume until object storage is proven; keep the same public paths. |
| Timezone and date handling | Wrong expiry dates | Store UTC, render in the site timezone, test around midnight and DST. |
| Database credentials in git history | Ongoing credential exposure | **Rotate every secret** listed in `docs/runbooks/secret-rotation.md`; history rewriting is optional and not a substitute for rotation. |

---

## 8. Rollback plan

- **Per prefix:** the reverse proxy config is versioned; reverting one block returns that prefix to CI3 in seconds.
- **Per module:** feature flags live in configuration, not code, so an admin can disable a migrated module without a deploy.
- **Per release:** every phase ships as an immutable tag; the previous tag remains deployable.
- **Database:** take a verified logical backup before every migration and before any MyISAM→InnoDB or charset change. Rehearse a restore, not just a backup.
- **Sessions:** keeping the CI3 session table and cookie intact means a rollback does not force every user to log in again.
- **Drill:** once per phase, flip a non-critical prefix to CI3 and back during low traffic, and record the time-to-recover.

---

## 9. Effort and operating cost

- **Engineering:** approximately 14–20 person-weeks for Phases 1–4 for one senior PHP engineer with part-time review, assuming the existing tests and database are used as the contract. Phase 5 adds 1–2 weeks.
- **Infrastructure:** one VPS with 4 vCPU / 8 GB RAM is enough for the application, Redis and queue workers at current scale. Object storage and CDN costs appear only in Phase 3.
- **Ongoing:** a supported framework removes the "patch an EOL CMS ourselves" tax; the budget shifts from emergency patching to product work.
- **Not included:** a frontend redesign, a native app rewrite, video transcoding, or a CDN migration. Those are separate decisions.

---

## 10. Gate — do not start migration until

- [ ] All five suites are green against staging (`tests/run.php`, `tests/security/check.php`, HTTP smoke, integration, browser).
- [ ] Every secret in `docs/runbooks/secret-rotation.md` has been rotated, including the database password that appears in git history.
- [ ] A backup has been restored into staging and the application served from it.
- [ ] The reverse proxy, staging host and deployment pipeline exist and have served at least one dry-run release.
- [ ] Route ownership for Phase 1 is written down and agreed.
- [ ] The mobile app team has confirmed the contract suite covers every endpoint the published app calls.
- [ ] There is a named owner for the migration and a rollback drill date.

Until then, the correct work is **hardening and operating the current application**, not rewriting it.

---

## 11. Immediate next steps (no migration)

1. Rotate the database, API, cron, payment and encryption secrets; treat the current values as compromised.
2. Add a Content-Security-Policy in report-only mode and fix the highest-volume violations.
3. Replace the remaining vendored Guzzle 6.5.3 code path or isolate it behind a maintained client.
4. Automate database backups and test a restore monthly.
5. Add uptime, error-rate and failed-login alerting.
6. Keep the dependency inventory current and review it each quarter.

---

## Sources

- Laravel release notes and support policy: <https://laravel.com/docs/releases>
- PHP supported versions: <https://www.php.net/supported-versions.php>
- CodeIgniter 3 status: <https://codeigniter.com/userguide3/>
- MySQL 8.4 LTS: <https://dev.mysql.com/doc/refman/8.4/en/>
- OWASP ASVS (verification target for the new build): <https://owasp.org/www-project-application-security-verification-standard/>
