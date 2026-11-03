# Security Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the gaps left after the 2026-10-31 hardening pass — unmanaged secrets, a post-auth web-updater RCE, an SSRF sink, timing-unsafe API key checks, missing HTTP security headers, and the absence of any unit/integration/browser test harness — without changing public URLs, the admin panel workflow, or the mobile REST response contract.

**Architecture:** Add one dependency-free test harness (`tests/run.php`) so every change is driven by a test that fails first. Then move deployment secrets out of version control behind an `env()` loader, disable the web updater by default while keeping a guarded path, put remote-fetch behind a URL validator, and add defence-in-depth headers. No framework migration happens in this plan; the migration recommendation is a separate document.

**Tech Stack:** PHP 8.3.6, CodeIgniter 3.1.13 (EOL), MySQLi/MySQL 8, Apache 2.4, curl, Playwright 1.64 (system Chrome).

**Spec:** `/home/jay/Downloads/tentukottasite/SECURITY-AUDIT.md` plus the remaining findings recorded in the ledger.

## Global Constraints

- Preserve every existing public URL (`/watch/...`, `/movies`, `/search`, `/rest-api/...`, `/legacy-api/...`) and every REST response field the mobile app reads. API changes must be additive or redactive-only.
- No new Composer dependencies. Tests use plain PHP, curl and Playwright already available on the host.
- Secrets are never committed. The local `.env` is git-ignored; `.env.example` contains placeholder names only.
- Each task ends in a green run of `php tests/security/check.php` and `php tests/run.php`, plus one commit. Every change is revertible with `git revert`.
- Commands run from `/home/jay/Downloads/tentukottasite` unless stated otherwise.
- Local staging remains `http://localhost:8080` (Apache docroot `_public_html`).

## Review Focus

1. **Existing deployment without `.env`** — the app must still boot using safe defaults, or fail with a clear message, never silently connect to the wrong database.
2. **Mobile client sending `api_secret_key` as GET/POST param** — must keep working; `X-API-KEY` is additive.
3. **Admin using the updater page** — must receive a clear "disabled" message rather than a PHP fatal or a silent no-op.
4. **Image fetch from TMDB/CDN** — public HTTPS URLs must still download; loopback and RFC1918 targets must be refused.
5. **`/admin/*` after header changes** — headers must not break the admin AJAX flows.

---

### Task 1: Dependency-free unit test harness

**Files:**
- Create: `tests/run.php`
- Create: `tests/bootstrap.php`
- Create: `tests/unit/password_test.php`
- Create: `tests/unit/env_test.php`
- Create: `tests/unit/safe_redirect_test.php`
- Modify: `tests/security/README.md`

**Interfaces:**
- Produces: `php tests/run.php` → runs every `tests/unit/*_test.php`, prints one `PASS`/`FAIL` line per assertion, exit `0`/`1`, summary line `N passed, M failed`.
- Produces: `tests/bootstrap.php` defines `BASEPATH`, `APPPATH`, `FCPATH` and stubs `get_instance()`, `base_url()`, `redirect()`, `html_escape()` so CI libraries/helpers can be loaded without booting CI.
- Produces: `test(string $name, callable $fn)`, `assert_same($expected, $actual, string $message = '')`, `assert_true($value, string $message = '')`, `assert_false(...)`, `assert_throws(callable $fn, string $class)`.

- [x] **Step 1: Write the failing harness self-test**

`tests/unit/harness_test.php` asserts `assert_same(1, 1)` passes and that a deliberately failing assertion is reported. Run `php tests/run.php` and watch it fail because the runner does not exist yet.

- [x] **Step 2: Implement `tests/run.php` + `tests/bootstrap.php`** until the self-test passes.

- [x] **Step 3: Add `password_test.php`** — hash is not plaintext, verify accepts the right password, rejects the wrong one, legacy 32-hex MD5 verifies, `needs_rehash()` true for MD5 and false for a fresh Argon2/bcrypt hash, `random()` is 64 hex chars and unique.

- [x] **Step 4: Add `env_test.php`** — parses `KEY=value`, quoted values, comments, blank lines, ignores malformed lines, does not overwrite an already-set process variable, `env()` returns the default when missing and preserves an empty string when set.

- [x] **Step 5: Add `safe_redirect_test.php`** — records the URL passed to `redirect()`; rejects `https://evil.test`, `//evil.test`, `javascript:alert(1)`, backslash and control characters; allows `/movies` and `watch/abc`.

- [x] **Step 6: Run and commit**

```bash
php tests/run.php
php tests/security/check.php
git add tests
git commit -m "test: add dependency-free unit test harness"
```

---

### Task 2: Environment-driven configuration and secret removal

**Files:**
- Create: `_public_html/application/config/env.php`
- Create: `.env.example`
- Create (untracked): `.env`
- Modify: `_public_html/index.php` (load env before config)
- Modify: `_public_html/application/config/database.php`
- Modify: `_public_html/application/config/config.php`
- Modify: `_public_html/application/config/rest.php`
- Modify: `tests/unit/env_test.php`
- Modify: `tests/security/check.php`

**Interfaces:**
- `env_load(string $path): void` parses a dotenv file without overwriting real process env vars.
- `env(string $key, $default = null)` reads process env, then `$_ENV`/`$_SERVER`, then the default.
- `env_bool(string $key, bool $default = false)` treats `1/true/yes/on` as true and `0/false/no/off/empty` as false.
- Config keys: `DB_HOSTNAME`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`, `BASE_URL`, `ENCRYPTION_KEY`, `SESSION_EXPIRATION`, `CI_ENV`.

- [x] **Step 1: Add failing static assertions to `tests/security/check.php`**: no literal DB password in `database.php`, no literal `encryption_key` value, `db_debug` literally `FALSE`, no `rest_valid_logins` credential pair, `index.php` loads `config/env.php`, `.env.example` exists, `.env` is git-ignored.

- [x] **Step 2: Implement `env.php` and the `index.php` hook.**

- [x] **Step 3: Create the local `.env`** with the current working staging values (never committed), plus `.env.example` with placeholders.

- [x] **Step 4: Move the DB, base URL, encryption key, session lifetime and environment flag to `env()`.** Keep behaviour identical when the variables are present.

- [x] **Step 5: Remove the hardcoded REST basic-auth pair** and set `db_debug` explicitly `FALSE`.

- [x] **Step 6: Verify** — `php tests/run.php`, `php tests/security/check.php`, `curl -I http://localhost:8080/` returns 200, and a request to `/rest-api/v130/config` still fails auth as before.

- [x] **Step 7: Commit**

```bash
git add .env.example _public_html/application/config _public_html/index.php tests
git commit -m "fix(sec): load deployment secrets from the environment"
```

---

### Task 3: HTTP security headers and crawler policy

**Files:**
- Modify: `_public_html/index.php`
- Modify: `_public_html/robots.txt`
- Modify: `tests/security/check.php`
- Modify: `tests/security/http_smoke.sh`

**Interfaces:**
- Responses served by PHP carry `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy: same-origin`, and `Strict-Transport-Security` only when the request is HTTPS.
- `robots.txt` keeps `/admin/` disallowed and adds `/install/`, `/update/` and `/rest-api/`.

- [x] **Step 1: Add failing assertions** (static + HTTP smoke header check).
- [x] **Step 2: Implement the headers** in `index.php`, before any output, guarded so CLI runs are unaffected.
- [x] **Step 3: Update `robots.txt`.**
- [x] **Step 4: Verify with `curl -sI`** and the smoke script.
- [x] **Step 5: Commit** — `fix(sec): send baseline security headers`.

---

### Task 4: Disable the web updater by default and validate archives

**Files:**
- Create: `_public_html/application/libraries/Safe_zip.php`
- Create: `tests/unit/safe_zip_test.php`
- Modify: `_public_html/application/controllers/Updater.php`
- Modify: `tests/security/check.php`

**Interfaces:**
- `Safe_zip::extract(string $zipPath, string $destination): bool` — refuses path traversal (`..`), absolute paths, symlinks, more than 2 000 entries, entries larger than 50 MB, archives larger than 200 MB, and any entry whose extension is `php`, `phtml`, `phar` or `htaccess`.
- `Updater::process()` returns HTTP 403 with a message unless `env_bool('ALLOW_WEB_UPDATER', false)` is true; when enabled it still requires an admin session, a CSRF token, a `.zip` upload, and `Safe_zip`.

- [x] **Step 1: Write `safe_zip_test.php` first** — build a benign zip (passes) and a traversal zip (rejected) and an absolute-path zip (rejected) using `ZipArchive` and, where needed, a pre-built fixture created with Python. Watch them fail.
- [x] **Step 2: Implement `Safe_zip`.**
- [x] **Step 3: Add the updater static assertions** (no `require './update/`, no `mysqli_multi_query`, guard uses `ALLOW_WEB_UPDATER`).
- [x] **Step 4: Gate `Updater::process()`**, remove the raw `require` and `multi_query` from the default path, and load `Safe_zip` when enabled.
- [x] **Step 5: Verify** — unit suite, static suite, and `curl -X POST http://localhost:8080/updater/process` for an anonymous user does not reach the upload logic.
- [x] **Step 6: Commit** — `fix(sec): disable web updater by default and validate archives`.

---

### Task 5: Timing-safe legacy API keys and `X-API-KEY` support

**Files:**
- Modify: `_public_html/application/models/Legacy_api_model.php`
- Modify: `_public_html/application/controllers/Legacy_api.php`
- Modify: `tests/unit/legacy_api_key_test.php` (new)
- Modify: `tests/security/check.php`

**Interfaces:**
- `Legacy_api_model::check_mobile_apps_api_secret_key()` uses `hash_equals`, returns `false` when the config row is missing, and accepts only non-empty string input.
- `Legacy_api::_api_key(): string` returns `X-API-KEY` first, then `api_secret_key` from GET or POST; every method uses it.
- The legacy API contract is unchanged: the same `api_secret_key` parameter still works.
- `find_in_set` inputs in `Legacy_api_model` are cast to `(int)` before interpolation.

- [x] **Step 1: Write failing tests.** Static: no `== $api_secret_key`; controller mentions `X-API-KEY` and `_api_key()`; every `api_secret_key` read goes through `_api_key()`. Unit: extract the comparison into a testable helper if a direct model test is impractical.
- [x] **Step 2: Implement** the comparison, the header accessor, and the mechanical replacement.
- [x] **Step 3: Cast numeric `find_in_set` inputs.**
- [x] **Step 4: Verify** with the unit + static suites and an HTTP call to `/legacy-api/test` using both the header and the parameter.
- [x] **Step 5: Commit** — `fix(sec): harden legacy API key handling`.

---

### Task 6: SSRF-safe remote image fetching

**Files:**
- Modify: `_public_html/application/helpers/custom_helper.php`
- Modify: `_public_html/application/models/Common_model.php`
- Create: `tests/unit/remote_url_test.php`
- Modify: `tests/security/check.php`

**Interfaces:**
- `is_private_ip(string $ip): bool` — true for loopback, RFC1918, link-local, CGNAT and reserved ranges.
- `is_safe_remote_url(string $url, ?callable $resolver = null): bool` — http/https only, no userinfo, no control characters, rejects numeric/hex hosts, resolves the host and refuses if any address is private.
- `Common_model::grab_image()` refuses unsafe URLs, verifies TLS, disables non-HTTP protocols, caps redirects at 3, caps download size and time, and does not create the destination file on failure.

- [x] **Step 1: Write `remote_url_test.php` first** — accept `https://example.com/a.jpg` (stub resolver returns `93.184.216.34`); reject `file:///etc/passwd`, `gopher://`, `http://127.0.0.1/`, `http://10.0.0.5/`, `http://169.254.169.254/`, `http://[::1]/`, `http://2130706433/`, `http://user:pass@example.com/`, and a host resolving to a private IP.
- [x] **Step 2: Implement the helpers.**
- [x] **Step 3: Rewrite `grab_image()`** on top of them.
- [x] **Step 4: Verify** the unit suite and a real TMDB image download through the staging app if a safe URL is available; otherwise assert the negative path.
- [x] **Step 5: Commit** — `fix(sec): block SSRF in remote image fetching`.

---

### Task 7: Integration and browser regression tests

**Files:**
- Create: `tests/integration/api_contract.sh`
- Create: `tests/browser/playwright.config.js`
- Create: `tests/browser/smoke.spec.js`
- Create: `tests/README.md`
- Modify: `tests/security/http_smoke.sh`

**Interfaces:**
- `STAGING_URL=http://localhost:8080 ./tests/integration/api_contract.sh` checks: security headers, `db_backup` denial, anonymous admin writes return 403, REST v130 without a key returns 401 JSON, legacy API without a key returns the legacy JSON error, REST with the key from the local DB returns 200, and public pages return 200.
- `npx playwright test --config=tests/browser/playwright.config.js` uses `/usr/bin/google-chrome`; covers home page render, `/movies`, `/search?q=...`, login page, and the admin redirect.

- [x] **Step 1: Write the integration script** and run it; fix any real regressions found.
- [x] **Step 2: Write the Playwright config/spec** and run it.
- [x] **Step 3: Document how to run each suite** in `tests/README.md`.
- [x] **Step 4: Commit** — `test: add integration and browser regression suites`.

---

### Task 8: Target architecture evaluation

**Files:**
- Create: `docs/architecture/target-architecture-2026.md`
- Modify: `docs/superpowers/plans/2026-10-31-architecture-modernization.md` (Laravel 12 → Laravel 13, PHP 8.5)

**Interfaces:**
- The document recommends a target stack with supported-version evidence, weighs Laravel vs. Symfony vs. staying on CI3, describes a phased strangler migration (API first, then auth, then admin), lists data/session compatibility risks, a rollback path, and an explicit "do not start yet" gate.

- [x] **Step 1: Write the evaluation** with the version/support facts already verified (Laravel 13 supports PHP 8.3–8.5 with security support into 2028; PHP 8.3 EOL Dec 2027; PHP 8.5 current).
- [x] **Step 2: Update the existing modernization plan's version references.**
- [x] **Step 3: Commit** — `docs: recommend target architecture and migration path`.

---

## Verification

After the last task:

```bash
php tests/run.php
php tests/security/check.php
STAGING_URL=http://localhost:8080 ./tests/security/http_smoke.sh
STAGING_URL=http://localhost:8080 ./tests/integration/api_contract.sh
npx playwright test --config=tests/browser/playwright.config.js
```

All five must be green before the branch is reported complete. Update the checkboxes in `docs/superpowers/plans/2026-10-31-security-refactoring.md` only for work verified by the suites in this session.
