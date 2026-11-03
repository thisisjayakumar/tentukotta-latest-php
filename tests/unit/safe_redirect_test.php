<?php
/**
 * Tests for safe_redirect() in application/helpers/custom_helper.php.
 *
 * The CI redirect() function is stubbed by tests/bootstrap.php and records
 * its target in $GLOBALS['__redirect_target'].
 */

require_once app_file('helpers/custom_helper.php');

function redirect_target_for(?string $url): ?string
{
    $GLOBALS['__redirect_target'] = null;
    safe_redirect($url);
    return $GLOBALS['__redirect_target'];
}

test('safe_redirect: allows a same-host absolute path', function () {
    assert_same('/movies', redirect_target_for('/movies'));
    assert_same('watch/abc', redirect_target_for('watch/abc'));
});

test('safe_redirect: rejects absolute external URLs', function () {
    foreach (['https://evil.test/x', 'http://evil.test', '//evil.test/x'] as $url) {
        assert_same('http://localhost:8080/', redirect_target_for($url), 'for ' . $url);
    }
});

test('safe_redirect: rejects dangerous schemes', function () {
    foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd'] as $url) {
        assert_same('http://localhost:8080/', redirect_target_for($url), 'for ' . $url);
    }
});

test('safe_redirect: rejects backslashes and control characters', function () {
    foreach (['\\evil.test', "/movies\nLocation: https://evil.test", "/a\x00b"] as $url) {
        assert_same('http://localhost:8080/', redirect_target_for($url));
    }
});

test('safe_redirect: empty and null fall back to the base URL', function () {
    assert_same('http://localhost:8080/', redirect_target_for(''));
    assert_same('http://localhost:8080/', redirect_target_for(null));
});
