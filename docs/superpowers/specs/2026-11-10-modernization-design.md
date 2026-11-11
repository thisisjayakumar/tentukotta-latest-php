# Tentukotta Modernization — Design

**Date:** 2026-11-10
**Status:** Approved for execution (design + Phase 0/1 plan)
**Owner:** Engineering
**Target platform:** Laravel 13, PHP 8.5, Hostinger Premium Web Hosting

---

## 1. Intent

Modernize the live movie-streaming application without breaking the public SEO URLs, the
admin workflow, the three generations of mobile API, or payments.

The application is a working content business on an end-of-life framework. The goal is not a
rewrite for its own sake; it is to move the code onto a supported foundation with enforceable
boundaries, a real test harness, and a UI that can be changed safely.

### What success looks like

- One repository, one deployable artifact, one documented release process.
- Every security defect found in the audit is closed and covered by a test.
- New code is PSR-4/PSR-12, `declare(strict_types=1)`, typed, and free of framework globals.
- The public site and admin are rebuilt on a shared design system, not per-page CSS.
- A route can be moved from CI3 to Laravel, verified, and rolled back in minutes.
- A new developer or agent can find the owner of any behavior in under five minutes.

### Non-goals (this program)

- Native mobile app rewrite.
- Microservices, event sourcing, or a headless SPA.
- Video transcoding pipeline or CDN migration before the backend is stable.
- Replacing the database engine or re-seeding catalogue content.

### Anti-goals (would count as failure)

- A big-bang cutover that takes the site down.
- A visual redesign implemented twice (once in CI3, once in Blade).
- "Modern" code that still calls `$this->db` from a controller.
- Shipping features on CI3 after the migration starts, creating double work.
- Rewriting git history or rotating secrets without an off-repo backup.

---

## 2. Constraints

| Constraint | Reality | Consequence |
|---|---|---|
| Hosting | Hostinger **Premium Web Hosting** | SSH + Composer 2 available; no Redis; no long-running workers; fixed `public_html` docroot |
| PHP | **8.5** selected | Laravel 13 supports 8.3–8.5; CI3 must be smoke-tested on 8.5 before cutover |
| Database | MySQL 8.x, one schema, 43 tables | Shared by both apps during the strangler; additive migrations only |
| Runtime | Apache/LiteSpeed + `.htaccess` | Route ownership is implemented as rewrite rules, not a reverse proxy |
| Sessions/cache | No Redis | Database driver for sessions, cache, queues; cron-driven workers |
| Media | Local `uploads/` (62 MB today) | Keep local disk + Cloudflare; object storage deferred |
| Mobile | Three API generations in production | Contracts are frozen; additive changes only |

### Verified Hostinger facts (2026-11-10)

- SSH is available on Premium Web and higher.
- Composer 2 is pre-installed on Premium Web.
- PHP 8.3+ is offered; PHP 8.5 is the selected runtime.
- Cron runs at one-minute granularity, which is enough for `schedule:run` and a short queue worker.
- No Redis add-on is assumed; the design must work on the database driver.

---

## 3. Approaches considered

| Approach | Trade-off | Decision |
|---|---|---|
| **A. Modernize in place on CI3** | Fast, low risk, keeps the site live; cannot deliver PSR-4, strict typing, DI, or testable services | Rejected as an end state; used only for bridge fixes |
| **B. Laravel 13 modular monolith, strangler** | Supported framework, typed architecture, Blade + Tailwind, queues, migrations; 4–6 months; shared-hosting compromises | **Chosen** |
| **C. Migrate to CodeIgniter 4** | Namespaces/PSR-4 within the CI family; almost the same effort as B; smaller ecosystem and fewer batteries | Rejected |

The decisive point: the requested standards (PSR compliance, strict typing, separation of
concerns) are not achievable inside CI3. The framework migration is the refactor, not a later
optimization.

---

## 4. Target architecture

### 4.1 Shape

A modular monolith: one Laravel application, one database, explicit module boundaries.

```
app/
  Modules/
    Content/        videos, episodes, seasons, genres, countries, people, live TV
    Accounts/       users, roles, devices, Firebase identities
    Subscriptions/  plans, entitlements, renewals
    Billing/        Stripe / PayPal / Razorpay adapters + webhooks
    CatalogApi/     v100, v130 and legacy response adapters
    Admin/          CRUD, moderation, imports, reports
    Frontend/       Blade public pages
  Support/          shared value objects, clocks, IDs, money
```

Each module owns its actions, form requests, policies, models and migrations. A module never
reads another module's models directly; cross-module calls go through a small service interface.

### 4.2 Layer contract

```
HTTP (Controller + FormRequest)
        ↓
Action / Service (business rules, transactions, gateway calls)
        ↓
Repository / Eloquent model (persistence only)
        ↓
MySQL

Controller → ViewModel/DTO → Blade (presentation only)
```

Enforced by review and PHPStan:

- Controllers: ≤150 lines, no queries, no `$_POST`, no `echo`.
- Form Requests: every write is validated at the boundary.
- Services: idempotent, typed, unit-tested.
- Models: relationships and scopes only.
- Blade: escaped output by default; no business logic.
- No static helpers that reach into the container or the database.

### 4.3 Runtime topology on Premium Web

```
Cloudflare (DNS, TLS, WAF)
        │
public_html/.htaccess  ── migrated prefixes ──►  laravel/public/index.php
        │                                              │
        └──────────── everything else ──►  CI3 index.php
                                                       │
                                      MySQL 8 (shared) ┘
                                      database queue/cache/sessions
                                      cron: schedule:run + queue:work --stop-when-empty
```

- Laravel lives outside the docroot; only `public/` is exposed (symlink or `/laravel` fallback).
- CI3 and Laravel share the same database during the strangler.
- Cookies use different names (`ci_session` vs `tentukotta_lv`) to avoid a logout storm.
- Route ownership is a versioned `.htaccess` block and a `docs/architecture/route-ownership.md`
  table; one request is served by exactly one application.

### 4.4 Data rules

- Additive migrations only while CI3 is live (new tables and nullable columns; no renames/drops).
- Convert `download_link` from MyISAM to InnoDB before relying on transactions/foreign keys.
- Move to `utf8mb4` in a measured maintenance window; test emoji and non-Latin titles.
- Passwords: verify Argon2id and legacy MD5, rehash on successful login, never mass-reset.
- Payments: webhook signature verification is the only grant path; idempotency keys on every
  gateway call; a reconciliation report matches grants to gateway transactions.
- Money and ratings use `DECIMAL`, not `FLOAT`/`DOUBLE`.

### 4.5 API contract

- Preserve `/rest-api/v100/*`, `/rest-api/v130/*`, `/api/*` field-for-field.
- Contract tests are the arbiter; no endpoint changes without a passing diff.
- Keep the shared mobile key during the transition; add per-user tokens (Sanctum) additively.
- Derive identity from the token, never from a caller-supplied `user_id`.
- Version by URL; never break an installed app.

---

## 5. Design system

### 5.1 Stack

- **Tailwind CSS 4** with CSS-first `@theme` tokens.
- **Alpine.js 3** for local interactions; no jQuery on new public pages.
- **Vite** for builds; hashed assets, self-hosted fonts.
- Blade components for the public site; the same tokens drive the rebuilt admin.

### 5.2 Tokens

| Family | Specification |
|---|---|
| Color | Brand primary/secondary (admin-configurable CSS variables, not compiled classes), neutral 50–950, semantic success/warning/danger/info, surface/elevated/overlay, text primary/secondary/muted |
| Type | 12/14/16/18/20/24/30/36/48 px; body 16/1.6; headings 1.2; tabular numerals for stats |
| Spacing | 4 px base: 4/8/12/16/24/32/48/64/96 |
| Radius | 4/8/12/16/full |
| Elevation | sm/md/lg; 2 px focus ring with offset |
| Motion | 150/200/300 ms; standard easing; honor `prefers-reduced-motion` |
| Breakpoints | 640/768/1024/1280/1536 |
| Dark mode | `[data-theme="dark"]` + `prefers-color-scheme`; contrast ≥4.5:1 |

### 5.3 Components

Public: `AppShell`, `Header`, `MegaMenu`, `SearchCombobox`, `HeroSlider`, `MediaCard`,
`CarouselRow`, `FilterBar`, `Pagination`, `Tabs`, `PlayerShell`, `EpisodeList`, `AuthForm`,
`Modal`, `Toast`, `EmptyState`, `Skeleton`, `Footer`.

Admin: `AdminShell`, `DataTable`, `FormField`, `ConfirmDialog`, `StatCard`, `ImportPanel`.

Every component defines: purpose, props/slots, keyboard behavior, loading/empty/error states,
and a visual regression snapshot.

### 5.4 Accessibility and performance budgets

- WCAG 2.2 AA: visible focus, labelled inputs, alt text, 44 px targets, ARIA tabs/modals,
  captions, reduced-motion support.
- LCP < 2.5 s on 4G, CLS < 0.1, INP < 200 ms.
- Initial JS < 200 KB gzip, CSS < 60 KB gzip.
- AVIF/WebP with `srcset`; lazy-load below the fold; no render-blocking third-party JS.
- Remove duplicate video.js 5/6, three icon fonts, and the 19-stylesheet home page.

### 5.5 Rollout rule

Define tokens and components once, then implement them on the Laravel read-model. Do not
redesign all 75 CI3 views and rebuild them again in Blade. On CI3, ship only cheap reversible
wins: escape output, fix contrast/labels, lazy-load posters, remove dead assets.

---

## 6. DX / AX

### 6.1 Canonical repository

One repository at the project root contains the app, tests, docs and deploy tooling.
`_public_html` is the legacy web root inside it; its nested `.git` was removed after verified
tree parity. User media is not tracked.

```
/  app/ (Laravel)  _public_html/ (CI3 bridge)  docs/  tests/  deploy/  tools/
   AGENTS.md  README.md  composer.json  package.json  .env.example  Makefile
```

### 6.2 Agent experience

- `AGENTS.md` / `CLAUDE.md`: architecture map, invariants, exact commands, “never touch” list,
  and the route-ownership table.
- One `README.md` per module: purpose, public interface, tables, quirks.
- ADRs in `docs/adr/`; one page per irreversible decision.
- Deterministic commands: `make up|test|analyse|lint|build|deploy-dry-run`.
- Seeders and fixtures for every domain so tests do not depend on production data.
- No magic helpers; errors name the fix.

### 6.3 Quality gates

- PHPStan level 8 on new code (level 5 ratchet on legacy), PHP-CS-Fixer/PSR-12.
- `declare(strict_types=1)` in every new PHP file.
- Pest/PHPUnit: service unit tests, feature tests per route, contract tests per API endpoint.
- Playwright: one happy path per critical journey plus visual snapshots.
- GitHub Actions: lint → static analysis → unit → integration on a seeded MySQL → browser.
- Structured logs with request ID, `/up` health check, failed-job alerts, login-anomaly alerts.

---

## 7. Delivery model

| Phase | Outcome | Gate |
|---|---|---|
| 0 | One repo, clean history, backups, baseline tag | Tree parity proven; deploy dry-run passes |
| 1 | Stop-the-bleeding security fixes | Each fix has a test; no unauthenticated state change |
| 2 | Contract harness | Every public/API route covered against CI3 |
| 3 | Laravel skeleton + design system | Login works for Argon2id and MD5; demo page approved |
| 4 | Public read path | Byte-comparable SEO/player output on a sample |
| 5 | Accounts/subscriptions | No logout storm; sandbox payments pass |
| 6 | Admin write paths | Every write has a feature test; audit log live |
| 7 | Mobile API | Contract suite passes for every endpoint |
| 8 | Retire CI3 + optimize | 30 days zero CI3 traffic; restore drill passed |

Each phase ships behind a flag, is reversible, and has a named owner.

---

## 8. Risks

| Risk | Mitigation |
|---|---|
| PHP 8.5 breaks CI3 | Run the CI3 smoke suite on 8.5 before flipping the runtime; keep 8.3 available in hPanel |
| Shared hosting cannot run queues well | Short, idempotent jobs; cron `queue:work --stop-when-empty`; chunked imports; database driver |
| Symlinked docroot rejected | Fallback to `/laravel` subdirectory with deny rules; test in Phase 3 |
| SEO loss during route flips | Canonical/meta/JSON-LD diff before flipping; proxy/rewrite back in seconds |
| Mobile contract drift | Contract tests against both apps; additive changes only |
| Payments double-credit | Webhook-only grants, idempotency keys, reconciliation report |
| Session collision | Separate cookie names and session tables; rollback keeps CI3 sessions |
| Uploads growth | Growth alarm; object storage decision at Phase 8 |
| Secret leakage | Env-only, rotation runbook, secret scanning in CI, no `.env` in artifacts |

---

## 9. Decisions taken

- **Laravel 13 on PHP 8.5** over staying on CI3 or moving to CI4.
- **Premium Web Hosting** is the deployment target; no Redis, database driver for queues/cache.
- **Path-prefix strangler** via `.htaccess`, not a reverse proxy.
- **Tailwind + Alpine + Blade** for the public site; admin rebuilt on the same tokens.
- **One repository**, outer history canonical; nested repo removed after parity check.
- **Additive-only database and API changes** until CI3 is retired.

## 10. Left to the builder

- Module-level class names and folder layout inside `app/Modules`.
- Exact Blade component API (props vs slots) per component.
- Queue job granularity and retry/backoff values.
- Whether to use Sanctum or a custom token table for mobile, after the contract audit.
- The route-flip order inside Phase 4, driven by the contract-suite coverage.

---

## Appendix — evidence

- CI3 3.1.13, ~98k LOC in `application/`, `Admin.php` 5,085 LOC / 106 functions.
- 212 views, 4,716 raw `echo`, 7 `html_escape`, 3,005 `$this->db` calls.
- Home page loads 19 render-blocking stylesheets; Bootstrap 3.3.6, jQuery 2.2.4.
- Live defects: public cron counter reset, no `status` check on login, unverified Firebase
  login, mobile IDOR, stored XSS, 106 referrer redirects, `mt_rand()` keys, unbounded uploads.
