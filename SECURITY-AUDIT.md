# Security Audit — Pudhu Padam (tentukotta.in)

| | |
|---|---|
| **Target** | `tentukotta.in` — OVOO-based movie / TV / Live-TV streaming CMS (CodeIgniter 3) |
| **Artifact** | Local deployment copy: `/home/jay/Downloads/tentukottasite/_public_html` |
| **Execution mode** | `offline` — static source & configuration review only. **No traffic was sent to any live host.** |
| **Assessment date (UTC)** | 2026-10-31T09:15Z |
| **Skills applied** | `09-web-security` (OWASP Top 10 / API / auth), `02-vulnerability-scanner` (config, dependency, CVSS) |
| **Assessor** | AI-assisted static review (OpenCode) |

> **Authorization note:** Findings below are derived entirely from source code and files supplied in the local copy. They must be re-validated against the live deployment before being treated as confirmed production vulnerabilities.

---

## 1. Executive Summary

The application is a commercially distributed PHP CMS (OVOO / OXOO lineage) with a large, largely unaudited customisation layer. The review found **multiple unauthenticated, internet-reachable paths to full administrative control**:

- An **unauthenticated database write primitive** in the admin controller that can modify arbitrary tables and columns — including user passwords — without a session.
- A **full database dump (3.2 MB, 123 user records) sitting inside the web root**, containing password hashes, PII, and live integration secrets (PayPal client ID, Google OAuth client secret, mobile API key, cron key).
- The **entire REST API (`/rest-api/v100`, `/rest-api/v130`) has no authentication whatsoever**, exposing user PII, payment-gateway secret keys, and the ability to grant premium subscriptions to arbitrary accounts.
- **Unsalted MD5** is used for every password (admin, subscriber, API) across all login, signup, reset, and change-password flows.

Chained together, these give an unauthenticated attacker administrative access and then remote code execution (via the admin updater), plus mass disclosure of customer data and payment credentials.

**Highest-risk items must be remediated immediately.** This is not a "harden later" situation — the deployment is currently exploitable.

### Finding summary

| ID | Finding | Severity | CVSS 3.1 | Reachable | Fix effort |
|---|---|---|---|---|---|
| CRIT-01 | Unauthenticated arbitrary DB delete/update (`Admin::delete_record`, `Admin::update_status`, and other ungated admin AJAX methods) | **Critical** | 9.4 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:H/A:H` | Yes (unauth) | Low |
| CRIT-02 | Full DB backup (users, hashes, secrets) served from web root `/db_backup/` | **Critical** | 7.5 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` | Yes (static file) | Low |
| CRIT-03 | REST API v100/v130 completely unauthenticated (PII, payment secret keys, subscription grants) | **Critical** | 9.1 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N` | Yes (unauth) | Medium |
| CRIT-04 | Unsalted MD5 password hashing everywhere | **High→Critical (chained)** | 7.5 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` | Yes (offline after CRIT-02) | Medium |
| HIGH-01 | `Subscription::save_payment` trusts client-supplied payment data → free premium | High | 6.5 `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:N` | Yes (any logged-in user) | Low |
| HIGH-02 | Hardcoded DB credentials and weak `encryption_key` in config | High | 7.5 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` | Yes (repo/hosting) | Low |
| HIGH-03 | Stored XSS via unauthenticated `Admin::slider` insert, echoed raw on the homepage | Medium→High | 6.1 `AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N` | Yes (unauth) | Low |
| HIGH-04 | Admin updater unpacks attacker ZIP and `require`s `php_update.php` → RCE (post-auth) | High | 7.2 `AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:H/A:H` | Post-auth (reachable via CRIT-01) | Medium |
| MED-01 | Global CSRF protection disabled (`csrf_protection = FALSE`) | Medium | 6.5 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N` | Yes | Low |
| MED-02 | No login rate limiting/lockout; legacy API sends credentials in GET query string | Medium | 5.9 `AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:N/A:N` | Yes | Low |
| MED-03 | Broken access control on admin read endpoints (`request_manage`, `report_manage`, `view_modal`, `load_*`, `get_transaction_details`) | Medium | 5.3 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N` | Yes (unauth) | Low |
| MED-04 | `uploads/` has no PHP-execution block; several `move_uploaded_file()` calls bypass validation | Medium | 5.0 `AV:N/AC:H/PR:L/UI:N/S:U/C:L/I:L/A:L` | Yes (post-auth/partial) | Low |
| MED-05 | `ENVIRONMENT='development'` + `db_debug` → verbose error/SQL disclosure | Medium | 5.3 `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N` | Yes | Low |
| MED-06 | Session cookie lacks `Secure`; SameSite not set; no regenerate-on-login | Low→Medium | 3.7 `AV:N/AC:H/PR:N/UI:R/S:U/C:L/I:N/A:N` | Yes | Low |
| MED-07 | Open redirect in comment/replay handlers (`redirect($url)` on user input) | Medium | 4.3 `AV:N/AC:L/PR:N/UI:R/S:U/C:N/I:L/A:N` | Yes (logged-in) | Low |
| MED-08 | End-of-life framework + outdated vendored dependencies (Guzzle 6.5.3, phpseclib 2.0.27, google/apiclient 2.4.1, firebase/php-jwt 5.2.0) | Medium | 7.5 (per-CVE; **not verified offline**) | Unknown | Medium |
| LOW-01 | Reflected output not escaped in search results / header inputs | Low | 4.3 | Yes (filter-dependent) | Low |
| LOW-02 | Password reset emails plaintext new password; predictable `md5(email)` default password | Low | 3.7 | Yes | Low |
| LOW-03 | No security headers (CSP/HSTS/X-Frame-Options/nosniff/Referrer-Policy); `robots.txt` advertises `/admin/` | Low | 3.7 | Yes | Low |

> **EPSS / CISA KEV:** not consulted (offline mode, no live NVD/OSV queries). Scores are CVSS v3.1 base only; treat prioritisation as CVSS × business impact.

---

## 2. Detailed Findings

### CRIT-01 — Unauthenticated arbitrary database modification

**CVSS:** 9.4 Critical — `AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:H/A:H`
**CWE-306** Missing Authentication for Critical Function · **CWE-862** Missing Authorization
**OWASP:** A01:2021 Broken Access Control

**Affected**
- `application/controllers/Admin.php:3731` — `delete_record()`
- `application/controllers/Admin.php:3758` — `update_status()`
- Related ungated writes: `slider()` `:250`, `api_setting()` `:2454`, `import_movie()` `:3828`, `video_file()` `:3921`, `episodes_url()` `:3937`, `rating()` `:3963`, `download_link()` `:3898`

**Description.** `Admin_Core_Controller` performs no authentication (`application/core/Core_Controller.php:12-19`); every admin method is expected to repeat
`if ($this->session->userdata('admin_is_login') != 1) redirect(...)`.
These methods — reachable at `/admin/<method>` through default CodeIgniter routing — omit that check. They are protected only by `is_demo()`, which is `false` here (`config.php:22`).

**Evidence (source excerpt).**
```php
// Admin.php:3731 — no session check
function delete_record(){
    $row_id     = $this->input->post('row_id');
    $table_name = $this->input->post('table_name');
    $table_row_id = ($table_name == 'homepage_sections') ? 'id' : $table_name.'_id';
    $this->db->where($table_row_id, $row_id);
    $query = $this->db->delete($table_name);
    ...
}
```
```php
// Admin.php:3758 — no session check, table/column/value all attacker-controlled
$table   = $this->input->post('table');
$column  = $this->input->post('column');
$value   = $this->input->post('value');
$data[$column] = $value;
$this->db->where($primary_key, $primary_key_value);
$this->db->update($table, $data);
```

**Impact.** An unauthenticated attacker can, with two POSTs:
1. Set an admin's password: `table=user&primary_key=user_id&primary_key_value=1&column=password&value=<md5>` → log in as admin.
2. Corrupt/disable the site (`config` table: `base_url`, `cron_key`, payment keys), alter content, or delete rows from any table (availability/integrity loss).

Unauthenticated inserts also exist via `Admin::video_file` / `Admin::episodes_url` (attacker-defined streaming URLs injected into `video_file`/`episodes`), and `Admin::api_setting` can rotate the mobile API secret key and create new REST credentials.

**Remediation.**
1. Enforce authorisation centrally in `Admin_Core_Controller::__construct()` — not per method — and return 403 for unauthenticated requests.
2. Whitelist `table`, `column`, and `primary_key` values in `delete_record`/`update_status`; never accept identifiers from the request.
3. Remove/refactor `video_file`, `episodes_url`, `import_movie`, `rating`, `api_setting` behind explicit admin checks and CSRF.
4. Audit the remaining 108 admin methods for the same pattern.

---

### CRIT-02 — Database backup exposed in the web root

**CVSS:** 7.5 High base; **Critical in context** (contains authentication material and live secrets)
**CWE-538** File/Directory Information Exposure · **CWE-552**
**OWASP:** A01 / A05

**Affected.** `db_backup/db_backup_2024-11-03-11-57-30.sql` (SHA-256 `37d99098…a24852`), 3,205,502 bytes, directly under the document root. Only `db_backup/index.html` exists — no `.htaccess`/server rule restricts `.sql`.

**Evidence.** The dump contains 123 `INSERT INTO user` rows with real emails and unsalted MD5 hashes, plus:
```
config  mobile_apps_api_secret_key = 229cfa2fd1b9787
config  cron_key                   = d5f9dfa661561fc
config  google_client_secret       = GOCSPX-…   (Google OAuth client secret)
config  paypal_client_id           = AY64xyiiKs9… 
```
The mobile API key is the only gate on the legacy `/api/*` endpoints; the cron key authorises `/cron/image` and `/cron/email`.

**Impact.** Anyone who can guess the path downloads all customer PII, all password hashes (trivially crackable — see CRIT-04), and the integration secrets needed to abuse the legacy API and payment integrations.

**Remediation.** Delete the file from the web root (keep backups off-server), add `Require all denied` for `^/db_backup`, and rotate **every** secret in the dump (DB password, mobile API key, cron key, Google OAuth secret, PayPal credentials). Treat all 123 user passwords as compromised.

---

### CRIT-03 — REST API v100/v130 is entirely unauthenticated

**CVSS:** 9.1 Critical — `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N`
**CWE-306 / CWE-862 / CWE-522**
**OWASP:** API1/API2/API3 (API Security Top 10), A01/A02/A07

**Affected.** `application/controllers/rest_api/V130.php` and `V100.php` (≈2,400 lines). `application/config/rest.php:113` sets `rest_auth = FALSE` and the controllers perform **zero** key/token checks (`grep -ci "secret\|api_key" V130.php` → `0`).

**Representative endpoints (all anonymous):**
| Endpoint | Exposure |
|---|---|
| `GET /rest-api/v130/user_details_by_user_id_get?id=N` | name, email, phone, join date, last login (`:393`) |
| `GET /rest-api/v130/user_details_by_email_get?email=…` | same, plus user enumeration (`:431`) |
| `POST /rest-api/v130/user_info_by_code_post` | returns the **entire `user` row** — password hash, token, Firebase UID (`:904`, model `:1583` returns `SELECT *`) |
| `GET /rest-api/v130/config_get` | `stripe_secret_key`, `paypal_client_id`, ads config (`:604`, model `:2509`) |
| `POST /rest-api/v130/store_payment_info_post` | inserts a paid subscription for any `user_id` (`:988`) |
| `GET /rest-api/v130/favorite_get?user_id=N` | any user's watchlist (`:621`) |
| `POST /rest-api/v130/password_reset_post` | triggers password reset email for any address (`:734`) |

**Evidence.**
```php
// V130.php:988
public function store_payment_info_post() {
    $data['user_id'] = $this->input->post('user_id');
    $data['plan_id'] = $this->input->post('plan_id');
    ...
    $data['status']  = 1;
    $this->db->insert('subscription', $data);   // no payment verification, no auth
}
```

**Impact.** Mass PII disclosure, premium-content bypass for arbitrary accounts, payment-provider secret key disclosure, and account takeover path via `user_info_by_code_post` + `set_password_post` (Firebase UID included in the leaked row; brute-forceable numeric TV codes).

**Remediation.** Enable `rest_auth` (or a signed token/JWT) and enforce it server-side per method; remove secret keys from `config_get`; require authenticated user identity rather than a `user_id` parameter; add rate limits and lockouts on login/signup/reset. Restrict `get_user_info_by_user_id` to a whitelisted column set (never `password`, `token`, `firebase_auth_uid`).

---

### CRIT-04 — Unsalted MD5 password hashing (all user classes)

**CVSS:** 7.5 High (Critical when combined with CRIT-02)
**CWE-916** Use of Password Hash With Insufficient Computational Effort · **CWE-759**
**OWASP:** A02:2021 Cryptographic Failures

**Affected (representative).**
`Login.php:44`, `Login.php:140/178/233`; `User.php:148` (default password = `md5(email)`), `User.php:194`; `Admin.php:1978/1991/3705-3707`; `rest_api/V100.php:292/453/527`; `rest_api/V130.php:317/476/550`; `Legacy_api.php:631/890`.

**Evidence.**
```php
$password = md5($_POST["password"]);                 // Login.php:44
$data['password'] = md5($user_info['email']);        // User.php:148 — guessable
```
Dump confirms format: `d5567dbed4fb04008c8beb5245c95331` for the admin account.

**Impact.** With the dump exposed (CRIT-02), all 123 accounts — including `role='admin'` — can be cracked at billions of guesses/second on commodity GPUs. Reused passwords elsewhere are compromised too.

**Remediation.** Migrate to `password_hash(PASSWORD_ARGON2ID)` / `password_verify`, rehash on next login; force a reset for all accounts; never derive a password from an email. Salted bcrypt is acceptable if Argon2 is unavailable.

---

### HIGH-01 — Client-trusted payment confirmation

**CVSS:** 6.5 Medium — `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:N`
**CWE-602** Client-Side Enforcement of Server-Side Security · **CWE-345**

**Affected.** `application/controllers/Subscription.php:642` (`save_payment`). Compare: legacy `Legacy_api.php:1636` and `V130.php:988` (same pattern).

**Evidence.**
```php
$plan_id        = $_POST['plan_id'];
$payment_method = $_POST['payment_method'];
$payment_info   = $_POST['payment_info'];
...
$data['status'] = 1;
$this->db->insert('subscription', $data);   // no gateway callback validation
```

**Impact.** Any logged-in user (free signup) can POST a plan and obtain premium access indefinitely; records are marked paid with attacker-chosen `payment_info`.

**Remediation.** Only mark a subscription paid after server-side verification of the gateway response (Stripe webhook signature, PayPal IPN `validate_ipn`, Razorpay signature check — the Razorpay flow at `:481` does this correctly; replicate it for `save_payment`). Use idempotency keys and server-generated amounts.

---

### HIGH-02 — Hardcoded production credentials and weak encryption key

**CVSS:** 7.5 High
**CWE-798** Use of Hard-coded Credentials · **CWE-321** Hard-coded Cryptographic Key

**Affected.** `application/config/database.php:79-83` (host/user/password/database), `application/config/config.php:232` (`encryption_key = '<redacted>'` — 60 bits of hex, below any modern guidance).

**Evidence.**
```php
'hostname' => 'localhost',
'username' => 'u473259675_pp_stream_1',
'password' => '<redacted>',
'database' => '<redacted>',
```

**Impact.** Credential reuse across environments, exposure via source leakage/backups, and a trivially brute-forceable session/encryption key. The same credentials are also present in the public SQL dump's deployment context.

**Remediation.** Move secrets to environment variables (CI3 `getenv()` in a non-committed `config/database.php` or a secrets manager); rotate the DB password; generate a 256-bit encryption key (`bin2hex(random_bytes(32))`) and rotate it.

---

### HIGH-03 — Stored XSS via unauthenticated slider insert

**CVSS:** 6.1 Medium (High impact in this app because admin pages render it)
**CWE-79** · **OWASP:** A03:2021 Injection

**Affected.** `Admin.php:250` (`slider()`, no auth) → `application/views/theme/default/main_slider.php:78-83` echoes `title`, `description`, `action_btn_text`, `image_link` unescaped. Also `views/admin/request_manage.php:33-36` echoes user-submitted movie requests raw (submitted anonymously via `Home::send_movie_request`, `Home.php:454`).

**Evidence.**
```php
<h2><?php echo $slider->title;?></h2>
<p class="sc-desc"><?php echo $slider->description;?></p>
```
Total `html_escape`/`htmlspecialchars` usage in `application/views` = **5** occurrences.

**Impact.** Persistent JavaScript on the public homepage — defacement, credential phishing, forced subscription redirects, and actions on behalf of any admin who views affected pages.

**Remediation.** Authenticate `slider()`; escape all output (`html_escape()`), add a CSP; sanitise rich content with an allow-list HTML purifier server-side.

---

### HIGH-04 — Admin updater → remote code execution

**CVSS:** 7.2 High (requires admin session — obtainable via CRIT-01)
**CWE-434** Unrestricted Upload of File with Dangerous Type · **CWE-22**

**Affected.** `application/controllers/Updater.php:54` (`extractTo`, no path/entry validation), `:115` (`require './update/…/php_update.php'`), plus `Common_model::restore_backup()` (`:165`) which writes `uploads/backup.sql` and executes arbitrary SQL.

**Evidence.**
```php
$zip->extractTo($update_dir);           // zip-slip / overwrite web files
...
require './update/' . $unzip_file_name . '/php_update.php';   // arbitrary PHP execution
```

**Impact.** Full server compromise (webshell, persistence, lateral movement) once any admin session exists — including the session obtained through CRIT-01.

**Remediation.** Remove the web-based updater or gate it behind re-authentication + signed update packages; validate zip entries and target paths; never `require` uploaded files.

---

### MED-01 — CSRF protection globally disabled

**CVSS:** 6.5 · **CWE-352** · **OWASP:** A01
**Affected.** `application/config/config.php:299` `csrf_protection = FALSE`; no CSRF tokens in any theme form. Session cookies use `SameSite` unset.

**Impact.** Combined with the admin endpoints, a single malicious page can drive state changes (delete content, change settings) in a logged-in admin's browser. Because CRIT-01 is unauthenticated, CSRF is not required for the worst impact — but it removes the last barrier for any endpoint that *does* check the session.

**Remediation.** Enable CI3 CSRF (`csrf_protection = TRUE`, `csrf_regenerate = TRUE`) or add per-form tokens; set `SameSite=Lax/Strict` in `system/libraries/Session/Session.php` config (`sess_samesite`).

---

### MED-02 — Credential handling in URLs; no brute-force protection

**CVSS:** 5.9 · **CWE-598** Use of GET Request Method With Sensitive Query Strings · **CWE-307**
**Affected.** `Legacy_api.php:622-631` (`login` reads `email`/`password` from `$_GET`), same pattern on `signup`, `update_profile`, `deactivate_account`; `Login.php:82-121` `validate_login` has no attempt counter, delay, CAPTCHA, or lockout; `V130.php:315` likewise.

**Impact.** Passwords appear in web-server access logs, proxies, browser history, and `Referer` headers; unlimited online guessing against admin accounts (MD5 hashes make offline checks cheap once a dump is obtained).

**Remediation.** Accept credentials only in POST bodies over TLS; add per-IP/per-account throttling and lockout; log and alert failed-login bursts.

---

### MED-03 — Unauthenticated admin read endpoints

**CVSS:** 5.3 · **CWE-862** · **OWASP:** A01
**Affected.** `Admin.php:5018` `request_manage`, `:5055` `report_manage`, `:4976` `get_transaction_details`, `:3655` `view_modal`, `:4430+` `load_stars`/`load_movie`/`load_tvseries`/`load_tv_channel`, `:3682` `profile` (partial), `:3731` `delete_record`, `:3758` `update_status`.

**Impact.** Anonymous users can read the movie-request queue (names, emails, messages), reports, and transaction/payment details; `view_modal` reflects an attacker-controlled view name (`admin/'.$page_name.'.php`) and can be abused for path traversal / internal view disclosure.

**Remediation.** Central auth in `Admin_Core_Controller`; whitelist `$page_name`; return 404/403 instead of rendering.

---

### MED-04 — Uploads directory can execute PHP; validation bypasses

**CVSS:** 5.0 · **CWE-434 / CWE-646**
**Affected.** `uploads/` contains no `.htaccess` and no `php_flag engine off`; `Admin.php:65,86,122,285` use raw `move_uploaded_file($_FILES[...]['tmp_name'], 'uploads/...png')` without the CI upload library; `V130.php:503` writes `uploads/user_image/<user_id>.jpg` from any uploaded content.

**Impact.** Defence-in-depth gap: any future upload flaw (or the updater, HIGH-04) yields PHP execution. Raw moves allow arbitrary file content with attacker-chosen image names; if a handler maps `.php`/`.phtml`, this becomes direct RCE.

**Remediation.** Drop `.htaccess` in `uploads/` (`php_flag engine off`, `RemoveHandler .php .phtml .phar`), validate MIME + extension + re-encode images with GD/Imagick, store uploads outside the document root and serve via a controller.

---

### MED-05 — Development mode and verbose errors

**CVSS:** 5.3 · **CWE-209** · **OWASP:** A05
**Affected.** `index.php:24` `define('ENVIRONMENT','development')`; `database.php:86` `db_debug => (ENVIRONMENT !== 'production')`; `config.php:52-53` display_errors on.

**Impact.** SQL errors, file paths, stack traces, and framework internals disclosed on failure — useful for injection and path discovery.

**Remediation.** Set `ENVIRONMENT='production'`, `log_threshold` ≥ 1, hide errors from responses, and log server-side.

---

### MED-06 — Session hardening gaps

**CVSS:** 3.7 · **CWE-614 / CWE-1004**
**Affected.** `config.php:252-258` (`sess_driver=database`, `sess_expiration=0`, `sess_regenerate_destroy=FALSE`), `:274` `cookie_secure=FALSE`; `cookie_samesite` absent (CI 3.1.13 defaults to `Lax` only if PHP provides it); no `session_regenerate_id(true)` on login (`Login.php:82-121`).

**Impact.** Cookies can be sent over plaintext HTTP; sessions survive password changes; fixation window on shared/public devices.

**Remediation.** `cookie_secure=TRUE`, `sess_samesite='Lax'`, regenerate session ID on privilege change and logout, short idle timeout for admin sessions.

---

### MED-07 — Open redirect

**CVSS:** 4.3 · **CWE-601** · **OWASP:** A01
**Affected.** `application/controllers/Comments.php:40,50,57,71,79,91,99,110` (`$url = $this->input->post('url'); redirect($url);`) — comment, replay and blog-comment handlers.

**Impact.** Phishing links on the trusted domain after login; can chain with stored XSS.

**Remediation.** Allow only same-origin paths (reject scheme/host or `//`), or use `base_url()` with an allow-list.

---

### MED-08 — End-of-life framework and outdated dependencies

**CVSS:** 7.5 per-CVE (not verified offline)
**Affected.**
- CodeIgniter **3.1.13** (`system/core/CodeIgniter.php`) — final CI3 release (2022), EOL; no security support.
- Vendored Google stack: `google/apiclient 2.4.1`, `google/auth 1.8.0`, `guzzlehttp/guzzle 6.5.3`, `guzzlehttp/psr7 1.6.1`, `phpseclib/phpseclib 2.0.27`, `firebase/php-jwt 5.2.0`, `monolog 2.0.2` (`application/third_party/google-api-client/vendor/composer/installed.json`).
- `stripe-php 9.6.0` (`application/libraries/stripe/lib/Stripe.php:61`).

**Impact.** Guzzle 6.5.3 is affected by **CVE-2022-31042 / CVE-2022-31043 / CVE-2022-31090** (fixed in 6.5.8+); phpseclib 2.0.27 and firebase/php-jwt 5.2.0 are several years behind. **These CVE claims require confirmation against NVD/OSV and a reachability check before remediation prioritisation.**

**Remediation.** Migrate to CodeIgniter 4 (or at minimum a supported PHP framework) and upgrade vendor packages; add `composer audit` / Dependabot to CI; generate an SBOM and VEX statements.

---

### LOW-01 — Unescaped reflected output

**CVSS:** 4.3 · **CWE-79**
**Affected.** `views/theme/default/search_results.php:7,40` and `views/theme/default/header.php:51` echo `$search_keyword` raw; the controller applies `$this->input->get('q', TRUE)` (`Home.php:117`), which runs CI3 `xss_clean` — a block-list filter, **not** contextual escaping. Contexts here are HTML text and a quoted attribute.

**Remediation.** `html_escape($search_keyword)` at every sink; abandon `xss_clean` as an output defence.

---

### LOW-02 — Weak password-reset and default-password design

**CVSS:** 3.7 · **CWE-640 / CWE-521**
**Affected.** `V130.php:734` (`password_reset_post` emails a plaintext random password and stores it in the DB), `User.php:148` (`md5(email)` initial password), password minimum length 4 (`form_validation` rules across login/signup/API).

**Remediation.** Time-limited single-use reset tokens, never email passwords, minimum 12 characters, breach-list check.

---

### LOW-03 — Missing security headers / admin discovery

**CVSS:** 3.7 · **CWE-693**
**Affected.** `.htaccess` (no header directives), `robots.txt` (`Disallow: /admin/` advertises the panel), no CSP/HSTS/X-Frame-Options/nosniff/Referrer-Policy.

**Remediation.** Add headers at the web-server layer; do not list admin paths in `robots.txt`; consider IP allow-listing or VPN for `/admin`.

---

## 3. Attack Chain (validated statically, not executed)

```
1. GET  /db_backup/db_backup_2024-11-03-11-57-30.sql
      → 123 user rows, admin hash, mobile_apps_api_secret_key, cron_key,
        Google OAuth secret, PayPal client ID
2. Crack admin MD5 (or skip straight to step 3 — no credential needed)
3. POST /admin/update_status
      table=user&primary_key=user_id&primary_key_value=1
      &column=password&value=<md5 of chosen password>
4. POST /login/do_login  → admin session (session fixation/CSRF not required)
5. POST /updater/process with crafted ZIP
      → extractTo + require php_update.php → arbitrary PHP execution
6. Optional: GET /rest-api/v130/config_get → stripe_secret_key, paypal_client_id
```

Severity of the chain: **Critical (10.0)** — unauthenticated → admin → RCE → payment-credential and PII exfiltration.

---

## 4. Remediation Roadmap

| Priority | Action | Effort | Risk removed |
|---|---|---|---|
| **Immediate (24h)** | Delete/move `/db_backup/*.sql`; deny `.sql`/`.bak`/`.zip` in web root; rotate DB password, mobile API key, cron key, Google OAuth secret, PayPal credentials, `encryption_key` | 1–2 h | CRIT-02, HIGH-02 |
| **Immediate (24h)** | Remove the unauthenticated admin methods from exposure; enforce auth centrally in `Admin_Core_Controller` | 2–4 h | CRIT-01, MED-03, HIGH-03, HIGH-04 |
| **Immediate (24h)** | Set `ENVIRONMENT='production'`, `db_debug=FALSE`, `display_errors=0` | 5 min | MED-05 |
| **This week** | Put authentication on v100/v130 (`rest_auth` + token), strip `password`/`token`/`firebase_auth_uid` from responses, remove secret keys from `config_get`; require POST for credentials | 1–2 days | CRIT-03, MED-02 |
| **This week** | Migrate passwords to Argon2id/bcrypt; force reset for all 123 accounts; fix `md5(email)` default | 1–2 days | CRIT-04 |
| **This week** | Server-side payment verification for `save_payment` | 4 h | HIGH-01 |
| **This month** | Enable CSRF + SameSite + `Secure`; add login throttling/lockout | 1 day | MED-01/06/02 |
| **This month** | Escape all output; add CSP; sanitise rich text; fix open redirect | 2–3 days | HIGH-03, LOW-01, MED-07 |
| **This month** | Harden `uploads/` (no exec, re-encode images, off-document-root storage) | 1 day | MED-04 |
| **Quarter** | Replace CI3 with a supported framework or backport; upgrade vendored libs; add `composer audit`, SBOM, dependency scanning to CI | Weeks | MED-08 |
| **Ongoing** | Add WAF rules for `/admin/*` and `/rest-api/*`; monitor for the endpoints above; external ASM scan | — | Defence in depth |

---

## 5. Verification Notes & Limitations

- **Mode:** offline source review only. No requests were made to `tentukotta.in`; nothing was exploited.
- **Observed:** file contents, line references, and hash values recorded above.
- **Inferred:** production reachability of paths (routing/`.htaccess` behaviour), and whether the live database matches the 2024 dump. The dump is dated 2024-11-03; treat its secrets as compromised regardless.
- **Unverified:** exact CVE applicability for vendored dependencies (no NVD/OSV query in offline mode); reflected-XSS bypass of CI3 `xss_clean`; `view_modal` traversal depth.
- **Not assessed:** TLS configuration, HTTP response headers live, hosting-level WAF, DNS/email security (SPF/DKIM/DMARC), the Android app, infrastructure, and business logic beyond the payment flow.
- **Recommended next step:** passive external validation (TLS/cipher, headers, `/db_backup` reachability) and, with written authorisation, an authorised-active test of CRIT-01/CRIT-03.

## 6. Evidence Hashes (SHA-256)

```
7a1e3b6ec5bf6d18ee922a42ec32615dfd233f53a6325e967db5b33ba81ff697  index.php
b4b4aaaad10a39fda7828823498c378eadb1cee6633f7b48e0c50dd10016d2a2  application/config/config.php
f2934782e1b616e71f88ee6a274ee8f7083789e5d3028756bfa6fc87eba0a9f5  application/config/database.php
0c5e1bade5a1ecaed1c456eb9ff216c3fff7a73a4c28e09f6508afaee2593f62  application/controllers/Admin.php
49b3bea955fefd998bc5252ad978294bccc8befb1cd30eb3545766897b86e8a6  application/controllers/rest_api/V130.php
9f5002f9b7b90260228e1e2e576b1cc7f0b07a61509a9cfd59106e259630a212  application/controllers/Legacy_api.php
47a173446817b3f201cda39c22338c467109d4a7f209e6491e8e60e99ea24852  application/controllers/Subscription.php
37d99098f1dd67405edd1f73df3c0f2d944a93b0000e2df0097fa85f501dc9f9  db_backup/db_backup_2024-11-03-11-57-30.sql
e73e090f293668364c2b520529db9f7e731a6117104074694510c2bd050c54f5  .htaccess
```
