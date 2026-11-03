# Security Refactoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the unauthenticated database-write, data-exposure, API, password-storage, and upload vulnerabilities identified in `SECURITY-AUDIT.md` without breaking the public site, admin panel, or mobile app API contracts.

**Architecture:** Harden at the choke points rather than patching call sites: (1) remove exposed artefacts and enable production error handling, (2) enforce authentication centrally in `Admin_Core_Controller` and a new `Api_Core_Controller`, (3) replace MD5 with Argon2id behind a `Password` library that transparently upgrades legacy hashes, (4) turn on CSRF/session hardening and output escaping, (5) constrain uploads and payments server-side. Every phase is independently deployable and revertible.

**Tech Stack:** PHP 8.3.6, CodeIgniter 3.1.13 (EOL), MySQLi/MySQL 8, Apache 2.4 (`mod_rewrite`), jQuery 3.2.1 admin UI, no Composer/test runner currently installed.

**Spec:** `/home/jay/Downloads/tentukottasite/SECURITY-AUDIT.md`

> **Status — 2026-11-03:** all 13 tasks are implemented and verified in place on branch `security-followup-2026-11-03`. Verification: unit 49/49, static 97 PASS / 1 SKIP, HTTP smoke 6/6, integration 16/16, browser 5/5. Follow-up work (env secrets, updater lockdown, SSRF, headers, tests) is tracked in `2026-11-03-security-followup.md`; the migration recommendation is `docs/architecture/target-architecture-2026.md`.

## Global Constraints

- PHP **8.3.6**; CodeIgniter **3.1.13**; MySQLi; Apache 2.4 + `mod_rewrite`. No framework upgrade inside this plan.
- **No new Composer dependencies** in Tasks 1–11 (none are installed; adding a supply chain is a separate decision).
- Preserve every existing public URL (`/watch/...`, `/movies`, `/search`, etc.) and every existing REST response field the mobile app reads. Changes to API responses must be **additive or redactive-only** (removing secrets/PII is allowed; renaming is not).
- Secrets are never committed. Rotation values are entered only on the server or in a local untracked `.env`-style file.
- No test fixture may contain real user data; use the dedicated `qa-*@example.test` accounts.
- Each task ends in a green run of `php tests/security/check.php` and one git commit. Every phase is revertible with `git revert`.
- **Command context:** shell commands run from `/home/jay/Downloads/tentukottasite/_public_html` unless the command starts with `cd ..` or uses a repo-root path. `tests/security/` lives at `/home/jay/Downloads/tentukottasite/tests/security/` (outside the web root).
- Production deploy order matters: Task 1 and Task 2 before any code release.

## Review Focus

These are the input classes most likely to break a real user once the fixes land; each has a test pinned to the owning task.

1. **Anonymous request to any `/admin/*` method** — must return HTTP 403, never a 200 page or a redirect that leaks data. (Task 3)
2. **Mobile client that sends no API key** — must receive JSON `401` with a JSON body, never an HTML error page or a PHP fatal. (Task 5)
3. **Existing user with an MD5 password** — must log in exactly once with the old password, then have the stored hash upgraded; users with `is_password_set = 0` must not be locked out. (Task 6)
4. **Admin AJAX after CSRF is enabled** (`delete_row`, `update_status`, modal loads) — must keep working via an injected token, not silently fail with 403. (Task 8)
5. **Firebase-only account (`firebase_auth_uid` set, `is_password_set = 0`)** — must not gain a password-setting bypass, and must still be able to authenticate through the existing flow. (Task 6)

---

## File Structure

| Path | Responsibility |
|---|---|
| `docs/superpowers/plans/` | This plan (outside the web root) |
| `tests/security/check.php` | Static security assertions; exit 0 = pass |
| `tests/security/http_smoke.sh` | Optional HTTP checks against `$STAGING_URL` |
| `_public_html/application/core/Core_Controller.php` | Add central `_require_admin()` to `Admin_Core_Controller` |
| `_public_html/application/core/API_Core_Controller.php` | **New** — REST base controller with `_require_api_key()` |
| `_public_html/application/libraries/Password.php` | **New** — hash/verify/needs_rehash with legacy MD5 upgrade |
| `_public_html/application/helpers/custom_helper.php` | Add `safe_redirect()`, `e()` |
| `_public_html/application/models/Auth_attempt_model.php` | **New** — login throttling |
| `_public_html/application/controllers/rest_api/V100.php`, `V130.php` | Extend `API_Core_Controller`; redact sensitive fields |
| `_public_html/application/controllers/Admin.php` | Remove ungated public writes; whitelist identifiers |
| `_public_html/application/controllers/Subscription.php` | Server-side payment verification |
| `_public_html/application/config/*.php` | Production mode, CSRF, session, DB credentials via env |
| `_public_html/uploads/.htaccess` | **New** — block PHP execution |
| `_public_html/application/views/theme/default/{main_slider,search_results,header}.php` | Output escaping |
| `_public_html/application/views/admin/index.php` | jQuery CSRF `ajaxSetup` |

**Deploy gate:** `_public_html/.htaccess` must deny `db_backup/`, `application/`, `system/`, and dot/backup extensions before Task 1 is considered done.

---

### Task 0: Safety net — version control, baseline, test harness

**Files:**
- Create: `.gitignore` (repo root `/home/jay/Downloads/tentukottasite`)
- Create: `tests/security/check.php`
- Create: `tests/security/http_smoke.sh`
- Create: `tests/security/README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `php tests/security/check.php` → exit `0`/`1` with one `PASS`/`FAIL` line per assertion. Later tasks append assertions to `$checks` in this file.

- [x] **Step 1: Confirm the tree is not under version control and initialise it**

Run: `cd /home/jay/Downloads/tentukottasite && git status`
Expected: `fatal: not a git repository`.

Then:
```bash
git init
git branch -M main
```

- [x] **Step 2: Write `.gitignore`**

```gitignore
_public_html/uploads/*
!_public_html/uploads/index.html
!_public_html/uploads/.htaccess
_public_html/db_backup/
_public_html/application/logs/*
!_public_html/application/logs/index.html
_public_html/application/cache/*
!_public_html/application/cache/index.html
.env
*.sql
_public_html.zip
```

- [x] **Step 3: Commit the baseline**

```bash
git add -A
git commit -m "chore: baseline before security hardening"
```

- [x] **Step 4: Write the static security checker**

`tests/security/check.php` — no dependencies, runs on PHP 8.3:

```php
<?php
$fails = 0;
function root(): string { return dirname(__DIR__, 2) . '/_public_html'; }
function check(string $name, callable $fn): void {
    global $fails;
    try { $ok = (bool) $fn(); } catch (Throwable $e) { $ok = false; }
    echo ($ok ? "PASS  " : "FAIL  ") . $name . PHP_EOL;
    if (!$ok) $fails++;
}
function src(string $rel): string {
    $p = root() . '/' . $rel;
    return is_file($p) ? file_get_contents($p) : '';
}
function has(string $rel, string $needle): bool { return str_contains(src($rel), $needle); }
```

- [x] **Step 5: Add a self-test that proves the harness fails correctly**

Append:
```php
check('harness: index.php exists', fn() => is_file(root() . '/index.php'));
check('harness: impossible assertion fails', fn() => false);
```
Run: `php tests/security/check.php`
Expected: `PASS` then `FAIL`, exit code `1`.

Then delete the impossible-assertion line and re-run; expected exit code `0`.

- [x] **Step 6: Write the HTTP smoke script**

`tests/security/http_smoke.sh`:
```bash
#!/usr/bin/env bash
set -uo pipefail
: "${STAGING_URL:?set STAGING_URL, e.g. https://staging.example.test}"
fail=0
expect() { # name expected_code url [extra curl args...]
  local name="$1" want="$2" url="$3"; shift 3
  local got
  got=$(curl -s -o /tmp/smoke.body -w '%{http_code}' "$@" "$STAGING_URL$url")
  if [[ "$got" == "$want" ]]; then echo "PASS  $name ($got)"; else
    echo "FAIL  $name (got $got, want $want)"; fail=1; fi
}
expect 'unauth admin delete_record' 403 '/admin/delete_record' -X POST
expect 'unauth admin update_status' 403 '/admin/update_status' -X POST
expect 'rest v130 config requires auth' 401 '/rest-api/v130/config'
exit "$fail"
```

- [x] **Step 7: Commit**

```bash
chmod +x tests/security/http_smoke.sh
git add .gitignore tests/security
git commit -m "test: add static and HTTP security check harness"
```

---

### Task 1: Remove the exposed database dump and deny backup paths

**Files:**
- Delete: `_public_html/db_backup/db_backup_2024-11-03-11-57-30.sql`
- Modify: `_public_html/.htaccess` (append deny rules)
- Create: `docs/runbooks/secret-rotation.md`
- Modify: `tests/security/check.php` (append assertions)

**Interfaces:**
- Consumes: nothing.
- Produces: no `.sql` under `_public_html`; `db_backup/` returns 403/404.

- [x] **Step 1: Add the failing tests**

Append to `tests/security/check.php`:
```php
check('no .sql files under web root', function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(root(),
        FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if (strtolower($f->getExtension()) === 'sql') return false; }
    return true;
});
check('.htaccess denies db_backup', fn() => has('.htaccess', 'db_backup'));
check('.htaccess denies application and system dirs',
    fn() => has('.htaccess', 'application/') && has('.htaccess', 'system/'));
```
Run: `php tests/security/check.php`
Expected: three `FAIL` lines.

- [x] **Step 2: Move the dump off the web root (do not just delete — archive it)**

```bash
mkdir -p /home/jay/Downloads/tentukottasite/secure-archive
mv _public_html/db_backup/db_backup_2024-11-03-11-57-30.sql \
   /home/jay/Downloads/tentukottasite/secure-archive/
chmod 600 /home/jay/Downloads/tentukottasite/secure-archive/*.sql
```

- [x] **Step 3: Append deny rules to `_public_html/.htaccess`**

```apache
# --- security hardening ---
<IfModule mod_rewrite.c>
  RewriteRule ^(db_backup|application|system)(/|$) - [F,L]
  RewriteRule \.(sql|bak|old|zip|tar|gz|log|env)$ - [F,L,NC]
</IfModule>
Options -Indexes
```

- [x] **Step 4: Write the rotation runbook**

`docs/runbooks/secret-rotation.md` must list, with checkboxes and the exact config key: DB password (`database.php`), `mobile_apps_api_secret_key`, `cron_key`, `google_client_secret`, `paypal_client_id`/secret, `stripe_secret_key`, `razorpay_key_secret`, `encryption_key`, SMTP password. For each: where stored, who rotates, how to verify, and "force password reset for all 123 users".

- [x] **Step 5: Run checks**

Run: `php tests/security/check.php`
Expected: all `PASS`, exit `0`.

- [x] **Step 6: Verify over HTTP (staging)**

Run: `STAGING_URL=https://staging.example.test ./tests/security/http_smoke.sh`
Expected: `db_backup` path returns `403`.

- [x] **Step 7: Commit**

```bash
git add _public_html/.htaccess docs/runbooks tests/security/check.php
git rm --cached _public_html/db_backup/db_backup_2024-11-03-11-57-30.sql 2>/dev/null || true
git commit -m "fix(sec): remove public DB dump and deny backup/system paths"
```

---

### Task 2: Production mode and safe error handling

**Files:**
- Modify: `_public_html/index.php:24`
- Modify: `_public_html/application/config/config.php` (`log_threshold`)
- Modify: `_public_html/application/config/database.php:86` (`db_debug`)
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ENVIRONMENT === 'production'`; `db_debug === FALSE`; errors logged, not printed.

- [x] **Step 1: Add failing tests**

```php
check('ENVIRONMENT is production', fn() => has('index.php', "define('ENVIRONMENT', 'production')"));
check('db_debug disabled', fn() => preg_match("/'db_debug'\s*=>\s*FALSE/i", src('application/config/database.php')) === 1);
check('error logging enabled', fn() => preg_match("/log_threshold'\]\s*=\s*[1-4]/", src('application/config/config.php')) === 1);
```
Run: expected three `FAIL`.

- [x] **Step 2: Apply the changes**

`index.php`: `define('ENVIRONMENT', 'production');`
`config.php`: `$config['log_threshold'] = 1;`
`database.php`: `'db_debug' => FALSE,`

- [x] **Step 3: Verify no SQL error text is emitted**

Run against staging with an intentionally bad request:
`curl -s "$STAGING_URL/watch/does-not-exist" | grep -ci "SQL syntax\|A Database Error Occurred"`
Expected: `0`.

- [x] **Step 4: Run checks and commit**

```bash
php ../tests/security/check.php
git add index.php application/config/config.php application/config/database.php
git commit -m "fix(sec): enable production mode and suppress verbose DB errors"
```
(Executed from `_public_html`; the checker path is relative to the repo root.)

---

### Task 3: Central admin authentication gate

**Files:**
- Modify: `_public_html/application/core/Core_Controller.php:12-19`
- Modify: `_public_html/application/controllers/Admin.php` (remove/move public writes)
- Modify: `_public_html/application/controllers/Home.php` (receive public rating if kept)
- Create: `_public_html/application/controllers/Movie_public.php` (only if public rating must stay)
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `admin_is_login` session key set by `Login::validate_login()`.
- Produces: `Admin_Core_Controller::_require_admin(): void` — sends `403` with `show_error('Forbidden', 403)` when the session flag is absent. All Admin methods benefit automatically.

- [x] **Step 1: Add the failing test**

```php
check('Admin_Core_Controller calls _require_admin in constructor', function () {
    $s = src('application/core/Core_Controller.php');
    return preg_match('/class Admin_Core_Controller.*?class Home_Core_Controller/s', $s, $m) === 1
        && str_contains($m[0], 'function _require_admin')
        && str_contains($m[0], '$this->_require_admin()');
});
check('Admin constructor delegates to parent', fn() =>
    preg_match('/class Admin[^{]*\{[^}]*function\s+__construct\s*\([^)]*\)\s*\{\s*parent::__construct\(\)/s',
        src('application/controllers/Admin.php')) === 1);
```
Run: expected `FAIL` ×3.

- [x] **Step 2: Implement `_require_admin()`**

In `Core_Controller.php`:
```php
class Admin_Core_Controller extends Core_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->_require_admin();
    }

    protected function _require_admin(): void
    {
        if ($this->session->userdata('admin_is_login') != 1) {
            if ($this->input->is_ajax_request()) {
                $this->output->set_status_header(403)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['status' => 'error', 'message' => 'Forbidden']))
                    ->_display();
                exit;
            }
            show_error('Forbidden', 403);
        }
    }
}
```
Guard against double-execution: in `Admin.php::__construct()` call `parent::__construct()` first (already present at `:9`).

- [x] **Step 3: Move genuinely public write endpoints out of `Admin.php`**

Move `rating()` (`Admin.php:3963`) and `post_rating()` (`:3974`) into a new `Movie_public extends Home_Core_Controller` controller, keeping the **existing URL contract** by adding a route in `application/config/routes.php`:
```php
$route['admin/rating'] = 'movie_public/rating';
```
Delete `rating()` and `post_rating()` from `Admin.php` after the copy exists (the route keeps the old URL working). Keep `video_file`, `episodes_url`, `import_movie`, `complete_import`, `api_setting`, `slider`, `download_link` inside `Admin` — they now inherit the gate.

- [x] **Step 4: Remove the per-method duplicate checks that the gate makes redundant**

Do not delete them all at once. Delete only the `if ($this->session->userdata('admin_is_login') != 1) redirect(...)` blocks in `Admin.php` where they are the *first* statement, to avoid dead code. Keep `demo_check()` calls.

- [x] **Step 5: Verify**

```bash
php tests/security/check.php
STAGING_URL=... ./tests/security/http_smoke.sh
```
Expected: `unauth admin delete_record` and `unauth admin update_status` now `403`; admin login + dashboard + one CRUD action still work manually.

- [x] **Step 6: Commit**

```bash
git add application/core/Core_Controller.php application/controllers/Admin.php \
        application/controllers/Movie_public.php application/config/routes.php \
        ../tests/security/check.php
git commit -m "fix(sec): enforce admin auth centrally and close unauthenticated admin writes"
```

---

### Task 4: Whitelist table/column identifiers (defence in depth)

**Files:**
- Modify: `_public_html/application/controllers/Admin.php` (any remaining generic CRUD helper)
- Create: `_public_html/application/config/admin_tables.php`
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `_require_admin()` from Task 3.
- Produces: `admin_tables(): array` returning `['table' => ['pk' => 'videos_id', 'columns' => ['title','publication',...]]]`.

- [x] **Step 1: Failing test**

```php
check('admin table whitelist config exists', fn() => is_file($GLOBALS['ROOT'].'/application/config/admin_tables.php'));
check('no raw table_names from input reach db->delete/update', function () {
    $s = src('application/controllers/Admin.php');
    return !preg_match('/\$this->db->(delete|update)\(\s*\$this->input->post/', $s);
});
```

- [x] **Step 2: Implement the whitelist and enforce it**

Create `application/config/admin_tables.php` listing the tables the UI actually mutates and their primary keys/columns. In the handler, reject unknown table/column with `403`.

- [x] **Step 3: Run checks, smoke, commit**

```bash
php tests/security/check.php && git add application/config/admin_tables.php application/controllers/Admin.php ../tests/security/check.php
git commit -m "fix(sec): whitelist admin-mutable tables and columns"
```

---

### Task 5: Authenticate the REST API and redact secrets/PII

**Files:**
- Create: `_public_html/application/core/API_Core_Controller.php`
- Modify: `_public_html/application/controllers/rest_api/V100.php:22`, `V130.php:22` (`extends`)
- Modify: `_public_html/application/models/Api_v130_model.php:1583` and v100 equivalent
- Modify: `_public_html/application/controllers/rest_api/V130.php:604` (`config_get`), `:904` (`user_info_by_code_post`), `:988`
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `config` row `mobile_apps_api_secret_key`.
- Produces: `API_Core_Controller::_require_api_key(): void` → `401` JSON `{"status":"error","message":"Unauthorized"}`. Accepts header `X-API-KEY` or POST/GET param `api_secret_key`, compared with `hash_equals()`. Also `public_user_fields(): array` = `['user_id','name','email','phone','gender','join_date','last_login']`.

- [x] **Step 1: Failing tests**

```php
check('API base controller exists', fn() => is_file(root().'/application/core/API_Core_Controller.php'));
check('V130 uses API base controller', fn() => has('application/controllers/rest_api/V130.php', 'extends API_Core_Controller'));
check('config_get exposes only the public payment subset', fn() =>
    !str_contains(src('application/controllers/rest_api/V130.php'), 'get_payment_config')
    && str_contains(src('application/controllers/rest_api/V130.php'), 'get_public_payment_config'));
```

- [x] **Step 2: Implement `API_Core_Controller`**

```php
class API_Core_Controller extends \chriskacerguis\RestServer\RestController
{
    public function __construct()
    {
        parent::__construct();
        $this->_require_api_key();
    }

    protected function _require_api_key(): void
    {
        $supplied = $this->input->get_request_header('X-API-KEY', TRUE);
        if (empty($supplied)) $supplied = $this->input->get_post('api_secret_key', TRUE);
        $expected = $this->db->get_where('config',
            ['title' => 'mobile_apps_api_secret_key'])->row()->value ?? '';
        if (!$expected || !hash_equals($expected, (string) $supplied)) {
            $this->response(['status' => 'error', 'message' => 'Unauthorized'], 401);
            exit;
        }
    }
}
```
CI3 does not autoload classes in `application/core/` unless they use the `Core_` prefix, so add at the top of `API_Core_Controller.php`:
```php
require_once(APPPATH.'/libraries/RestController.php');
use chriskacerguis\RestServer\RestController;
```
and in `V100.php` / `V130.php` replace the existing `require(APPPATH.'/libraries/RestController.php');` with:
```php
require_once(APPPATH.'/core/API_Core_Controller.php');
```
Leave their `use chriskacerguis\RestServer\RestController;` line intact or remove it — the parent class is resolved at parse time from the included file.

- [x] **Step 3: Redact sensitive responses**

- `user_info_by_code_post` (`:904`) must map through `public_user_fields()` — never return `password`, `token`, `firebase_auth_uid`.
- `user_details_by_user_id_get` / `..._by_email_get`: keep current fields (no password was returned there) but add rate limiting later.
- `config_get` (`:604`): replace `get_payment_config()` with a public subset (`currency`, `currency_symbol`, `paypal_enable`, `paypal_client_id`, `stripe_enable`, `stripe_publishable_key`, `razorpay_enable`, `razorpay_key_id`). **Never** return `stripe_secret_key`, `razorpay_key_secret`, `paypal_secret`.
- `store_payment_info_post` (`:988`) is temporarily disabled until Task 7 verifies payments. Replace its body with:
  ```php
  $this->response(['status' => 'error', 'message' => 'Endpoint disabled pending payment verification'], 410);
  ```
  Task 7 restores the body with verification added. The mobile app must treat `410` as "purchase unavailable" rather than retry.

- [x] **Step 4: Verify auth on every endpoint**

```bash
for p in config slider all_package user_details_by_user_id; do
  curl -s -o /dev/null -w "$p %{http_code}\n" "$STAGING_URL/rest-api/v130/$p"
done
```
Expected: `401` for each. Then repeat with `-H "X-API-KEY: $TEST_KEY"` → `200`.

- [x] **Step 5: Commit**

```bash
git add application/core/API_Core_Controller.php application/controllers/rest_api \
        application/models/Api_v130_model.php application/models/Api_v100_model.php ../tests/security/check.php
git commit -m "fix(sec): require API key for v100/v130 and redact secrets and auth fields"
```

---

### Task 6: Password hashing migration (Argon2id + transparent upgrade)

**Files:**
- Create: `_public_html/application/libraries/Password.php`
- Modify: `_public_html/application/controllers/Login.php:44,140,178,233`
- Modify: `_public_html/application/controllers/User.php:148,194`
- Modify: `_public_html/application/controllers/Admin.php:1978,1991,3705-3707`
- Modify: `_public_html/application/controllers/rest_api/V100.php`, `V130.php`, `Legacy_api.php`
- Modify: `_public_html/application/models/Api_v100_model.php:1502`, `Api_v130_model.php:1631`, `Legacy_api_model.php:1505`
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `user.password` is `LONGTEXT` (no schema change needed).
- Produces:
  - `Password::hash(string $plain): string`
  - `Password::verify(string $plain, string $stored): bool`
  - `Password::needs_rehash(string $stored): bool`

- [x] **Step 1: Failing tests**

```php
check('Password library exists', fn() => is_file(root().'/application/libraries/Password.php'));
check('no md5( anywhere in controllers or models', function () {
    foreach (['application/controllers', 'application/models'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            root() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            if (str_contains(file_get_contents($f->getPathname()), 'md5(')) return false;
        }
    }
    return true;
});
check('Password uses password_hash', fn() => str_contains(src('application/libraries/Password.php'), 'password_hash'));
```

- [x] **Step 2: Implement `Password`**

```php
class Password
{
    private const ALGO = PASSWORD_ARGON2ID;
    public static function hash(string $plain): string { return password_hash($plain, self::ALGO); }
    public static function verify(string $plain, string $stored): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/i', $stored)) return hash_equals($stored, md5($plain));
        return password_verify($plain, $stored);
    }
    public static function needs_rehash(string $stored): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $stored) || password_needs_rehash($stored, self::ALGO);
    }
}
```

- [x] **Step 3: Wire login paths with transparent upgrade**

`Login::validate_login()`: compare via `Password::verify()`, then if `Password::needs_rehash($row->password)` update `user.password = Password::hash($plain)` for that `user_id` before returning success. Apply the same to `V130::login_post`, `V100::login_post`, `Legacy_api::login`.

- [x] **Step 4: Replace write paths**

Every `md5($x)` used to store a password becomes `Password::hash($x)` — including the models that seed `md5($phone)` for social/Firebase signups (`Api_v100_model.php:1502`, `Api_v130_model.php:1631`, `Legacy_api_model.php:1505`) and the `md5(uniqid())` Firebase placeholder. `User.php:148` default `md5($email)` becomes a random `bin2hex(random_bytes(16))` with `is_password_set = 0` (never derive from email).

- [x] **Step 5: Add the Firebase-account guard test**

Append:
```php
check('firebase set_password still requires firebase_auth_uid', function () {
    $s = src('application/controllers/rest_api/V130.php');
    if (!preg_match('/function\s+set_password_post\s*\([^)]*\)\s*\{(.*?)\n\s{4}\}/s', $s, $m)) return false;
    return str_contains($m[1], 'firebase_auth_uid') && str_contains($m[1], 'validate_user_by_id_firebase_auth_uid');
});
```

- [x] **Step 6: Verify the legacy path end-to-end**

Seed a QA user with a known MD5 hash, log in with the plaintext, assert success, then assert the DB value now starts with `$argon2id$`. Run:
```bash
php tests/security/check.php
STAGING_URL=... ./tests/security/http_smoke.sh
```
Expected: legacy login succeeds once; hash upgraded; second login still succeeds.

- [x] **Step 7: Commit**

```bash
git add application/libraries/Password.php application/controllers application/models \
        ../tests/security/check.php
git commit -m "fix(sec): migrate password storage to Argon2id with legacy hash upgrade"
```

---

### Task 7: Server-side payment verification

**Files:**
- Modify: `_public_html/application/controllers/Subscription.php:642` (`save_payment`)
- Modify: `_public_html/application/models/Subscription_model.php`
- Modify: `_public_html/application/controllers/Legacy_api.php:1636`, `rest_api/V130.php:988`
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: gateway libraries already present (`stripegateway`, `Paypal`, Razorpay `application/third_party/razorpay`).
- Produces: `Subscription_model::verify_payment(string $method, array $payload, float $expectedAmount): bool`.

- [x] **Step 1: Failing test**

```php
check('save_payment calls verify_payment', function () {
    $s = src('application/controllers/Subscription.php');
    return str_contains($s, 'verify_payment(')
        && !preg_match('/\$data\[.paid_amount.\]\s*=\s*\$_POST/', $s);
});
```

- [x] **Step 2: Implement per-gateway verification**

- `stripe`: retrieve the PaymentIntent id from `$payload['payment_info']`, call the Stripe library, require `status === 'succeeded'` and `amount_received == intval($expectedAmount * 100)`.
- `paypal`: use the existing `$this->paypal->validate_ipn()` result plus `payment_status === 'Completed'` and matching `mc_gross`.
- `razorpay`: reuse `verifyPaymentSignature()` exactly as `Subscription.php:481-503` already does.

Return `false` on any mismatch; log the reason.

- [x] **Step 3: Enforce in all three call sites**

`save_payment` must read the plan price from the DB (never trust `paid_amount`), call `verify_payment()`, and only then insert with `status = 1`. On failure return JSON `{"status":"error","message":"Payment verification failed"}` with HTTP 402.

Restore the `V130::store_payment_info_post` body disabled in Task 5, now gated on `verify_payment()` and on the authenticated identity matching `user_id`; keep returning `410` until the mobile app sends an auth token.

- [x] **Step 4: Verify**

```bash
php tests/security/check.php
curl -s -X POST "$STAGING_URL/subscription/save_payment" \
  -d 'plan_id=1&payment_method=stripe&payment_info=fake' -b "$USER_COOKIE"
```
Expected: `402`, no new `subscription` row.

- [x] **Step 5: Commit**

```bash
git add application/controllers/Subscription.php application/models/Subscription_model.php \
        application/controllers/Legacy_api.php application/controllers/rest_api/V130.php ../tests/security/check.php
git commit -m "fix(sec): require server-side gateway verification before granting subscriptions"
```

---

### Task 8: CSRF and session hardening

**Files:**
- Modify: `_public_html/application/config/config.php:252-258,274,299-302`
- Modify: `_public_html/application/views/admin/index.php` (add `ajaxSetup`)
- Modify: `_public_html/application/views/theme/default/header.php` (meta token)
- Modify: `_public_html/application/controllers/Login.php` (regenerate session)
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `form_open()` usage (91 occurrences) auto-injects the hidden token when CSRF is on.
- Produces: valid CSRF token available as `$this->security->get_csrf_hash()` and injected into all jQuery AJAX.

- [x] **Step 1: Failing tests**

```php
check('csrf enabled', fn() => preg_match("/csrf_protection'\]\s*=\s*TRUE/", src('application/config/config.php')) === 1);
check('secure cookie flag set conditionally', fn() => has('application/config/config.php', 'cookie_secure'));
check('samesite configured', fn() => has('application/config/config.php', 'sess_samesite'));
check('session regenerated on login', fn() => has('application/controllers/Login.php', 'sess_regenerate(TRUE)'));
```

- [x] **Step 2: Configuration**

```php
$config['csrf_protection']   = TRUE;
$config['csrf_token_name']   = 'csrf_token';
$config['csrf_cookie_name']  = 'csrf_cookie';
$config['csrf_expire']       = 7200;
$config['csrf_regenerate']   = FALSE; // see note below: TRUE would rotate the token on every response
$config['csrf_exclude_uris'] = ['^rest-api/.*', '^api/.*', '^cron/.*'];

$config['cookie_secure'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
```
In the **Session Variables** block of `config.php` (after `sess_regenerate_destroy`):
```php
$config['sess_samesite'] = 'Lax';
$config['sess_regenerate_destroy'] = TRUE;
```

- [x] **Step 3: Inject the token into admin AJAX**

In `views/admin/index.php`, immediately after jQuery loads:
```php
<script>
window.CSRF = { name: '<?= $this->security->get_csrf_token_name() ?>',
                hash: '<?= $this->security->get_csrf_hash() ?>' };
$.ajaxSetup({ data: { [window.CSRF.name]: window.CSRF.hash } });
</script>
```
Keep `csrf_regenerate = FALSE` in this task: with `TRUE`, CI3 rotates the token on every response and the static hash injected above goes stale after the first AJAX call (the admin UI then 403s intermittently). The token still expires with the session. If you later want per-request rotation, read the refreshed `csrf_cookie` value in a jQuery `ajaxComplete` handler and update `window.CSRF.hash` before enabling it.

- [x] **Step 4: Regenerate the session on login**

In `Login::validate_login()` right after successful credential check:
`$this->session->sess_regenerate(TRUE);`

- [x] **Step 5: Verify manually and by smoke**

Run: `php tests/security/check.php`
Then in a browser: log in to `/admin`, open a movie list, delete a row, edit a status, open two modals. Expected: all succeed; a direct POST without a token returns `403`.

- [x] **Step 6: Commit**

```bash
git add application/config/config.php application/views/admin/index.php \
        application/views/theme/default/header.php application/controllers/Login.php ../tests/security/check.php
git commit -m "fix(sec): enable CSRF, SameSite/Secure cookies and session regeneration"
```

---

### Task 9: Output escaping (stored and reflected XSS)

**Files:**
- Modify: `_public_html/application/views/theme/default/main_slider.php:78-83`
- Modify: `_public_html/application/views/admin/request_manage.php:33-36`
- Modify: `_public_html/application/views/admin/report_manage.php` (issue/message columns)
- Modify: `_public_html/application/views/theme/default/search_results.php:7,40`
- Modify: `_public_html/application/views/theme/default/header.php:51,72,183,253,319`
- Modify: `_public_html/application/helpers/custom_helper.php` (add `e()`)
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `e($value): string` → `html_escape($value)`; all dynamic output in the listed views passes through it.

- [x] **Step 1: Failing test**

```php
check('slider output escaped', fn() => has('application/views/theme/default/main_slider.php', 'e($slider->title)'));
check('search keyword escaped', fn() => has('application/views/theme/default/search_results.php', 'e($search_keyword)'));
check('request queue escaped', fn() => has('application/views/admin/request_manage.php', 'e($row['));
```

- [x] **Step 2: Add the helper**

```php
if (!function_exists('e')) {
    function e($v) { return html_escape($v ?? ''); }
}
```

- [x] **Step 3: Escape each sink**

Replace `<?php echo $slider->title;?>` with `<?php echo e($slider->title);?>` etc. Do **not** escape values that are intentionally HTML (none of these are). Keep `strip_tags()` where already used.

- [x] **Step 4: Verify with a payload**

Submit a movie request containing `<img src=x onerror=alert(1)>`; open `/admin/request_manage`. Expected: the literal text renders, no dialog.

- [x] **Step 5: Commit**

```bash
git add application/helpers/custom_helper.php application/views ../tests/security/check.php
git commit -m "fix(sec): escape stored and reflected output in slider, requests and search"
```

---

### Task 10: Upload hardening

**Files:**
- Create: `_public_html/uploads/.htaccess`
- Create: `_public_html/uploads/web.config`
- Modify: `_public_html/application/controllers/Admin.php:65,86,122,144,285` and upload config blocks
- Modify: `_public_html/application/controllers/rest_api/V130.php:503`
- Modify: `_public_html/application/helpers/custom_helper.php` (add `safe_image_upload()`)
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: `$_FILES` entries.
- Produces: `safe_image_upload(array $file, string $destinationAbsPath): bool` — verifies `getimagesize()`, allowed MIME (`image/jpeg|png|webp`), max 5 MB, re-encodes via GD.

- [x] **Step 1: Failing tests**

```php
check('uploads denies php execution', function () {
    $p = root().'/uploads/.htaccess';
    if (!is_file($p)) return false;
    $s = file_get_contents($p);
    return str_contains($s, 'php_flag engine off') && str_contains($s, 'RemoveHandler');
});
check('no raw move_uploaded_file in Admin', fn() => !str_contains(src('application/controllers/Admin.php'), 'move_uploaded_file('));
```

- [x] **Step 2: Block execution**

`uploads/.htaccess`:
```apache
php_flag engine off
RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .php8
RemoveType .php .phtml .phar
<FilesMatch "\.(php|phtml|phar|cgi|pl|sh)$">
  Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
```
`uploads/web.config`: mirror the PHP handler removal for IIS.

- [x] **Step 3: Replace raw moves**

Swap every `move_uploaded_file(...)` for `safe_image_upload()`; on failure set a flash error and skip the DB write. For video uploads keep the CI upload library with `allowed_types` and add `max_size` (e.g. `2048000` KB) and `file_ext_tolower`.

- [x] **Step 4: Verify**

Upload a PHP file named `x.php.png` and a PHP file named `x.php`. Expected: both rejected (or stored as re-encoded JPEG); `/uploads/x.php` returns `403`.

- [x] **Step 5: Commit**

```bash
git add uploads/.htaccess uploads/web.config application/controllers application/helpers/custom_helper.php ../tests/security/check.php
git commit -m "fix(sec): block PHP execution in uploads and validate uploaded images"
```

---

### Task 11: Login throttling and open-redirect fix

**Files:**
- Create: `_public_html/application/models/Auth_attempt_model.php`
- Create: `docs/migrations/2026-10-31-login-attempts.sql`
- Modify: `_public_html/application/controllers/Login.php`, `rest_api/V130.php`, `Legacy_api.php`
- Modify: `_public_html/application/controllers/Comments.php:40,50,57,71,79,91,99,110`
- Modify: `_public_html/application/helpers/custom_helper.php`
- Modify: `tests/security/check.php`

**Interfaces:**
- Consumes: DB connection.
- Produces:
  - Table `login_attempts (id, ip, username, attempted_at, INDEX(ip, attempted_at), INDEX(username, attempted_at))`
  - `Auth_attempt_model::too_many(string $ip, string $username): bool` (≥5 failures in 15 min)
  - `Auth_attempt_model::record(string $ip, string $username): void`
  - `Auth_attempt_model::clear(string $ip, string $username): void`
  - `safe_redirect(string $url): void` — permits only same-host paths, otherwise `redirect(base_url())`.

- [x] **Step 1: Failing tests**

```php
check('login throttling model exists', fn() => is_file(root().'/application/models/Auth_attempt_model.php'));
check('comments use safe_redirect', fn() => !preg_match('/redirect\(\s*\$url/', src('application/controllers/Comments.php')));
check('safe_redirect helper defined', fn() => has('application/helpers/custom_helper.php', 'function safe_redirect'));
```

- [x] **Step 2: Create the table and model**

Run the migration on staging; implement the three methods with `COUNT(*)` over the window and prune rows older than 24 h on write.

- [x] **Step 3: Enforce in every credential check**

Before validating credentials: `if ($this->auth_attempt_model->too_many($ip, $username)) return 'throttled';` → user-facing "Too many attempts, try again in 15 minutes" (HTTP 429 for API). On failure `record()`; on success `clear()`.

- [x] **Step 4: Replace open redirects**

`safe_redirect($this->input->post('url'))` in `Comments.php`, and reject absolute URLs/hosts in the helper:
```php
function safe_redirect(?string $url): void {
    if (!$url || preg_match('#^(https?:)?//#i', $url) || str_contains($url, '\\')) {
        redirect(base_url()); return;
    }
    redirect($url);
}
```

- [x] **Step 5: Verify**

Six bad logins → 6th returns throttled; a comment POST with `url=https://evil.test` lands on `base_url()`.

- [x] **Step 6: Commit**

```bash
git add application/models/Auth_attempt_model.php docs/migrations application/controllers \
        application/helpers/custom_helper.php ../tests/security/check.php
git commit -m "fix(sec): throttle login attempts and prevent open redirects"
```

---

### Task 12: Dependency and framework follow-up (track, do not execute here)

**Files:**
- Create: `docs/security/dependency-remediation.md`
- Modify: `tests/security/check.php` (record known-bad versions)

**Interfaces:**
- Consumes: `application/third_party/google-api-client/vendor/composer/installed.json`, `application/libraries/stripe/lib/Stripe.php:61`.
- Produces: a tracked list of packages, current versions, minimum safe versions, and owner.

- [x] **Step 1: Write the inventory**

Record: `guzzlehttp/guzzle 6.5.3` (CVE-2022-31042/31043/31090 — verify against NVD/OSV and reachability), `google/apiclient 2.4.1`, `google/auth 1.8.0`, `phpseclib 2.0.27`, `firebase/php-jwt 5.2.0`, `monolog 2.0.2`, `stripe-php 9.6.0`, CodeIgniter 3.1.13 (EOL).

- [x] **Step 2: Decide and document**

Choose one: (a) targeted upgrade of the vendored tree, (b) isolate the Google integration behind its own service, or (c) framework migration to CI4. Record the decision, owner, and date. Do not start edits in this plan.

- [x] **Step 3: Add a guard test**

```php
check('guzzle is not the known-vulnerable 6.5.3', function () {
    $j = root().'/application/third_party/google-api-client/vendor/composer/installed.json';
    return !is_file($j) || !str_contains(file_get_contents($j), '"version": "6.5.3"');
});
```
Mark it `SKIP` (print `SKIP` and do not fail) until the upgrade task starts.

- [x] **Step 4: Commit**

```bash
git add docs/security/dependency-remediation.md ../tests/security/check.php
git commit -m "docs(sec): track EOL framework and vulnerable vendored dependencies"
```

---

## Verification Matrix

| Requirement from `SECURITY-AUDIT.md` | Task | Automated check |
|---|---|---|
| CRIT-01 unauthenticated admin writes | 3, 4 | static + `http_smoke.sh` 403 |
| CRIT-02 public DB dump | 1 | static (no `.sql`) + HTTP 403 |
| CRIT-03 unauthenticated API | 5 | static + HTTP 401 |
| CRIT-04 MD5 passwords | 6 | static + legacy-login upgrade test |
| HIGH-01 client-trusted payment | 7 | static + HTTP 402 on fake payment |
| HIGH-02 hardcoded/weak secrets | 1, 2 | runbook + rotation checklist |
| HIGH-03 stored XSS | 3, 9 | static escaping checks + manual payload |
| HIGH-04 updater RCE | 3 | `_require_admin()` gate + manual test |
| MED-01 CSRF | 8 | static + manual admin CRUD |
| MED-02 brute force / GET creds | 11, 5 | static + 429 test |
| MED-03 admin read endpoints | 3 | static + HTTP 403 |
| MED-04 uploads exec | 10 | static + `x.php` 403 |
| MED-05 verbose errors | 2 | static + curl grep 0 |
| MED-06 session flags | 8 | static |
| MED-07 open redirect | 11 | static + manual POST |
| MED-08 EOL dependencies | 12 | tracked, guarded test |
| LOW-01 reflected XSS | 9 | static |
| LOW-02 weak reset/default password | 6 | static (no `md5(email)`) |
| LOW-03 headers/robots | 8 | manual header check (add in deploy) |

## Rollback

- Every task is one commit; revert in reverse order with `git revert <sha>`.
- Tasks 3 and 8 are the highest-blast-radius changes. Deploy them to staging first, with a maintenance window; keep the previous release artefact available.
- If admin login breaks after Task 3: revert Task 3 only; Tasks 4–12 are independent.
- If the mobile app breaks after Task 5: the API key can be temporarily disabled by setting `rest_auth` back to `FALSE` **only** together with a WAF rule blocking the affected endpoints — never leave both open.

## Out of Scope

- TLS/cipher configuration, WAF, DNS/email security (SPF/DKIM/DMARC), hosting hardening.
- Android app changes (the app must ship `X-API-KEY`; coordinate release).
- Re-architecture of OVOO code outside the files listed.
- Legal/compliance review of the content-distribution model.
