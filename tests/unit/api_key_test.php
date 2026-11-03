<?php
/**
 * Tests for timing-safe API key comparison.
 */

require_once app_file('libraries/Api_key.php');

test('api_key: accepts an exact match', function () {
    assert_true(Api_key::verify('abc123', 'abc123'));
});

test('api_key: rejects a different value', function () {
    assert_false(Api_key::verify('abc123', 'abc124'));
    assert_false(Api_key::verify('abc123', 'abc12'));
});

test('api_key: rejects an empty expected or supplied key', function () {
    assert_false(Api_key::verify('', ''));
    assert_false(Api_key::verify('', 'abc'));
    assert_false(Api_key::verify('abc', ''));
});

test('api_key: rejects non-string input', function () {
    assert_false(Api_key::verify('abc', null));
    assert_false(Api_key::verify('abc', ['abc']));
    assert_false(Api_key::verify(null, 'abc'));
});

test('api_key: accepts numeric strings without coercion surprises', function () {
    assert_true(Api_key::verify('0123', '0123'));
    assert_false(Api_key::verify('0123', '123'));
});
