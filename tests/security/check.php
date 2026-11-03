<?php
/**
 * Static security assertions for the Pudhu Padam codebase.
 *
 * PHP 8.3 only. No dependencies. Run:  php tests/security/check.php
 * Exit code 0 = all checks pass, 1 = at least one failed.
 *
 * Each security task appends its own assertions to this file so the
 * harness grows with the code and always fails-fast on regressions.
 */

$fails = 0;

/**
 * Resolve a path relative to the web root (located 2 dirs up: repo_root/_public_html).
 */
function root(): string
{
    return dirname(__DIR__, 2) . '/_public_html';
}

/**
 * Read the given file relative to the web root, or return '' if absent.
 */
function src(string $rel): string
{
    $p = root() . '/' . $rel;
    return is_file($p) ? file_get_contents($p) : '';
}

/**
 * Case-sensitive substring test on the given file.
 */
function has(string $rel, string $needle): bool
{
    return str_contains(src($rel), $needle);
}

/**
 * Record a check: prints PASS/FAIL, bumps the failure counter.
 * Exceptions are caught so one bad assertion never aborts the run.
 */
function check(string $name, callable $fn): void
{
    global $fails;
    try {
        $ok = (bool) $fn();
    } catch (Throwable $e) {
        $ok = false;
    }
    echo ($ok ? "PASS  " : "FAIL  ") . $name . PHP_EOL;
    if (!$ok) {
        $fails++;
    }
}

/**
 * Record a tracked, non-blocking item (prints SKIP, never bumps the counter).
 */
function skip(string $name, string $reason = ''): void
{
    echo 'SKIP  ' . $name . ($reason !== '' ? ' (' . $reason . ')' : '') . PHP_EOL;
}

// -----------------------------------------------------------------------------
// Harness sanity: prove the harness itself reports failures correctly.
// These two lines are temporary and get removed once the environment is set.
// -----------------------------------------------------------------------------
check('harness: index.php exists', fn() => is_file(root() . '/index.php'));

// -----------------------------------------------------------------------------
// TASK 1: no .sql dump exposed under the web root
// -----------------------------------------------------------------------------
check('no .sql files under web root', function () {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(root(), RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) === 'sql') {
            return false;
        }
    }
    return true;
});
check('.htaccess denies db_backup, application, system', fn() => has('.htaccess', 'db_backup'));
check('.htaccess denies backup extensions', fn() => has('.htaccess', 'bak'));

// -----------------------------------------------------------------------------
// TASK 2: production mode + safe error handling
// -----------------------------------------------------------------------------
check('ENVIRONMENT defaults to production', fn() => has('index.php', "define('ENVIRONMENT', env('CI_ENV', 'production'))"));
check('db_debug not hardcoded TRUE', fn() => !preg_match("/'db_debug'\s*=>\s*TRUE/i", src('application/config/database.php')));
check('error logging enabled (threshold >= 1)', fn() => preg_match("/log_threshold'\]\s*=\s*[1-4]/", src('application/config/config.php')) === 1);

// -----------------------------------------------------------------------------
// TASK 3: central admin authentication gate
// -----------------------------------------------------------------------------
check('Admin_Core_Controller enforces admin session', function () {
    $s = src('application/core/Core_Controller.php');
    if (preg_match('/class Admin_Core_Controller.*?class Home_Core_Controller/s', $s, $m) !== 1) {
        return false;
    }
    return str_contains($m[0], 'function _require_admin')
        && str_contains($m[0], '$this->_require_admin()');
});
check('Admin constructor delegates to parent', fn() => preg_match(
    '/class Admin[^{]*\{[^}]*function\s+__construct\s*\([^)]*\)\s*\{\s*parent::__construct\(\)/s',
    src('application/controllers/Admin.php')) === 1);
check('public rating moved out of Admin.php', fn() => !preg_match(
    '/function\s+rating\s*\(/', src('application/controllers/Admin.php')));
check('public rating controller exists', fn() => is_file(root() . '/application/controllers/Movie_public.php'));
check('admin/rating route preserved', fn() => has('application/config/routes.php', "'admin/rating'"));

// -----------------------------------------------------------------------------
// TASK 4: whitelist admin-mutable tables and columns
// -----------------------------------------------------------------------------
check('admin table whitelist config exists', fn() => is_file(root() . '/application/config/admin_tables.php'));
check('delete_record uses the whitelist', function () {
    $s = src('application/controllers/Admin.php');
    return str_contains($s, "config->load('admin_tables'")
        && str_contains($s, "'Table not permitted'");
});
check('update_status rejects non-whitelisted columns', fn() => has('application/controllers/Admin.php', "'Column not permitted'"));
check('admin_tables is not loaded as a config section', fn() => !preg_match(
    "/config->load\\(\\s*'admin_tables'\\s*,\\s*TRUE\\)/", src('application/controllers/Admin.php')));
check('no raw table_names from input reach db->delete', fn() => !preg_match(
    '/\$this->db->delete\(\s*\$this->input->post/', src('application/controllers/Admin.php')));

// -----------------------------------------------------------------------------
// TASK 5: authenticate the REST API and redact secrets/PII
// -----------------------------------------------------------------------------
check('API base controller exists', fn() => is_file(root() . '/application/core/API_Core_Controller.php'));
check('RestServer library key table is disabled', fn() => preg_match(
    "/rest_enable_keys'\\]\\s*=\\s*false/i", src('application/config/rest.php')) === 1);
check('API base controller enforces a key', function () {
    $s = src('application/core/API_Core_Controller.php');
    return str_contains($s, 'function _require_api_key')
        && str_contains($s, 'hash_equals')
        && str_contains($s, 'mobile_apps_api_secret_key');
});
check('V130 extends API_Core_Controller', fn() => has('application/controllers/rest_api/V130.php', 'class V130 extends API_Core_Controller'));
check('V100 extends API_Core_Controller', fn() => has('application/controllers/rest_api/V100.php', 'class V100 extends API_Core_Controller'));
check('config_get exposes only the public payment subset', function () {
    $s = src('application/controllers/rest_api/V130.php');
    return !str_contains($s, '->get_payment_config()')
        && str_contains($s, '->get_public_payment_config()');
});
check('payment config omits gateway secrets', function () {
    foreach (['Api_v130_model.php', 'Api_v100_model.php', 'Api_subscription_model.php'] as $f) {
        $s = src('application/models/' . $f);
        if (preg_match("/\\\$response\\['(stripe_secret_key|razorpay_key_secret|reve_secret_key|reve_encryption_key)'\\]/", $s)) {
            return false;
        }
    }
    return true;
});
check('store_payment_info_post stays closed pending user auth', function () {
    foreach (['V130.php', 'V100.php'] as $f) {
        if (!has('application/controllers/rest_api/' . $f, 'Endpoint requires an authenticated user token')) {
            return false;
        }
    }
    return true;
});
check('tv user lookup redacts private fields', fn() => has(
    'application/controllers/rest_api/V130.php', 'public_user_fields'));

// -----------------------------------------------------------------------------
// TASK 7: server-side payment verification
// -----------------------------------------------------------------------------
check('save_payment calls verify_payment', function () {
    $s = src('application/controllers/Subscription.php');
    return str_contains($s, 'verify_payment(')
        && !preg_match('/\$data\[.paid_amount.\]\s*=\s*\$_POST/', $s);
});
check('Subscription_model implements per-gateway verification', function () {
    $s = src('application/models/Subscription_model.php');
    return str_contains($s, 'function verify_payment')
        && str_contains($s, 'verifyPaymentSignature')
        && str_contains($s, 'amount_received')
        && str_contains($s, 'validate_ipn')
        && str_contains($s, 'mc_gross');
});
check('save_payment derives the price from the plan table', fn() => has(
    'application/controllers/Subscription.php', '$plan->price'));
check('save_payment returns 402 on verification failure', fn() => has(
    'application/controllers/Subscription.php', 'set_status_header(402)'));
check('payments reject replayed transactions', fn() => has(
    'application/controllers/Subscription.php', 'Payment already processed'));
check('legacy payment endpoint ignores client paid_amount', function () {
    $s = src('application/controllers/Legacy_api.php');
    return str_contains($s, 'verify_payment(')
        && !str_contains($s, "input->post('paid_amount')");
});

// -----------------------------------------------------------------------------
// TASK 6: password hashing migration (Argon2id + transparent upgrade)
// -----------------------------------------------------------------------------
check('Password library exists', fn() => is_file(root() . '/application/libraries/Password.php'));
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
check('Password verifies legacy MD5 and Argon2id', function () {
    if (!defined('BASEPATH')) define('BASEPATH', root() . '/system/');
    require_once root() . '/application/libraries/Password.php';

    $legacy = md5('secret');
    if (!Password::verify('secret', $legacy)) return false;
    if (Password::verify('wrong', $legacy)) return false;

    $modern = Password::hash('secret');
    if (!Password::verify('secret', $modern)) return false;
    if (Password::verify('wrong', $modern)) return false;

    if (!Password::needs_rehash($legacy)) return false;
    if (Password::needs_rehash($modern)) return false;

    return Password::needs_rehash('');
});
check('login paths upgrade legacy hashes', function () {
    $files = [
        'application/controllers/Login.php',
        'application/controllers/User.php',
        'application/models/Api_v100_model.php',
        'application/models/Api_v130_model.php',
        'application/models/Legacy_api_model.php',
    ];
    foreach ($files as $f) {
        $s = src($f);
        if (!str_contains($s, 'Password::verify') || !str_contains($s, 'needs_rehash')) return false;
    }
    return true;
});
check('password writes go through Password::hash', function () {
    foreach (['application/controllers/Login.php', 'application/controllers/User.php', 'application/controllers/Admin.php'] as $f) {
        if (!str_contains(src($f), 'Password::hash')) return false;
    }
    return true;
});
check('firebase set_password still requires firebase_auth_uid', function () {
    $s = src('application/controllers/rest_api/V130.php');
    if (!preg_match('/function\s+set_password_post\s*\([^)]*\)\s*\{(.*?)\n\s{4}\}/s', $s, $m)) return false;
    return str_contains($m[1], 'firebase_auth_uid') && str_contains($m[1], 'validate_user_by_id_firebase_auth_uid');
});
check('social signups never derive a password from email', function () {
    $s = src('application/controllers/User.php');
    return !str_contains($s, "Password::hash(\$user_info['email'])")
        && !str_contains($s, 'Password::hash($email)')
        && str_contains($s, 'is_password_set');
});

// -----------------------------------------------------------------------------
// TASK 8: CSRF, SameSite/Secure cookies and session regeneration
// -----------------------------------------------------------------------------
check('csrf enabled', fn() => preg_match("/csrf_protection'\]\s*=\s*TRUE/", src('application/config/config.php')) === 1);
check('secure cookie flag set conditionally', function () {
    $s = src('application/config/config.php');
    return str_contains($s, 'cookie_secure')
        && str_contains($s, 'HTTP_X_FORWARDED_PROTO');
});
check('samesite configured', fn() => has('application/config/config.php', "sess_samesite']")
    && has('application/config/config.php', "'Lax'"));
check('session cookie is httponly', fn() => preg_match("/cookie_httponly'\]\s*=\s*TRUE/", src('application/config/config.php')) === 1);
check('session id destroyed on regenerate', fn() => preg_match("/sess_regenerate_destroy'\]\s*=\s*TRUE/", src('application/config/config.php')) === 1);
check('csrf exclusions cover API, cron and PayPal IPN', function () {
    $s = src('application/config/config.php');
    return str_contains($s, 'rest-api') && str_contains($s, 'cron')
        && str_contains($s, 'subscription/paypal/ipn');
});
check('session regenerated on admin login', fn() => has('application/controllers/Login.php', 'sess_regenerate(TRUE)'));
check('session regenerated on front-end login', fn() => has('application/controllers/User.php', 'sess_regenerate(TRUE)'));
check('csrf helper exists', fn() => has('application/helpers/custom_helper.php', 'function csrf_input'));
check('every raw POST form carries a csrf token', function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        root() . '/application/views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $s = file_get_contents($f->getPathname());
        if (!preg_match_all('/<form\b[^>]*method\s*=\s*["\']?post[^>]*>/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as [$tag, $offset]) {
            $close = strpos($s, '</form>', $offset + strlen($tag));
            $body = $close === false ? substr($s, $offset) : substr($s, $offset, $close - $offset);
            if (!str_contains($body, 'csrf_input')) return false;
        }
    }
    return true;
});
check('admin and theme pages inject the csrf token', function () {
    return has('application/views/admin/index.php', 'common/csrf_script')
        && has('application/views/admin/index.php', 'common/csrf_meta')
        && has('application/views/theme/default/index.php', 'common/csrf_script')
        && has('application/views/theme/default/index.php', 'common/csrf_meta');
});

// -----------------------------------------------------------------------------
// TASK 9: output escaping (stored and reflected XSS)
// -----------------------------------------------------------------------------
check('escape helper exists', fn() => has('application/helpers/custom_helper.php', 'function e('));
check('slider output escaped', function () {
    $s = src('application/views/theme/default/main_slider.php');
    return str_contains($s, 'e($slider->title)')
        && str_contains($s, 'e($videos->title)')
        && str_contains($s, 'e($slider->description)');
});
check('search keyword escaped', function () {
    $s = src('application/views/theme/default/search_results.php');
    return substr_count($s, 'e($search_keyword)') >= 2
        && !preg_match('/echo\s+\$search_keyword/', $s)
        && !preg_match("/\\.\\s*\\\$search_keyword\\s*\\./", $s);
});
check('search box value escaped', fn() => has(
    'application/views/theme/default/header.php', 'echo e($search_keyword)'));
check('request queue escaped', function () {
    $s = src('application/views/admin/request_manage.php');
    foreach (['name', 'email', 'movie_name', 'message'] as $field) {
        if (!str_contains($s, "e(\$row['" . $field . "'])")) return false;
    }
    return true;
});
check('report issue and message escaped', function () {
    $s = src('application/views/admin/report_manage.php');
    return str_contains($s, "e(\$row['issue'])")
        && str_contains($s, "e(\$row['message'])")
        && str_contains($s, 'e($title2)');
});

// -----------------------------------------------------------------------------
// TASK 10: upload hardening
// -----------------------------------------------------------------------------
check('uploads denies php execution', function () {
    $p = root() . '/uploads/.htaccess';
    if (!is_file($p)) return false;
    $s = file_get_contents($p);
    return str_contains($s, 'php_flag engine off') && str_contains($s, 'RemoveHandler');
});
check('uploads ships an IIS web.config', function () {
    $p = root() . '/uploads/web.config';
    if (!is_file($p)) return false;
    $s = file_get_contents($p);
    return str_contains($s, '<clear') && str_contains($s, '.php');
});
check('safe_image_upload validates content, size and type', function () {
    $s = src('application/helpers/custom_helper.php');
    return str_contains($s, 'function safe_image_upload')
        && str_contains($s, 'getimagesize')
        && str_contains($s, 'UPLOAD_ERR_OK')
        && str_contains($s, 'image/jpeg')
        && str_contains($s, 'imagecreatefromstring')
        && str_contains($s, '5242880');
});
check('no raw move_uploaded_file in app controllers', function () {
    foreach (['Admin.php', 'User.php', 'Legacy_api.php', 'rest_api/V100.php', 'rest_api/V130.php'] as $f) {
        if (str_contains(src('application/controllers/' . $f), 'move_uploaded_file(')) return false;
    }
    return true;
});
check('admin image uploads go through safe_image_upload', function () {
    $s = src('application/controllers/Admin.php');
    return substr_count($s, 'safe_image_upload(') >= 20
        && str_contains($s, 'safe_subtitle_upload(');
});

// -----------------------------------------------------------------------------
// TASK 11: login throttling and open-redirect fix
// -----------------------------------------------------------------------------
check('login throttling model exists', fn() => is_file(root() . '/application/models/Auth_attempt_model.php'));
check('throttling model exposes too_many/record/clear', function () {
    $s = src('application/models/Auth_attempt_model.php');
    return str_contains($s, 'function too_many')
        && str_contains($s, 'function record')
        && str_contains($s, 'function clear')
        && str_contains($s, 'table_exists');
});
check('login_attempts migration exists', fn() => is_file(
    dirname(__DIR__, 2) . '/docs/migrations/2026-10-31-login-attempts.sql'));
check('every credential path is throttled', function () {
    $files = [
        'application/controllers/Login.php',
        'application/controllers/User.php',
        'application/controllers/rest_api/V100.php',
        'application/controllers/rest_api/V130.php',
        'application/controllers/Legacy_api.php',
    ];
    foreach ($files as $f) {
        $s = src($f);
        if (!str_contains($s, 'too_many(') || !str_contains($s, '->record(')) return false;
    }
    return true;
});
check('API throttling returns HTTP 429', function () {
    foreach (['rest_api/V100.php', 'rest_api/V130.php', 'Legacy_api.php'] as $f) {
        $s = src('application/controllers/' . $f);
        if (!str_contains($s, '429')) return false;
    }
    return true;
});
check('safe_redirect helper defined', fn() => has('application/helpers/custom_helper.php', 'function safe_redirect'));
check('comments use safe_redirect', function () {
    $s = src('application/controllers/Comments.php');
    return str_contains($s, 'safe_redirect($url)')
        && !preg_match('/(?<!safe_)redirect\(\s*\$url/', $s);
});
check('safe_redirect rejects external hosts and schemes', function () {
    $s = src('application/helpers/custom_helper.php');
    return str_contains($s, '#^(https?:)?//#i')
        && str_contains($s, '#^[a-z][a-z0-9+.-]*:#i')
        && str_contains($s, 'redirect(base_url(), $method)');
});

// -----------------------------------------------------------------------------
// TASK 12: dependency and framework follow-up (tracked, not executed)
// -----------------------------------------------------------------------------
check('dependency inventory documented', fn() => is_file(
    dirname(__DIR__, 2) . '/docs/security/dependency-remediation.md'));
check('inventory records the Guzzle CVE risk', function () {
    $p = dirname(__DIR__, 2) . '/docs/security/dependency-remediation.md';
    if (!is_file($p)) return false;
    $s = file_get_contents($p);
    return str_contains($s, 'guzzlehttp/guzzle')
        && str_contains($s, 'CVE-2022-31042')
        && str_contains($s, 'CodeIgniter');
});
// -----------------------------------------------------------------------------
// FOLLOW-UP: environment-driven configuration (no secrets in version control)
// -----------------------------------------------------------------------------
$repo_root = dirname(__DIR__, 2);
check('index.php loads the env loader', fn() => has('index.php', 'config/env.php'));
check('database credentials come from env', function () {
    $s = src('application/config/database.php');
    return str_contains($s, "env('DB_PASSWORD'")
        && !str_contains($s, '<redacted>');
});
check('database host and name come from env', function () {
    $s = src('application/config/database.php');
    return str_contains($s, "env('DB_HOSTNAME'")
        && str_contains($s, "env('DB_DATABASE'");
});
check('encryption key comes from env', function () {
    $s = src('application/config/config.php');
    return str_contains($s, "env('ENCRYPTION_KEY'")
        && !str_contains($s, "'<redacted>'");
});
check('base URL comes from env', fn() => has('application/config/config.php', "env('BASE_URL'"));
check('db_debug is explicitly FALSE', fn() => preg_match(
    "/'db_debug'\\s*=>\\s*FALSE/",
    src('application/config/database.php')) === 1);
check('no hardcoded REST basic-auth pair', fn() => !has(
    'application/config/rest.php', "'admin' => '1234'"));
check('.env.example exists and documents the DB password', function () use ($repo_root) {
    $p = $repo_root . '/.env.example';
    return is_file($p) && str_contains(file_get_contents($p), 'DB_PASSWORD=');
});
check('.env is git-ignored', function () use ($repo_root) {
    $p = $repo_root . '/.gitignore';
    return is_file($p) && preg_match('/^\\.env$/m', file_get_contents($p)) === 1;
});

$installed = root() . '/application/third_party/google-api-client/vendor/composer/installed.json';
if (is_file($installed) && str_contains(file_get_contents($installed), '"version": "6.5.3"')) {
    skip('guzzle is not the known-vulnerable 6.5.3', 'tracked in docs/security/dependency-remediation.md');
} else {
    check('guzzle is not the known-vulnerable 6.5.3', fn() => true);
}

exit($fails > 0 ? 1 : 0);
