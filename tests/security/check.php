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

// -----------------------------------------------------------------------------
// Harness sanity: prove the harness itself reports failures correctly.
// These two lines are temporary and get removed once the environment is set.
// -----------------------------------------------------------------------------
check('harness: index.php exists', fn() => is_file(root() . '/index.php'));
check('harness: impossible assertion fails', fn() => false);

exit($fails > 0 ? 1 : 0);
