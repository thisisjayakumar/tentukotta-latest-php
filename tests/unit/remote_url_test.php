<?php
/**
 * Tests for the SSRF guards used by remote image fetching.
 */

require_once app_file('helpers/custom_helper.php');

function test_resolver(string $host): array
{
    if ($host === 'example.com') {
        return ['93.184.216.34'];
    }
    if ($host === 'private.example.test') {
        return ['10.0.0.5'];
    }
    return [];
}

test('is_private_ip: flags loopback, private and reserved addresses', function () {
    foreach ([
        '127.0.0.1',
        '127.1.2.3',
        '10.0.0.5',
        '172.16.0.1',
        '192.168.1.1',
        '169.254.169.254',
        '100.64.0.1',
        '0.0.0.0',
        '::1',
        'fe80::1',
    ] as $ip) {
        assert_true(is_private_ip($ip), $ip);
    }
});

test('is_private_ip: does not flag public addresses', function () {
    foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
        assert_false(is_private_ip($ip), $ip);
    }
});

test('is_private_ip: unparseable input is treated as unsafe', function () {
    assert_true(is_private_ip('not-an-ip'));
    assert_true(is_private_ip(''));
});

test('is_safe_remote_url: accepts public http and https URLs', function () {
    assert_true(is_safe_remote_url('https://example.com/a.jpg', 'test_resolver'));
    assert_true(is_safe_remote_url('http://example.com:8080/path/a.png?x=1', 'test_resolver'));
});

test('is_safe_remote_url: accepts public literal IPs', function () {
    assert_true(is_safe_remote_url('https://8.8.8.8/logo.png'));
});

test('is_safe_remote_url: rejects non-http schemes', function () {
    foreach ([
        'file:///etc/passwd',
        'gopher://example.com/',
        'ftp://example.com/a.jpg',
        'data:text/plain,hello',
        'phar://example.com/a.phar',
    ] as $url) {
        assert_false(is_safe_remote_url($url, 'test_resolver'), $url);
    }
});

test('is_safe_remote_url: rejects loopback, private and link-local literals', function () {
    foreach ([
        'http://127.0.0.1/',
        'http://10.0.0.5/a.jpg',
        'http://192.168.0.1/',
        'http://169.254.169.254/latest/meta-data/',
        'http://[::1]/',
        'http://0.0.0.0/',
    ] as $url) {
        assert_false(is_safe_remote_url($url), $url);
    }
});

test('is_safe_remote_url: rejects credentials in the URL', function () {
    assert_false(is_safe_remote_url('http://user:pass@example.com/a.jpg', 'test_resolver'));
    assert_false(is_safe_remote_url('http://user@example.com/a.jpg', 'test_resolver'));
});

test('is_safe_remote_url: rejects numeric and hex host encodings', function () {
    assert_false(is_safe_remote_url('http://2130706433/'));
    assert_false(is_safe_remote_url('http://0x7f000001/'));
    assert_false(is_safe_remote_url('http://017700000001/'));
});

test('is_safe_remote_url: rejects a host resolving to a private address', function () {
    assert_false(is_safe_remote_url('https://private.example.test/a.jpg', 'test_resolver'));
});

test('is_safe_remote_url: rejects a host that does not resolve', function () {
    assert_false(is_safe_remote_url('https://unresolvable.example.test/a.jpg', 'test_resolver'));
});

test('is_safe_remote_url: rejects empty input and control characters', function () {
    assert_false(is_safe_remote_url(''));
    assert_false(is_safe_remote_url("http://example.com/a\nb.jpg", 'test_resolver'));
    assert_false(is_safe_remote_url("http://example.com/a\x00b.jpg", 'test_resolver'));
});
