# Go-Live Stabilization + CI3 UI/UX Refresh Plan

> **Status:** Active (replaces Phase 2–8 execution for now).  
> **Date:** 2026-11-11  
> **Scope:** Stage the existing CodeIgniter 3 app on Hostinger for production-like use with minimal code changes, plus a targeted UI/UX refresh on CI3.  
> **Phases 2–8 remain intact** for later (Laravel strangler, design system rebuild, API migration, CI3 retirement): see [`2026-11-10-modernization-design.md`](../specs/2026-11-10-modernization-design.md), [`../../architecture/target-architecture-2026.md`](../../architecture/target-architecture-2026.md), and [`2026-10-31-architecture-modernization.md`](2026-10-31-architecture-modernization.md). Do not delete or rewrite them; resume when stabilization/UI goals are met.

---

## 1. Intent

- **Now:** get the current CI3 app (`_public_html/`) running correctly on Hostinger staging (mirror of production) with minimal, reversible changes.
- **Now:** make the core experience modern-looking and mobile-friendly (layout, navigation/search, key pages) without a framework migration.
- **Later:** execute Phases 2–8 (contract harness, Laravel 13 modular monolith, design system, strangler cutover) as previously planned.

**First-cut acceptance (confirmed):** browsable + user login + watch/playback + admin login & basic admin access work on staging. Payments, SMTP/email delivery, and mobile API are **not** day-one blockers for this cut (verify they don’t break core flows; full validation deferred).

## 2. Constraints & guardrails

- **Minimal code changes:** prefer config, hosting, and targeted view/CSS/JS edits over refactors or rewrites.
- **No migration now:** do not introduce Laravel, do not move route ownership, do not do destructive DB changes.
- **Preserve behavior/URLs:** keep existing routes and controller contracts working (`application/config/routes.php`, `.htaccess` rewrites).
- **No secret leakage:** `.env` and credentials are server-only; never commit secrets, DB dumps, or `uploads/` media.
- **CI3/PHP compatibility:** CI3 3.1.13 may be fragile on PHP 8.5; prefer the highest PHP version that passes smoke on Hostinger (8.3 is the safer baseline). Document the chosen version.
- **UI refresh, not full redesign:** improve the critical path on the existing theme stack; do not attempt to restyle all ~212 views or introduce a new build pipeline (Tailwind/Vite) in this phase. A full token/component system remains Phase 3/4 work.

## 3. Environment assumptions (provided)

- hPanel + SSH access available.
- Production DB exists; a staging DB is ready to deploy to.
- Target: Hostinger staging mirroring production; promote to production after staging verification.

---

## Phase S — Go-Live Stabilization (critical/high blockers)

> Do these in order. Each step has a verification check. Stop and fix blockers before moving on.

### S1. Runtime & extensions
- [ ] In hPanel, set PHP to a CI3-compatible version (start at **8.3**; only move to 8.5 if S7 smoke passes). 
- [ ] Enable required extensions: `mysqli`, `mbstring`, `curl`, `gd`, `openssl`, `json`, `fileinfo` (and `intl` if available).
- [ ] Confirm `php -v` via SSH matches.
- **Verify:** a simple PHP info/request to `_public_html/index.php` does not fatal on missing extensions.

### S2. Deploy code to staging
- [ ] Deploy the current repo state to staging (git pull/clone or upload) so docroot serves `_public_html/`.
- [ ] Ensure `_public_html/.htaccess` is active (rewrites on) and `application/`, `system/`, `db_backup/` remain non-public per existing rules.
- [ ] Do not deploy `.env` from git; do not copy tracked junk; keep `uploads/` media as needed.
- **Verify:** `https://<staging-host>/` reaches the app front controller (not a directory listing or 404).

### S3. Server configuration (`.env`)
- [ ] Create `.env` **on the server** (e.g. repo root or path loaded by `_public_html/index.php` → `env_load`) with:
  - `CI_ENV=production`
  - `BASE_URL=https://<staging-host>/` (trailing slash)
  - `DB_HOSTNAME`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` (staging DB)
  - `ENCRYPTION_KEY` (32+ hex chars, e.g. `php -r "echo bin2hex(random_bytes(16));"`)
  - `SESSION_EXPIRATION=7200` (or current policy)
  - `ALLOW_WEB_UPDATER=0`
- [ ] Confirm file permissions restrict `.env` to the deploy user/web user as appropriate.
- **Verify:** app boots, no DB connection error, sessions/cookies issue on the staging domain.

### S4. Database
- [ ] Import/copy required data into the **staging DB** (from prod dump or agreed dataset). Do not point staging at prod DB.
- [ ] Verify charset/collation compatibility and that core tables read correctly.
- **Verify:** home/catalog pages load data; no “Unable to connect to your database server” errors.

### S5. Writable paths & media
- [ ] Ensure writable directories for the web user: `_public_html/uploads/` (and subdirs used for posters/videos/thumbs) and any session/cache/log paths CI3 uses in this setup.
- [ ] Sync or symlink existing media needed for browse/watch on staging if not present.
- **Verify:** images/posters render; watch page can load/play available media; no permission-denied errors in logs.

### S6. Routing, base URL, HTTPS, cron
- [ ] Confirm HTTPS works on staging and `BASE_URL` matches the canonical staging URL (avoid mixed content / wrong redirects).
- [ ] Verify `.htaccess` rewrites: clean URLs work (`/movies`, `/search`, `/watch/...`, admin routes).
- [ ] Configure Hostinger cron for required CI3 cron endpoints using the configured `cron_key` (from `config`/env as applicable). Ensure cron calls are authorized and not publicly abusable (Phase 1 guards in place).
- **Verify:** key routes resolve; cron endpoint responds correctly when called with key (and rejects without, per S1 hardening).

### S7. Smoke verification — first-cut gate (must pass)
Run manually on staging (HTTP/API test suites optional if `STAGING_URL` is wired, but not required to proceed):
- [ ] **Public:** home loads, navigation works, browse/listing pages render, search returns results.
- [ ] **Watch:** a known title opens and playback starts/loads.
- [ ] **User auth:** login, logout, session persists across a few pages.
- [ ] **Admin:** admin login works; dashboard or a basic read/list screen loads.
- [ ] **Regression sanity:** no PHP fatal/500 on the above; assets (CSS/JS/images) load.
- **Exit:** all four core flows (browsable, login, watch, admin) work. Record staging URL, PHP version, commit hash, and any known non-blocking issues.

### S8. Minimum secrets hygiene (before exposing to real users)
- [ ] Set/rotate on **staging** at minimum: DB password (staging user), `ENCRYPTION_KEY`, and `cron_key`; update any dependent config/DB rows.
- [ ] Review `docs/runbooks/secret-rotation.md`: rotate additional secrets (payment, Google, SMTP, OneSignal, TMDB, mobile API key) as required before broader/public use or if exposure is suspected. Treat values found in history as compromised.
- [ ] **Deferred for this cut:** full third-party rotation can follow S7 if those integrations aren’t in use yet—but do not go fully public with known-leaked credentials.
- **Verify:** app functions after rotation; rotated values not committed.

**Stop point (stabilization):** staging is usable for the agreed core flows. Promote to production only after repeating S1–S8 (or validating parity) on production with a rollback plan (previous release + DB backup).

---

## Phase U — CI3 UI/UX Refresh (targeted, mobile-friendly)

> **Goal:** a noticeably more modern, mobile-friendly experience on the critical path, implemented on the existing CI3/theme stack with minimal risk. This is **not** the Phase 3 Tailwind/Blade design system.

### U0. Scope & approach
- **In scope (P0):** shared layout chrome and the core journeys — header/nav + search bar, footer, home, search/results, movies/listing & category/genre/year pages as touched, watch page, login/auth pages, and basic admin shell readability on mobile/tablet.
- **Out of scope (defer):** restyling every view, new frontend build tooling, SPA rewrite, visual redesign in Laravel, heavy JS rewrites, pixel-perfect brand system. Capture follow-ups as issues/notes.
- **Approach:** responsive CSS overrides/refactors, simplify/modernize existing Bootstrap/theme styles where safe, improve typography/spacing/tap targets, ensure search and navigation are usable on small screens, reduce obvious breakage/overflow. Reuse existing assets; avoid large new dependencies.

### U1. Baseline & inventory
- [ ] Identify active theme entrypoints: `application/views/theme/default/*` (layout/header/footer, `index.php`, `search_results.php`, `movies.php`, `watch.php`, etc.), `assets/theme/default/*`, and auth views.
- [ ] Capture quick before screenshots/notes for P0 pages on mobile + desktop widths (staging or local).
- **Verify:** a short list of files to touch for P0 is agreed.

### U2. Global layout, navigation & search (P0)
- [ ] Make header/nav responsive (collapse/menu usable on mobile), ensure logo/nav/search don’t overflow.
- [ ] Modernize **search bar** UX: visible/accessible on mobile, clear input/button, correct action to existing search route (`/search` / `home/search`), reasonable sizing and focus states.
- [ ] Tidy footer and base typography/spacing for readability.
- **Verify:** on 360px–430px and desktop widths, nav and search are usable; no horizontal scroll on chrome.

### U3. Core public pages (P0)
- [ ] **Home & listings** (`index`, movies/genre/country/year/category views as applicable): responsive grids/cards, readable titles/metadata, tap-friendly controls, lazy-friendly images where easy.
- [ ] **Search results:** clear layout, empty/loading states readable, mobile-friendly.
- [ ] **Watch page:** player/container responsive, metadata/episode/actions stack correctly on mobile.
- [ ] **Auth (login/signup/forgot as present):** clean, centered/mobile-friendly forms.
- **Verify:** core flows (browse → search → open title → watch, login) are usable on mobile and desktop.

### U4. Admin readability (P0, minimal)
- [ ] Ensure admin login and primary admin shell/navigation are usable on tablet/mobile (basic responsiveness/readability), without restructuring admin modules.
- **Verify:** admin login and a basic list/dashboard screen are operable on a small viewport.

### U5. QA, performance hygiene & polish
- [ ] Cross-check key pages on Chrome mobile viewport + one real device if available; fix layout breakages/overlaps.
- [ ] Quick wins only: avoid adding render-blocking assets; compress/size obvious oversized images if trivial; keep changes minimal.
- [ ] Re-run S7 smoke after UI changes.
- **Verify:** no regressions to browsable/login/watch/admin; improved mobile usability on P0 pages.

**Stop point (UI refresh):** P0 pages are modernized and mobile-friendly on CI3. Further visual/system work is handed off to Phase 3/4 when migration resumes.

---

## 4. Deferred / not in this plan

- Phase 1 **mobile API IDOR** token enforcement (Phase 7, after token-aware app release).
- **CSP** report-only rollout and violation review.
- Full **secret rotation** of every third-party integration beyond S8 needs.
- HTTP/API contract automation on staging (run when `STAGING_URL` + data fixtures are available).
- **Phases 2–8:** route inventory & contract harness, Laravel 13 skeleton + Tailwind/Blade design system, public read-path migration, accounts/subscriptions migration, admin migration, mobile API migration, CI3 retirement & optimization — **unchanged and available to start next week** per existing docs.

## 5. Definition of done

- [ ] S1–S7 complete on staging; S8 minimum hygiene applied.
- [ ] U1–U5 complete for P0 scope (or explicitly descoped items recorded).
- [ ] Staging details recorded: URL, PHP version, commit hash, DB used, known issues.
- [ ] No secrets/`.env`/uploads committed; Phase 2–8 docs left intact.
- [ ] Go/no-go note for production promotion (what to repeat, backups/rollback).
