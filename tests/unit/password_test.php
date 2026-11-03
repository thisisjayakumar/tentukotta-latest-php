<?php
/**
 * Characterization tests for the Password library (Argon2id + legacy MD5).
 */

require_once app_file('libraries/Password.php');

test('password: hash is not the plaintext and is long enough', function () {
    $hash = Password::hash('correct horse battery staple');
    assert_true($hash !== 'correct horse battery staple');
    assert_true(strlen($hash) > 30);
});

test('password: verifies the correct password', function () {
    assert_true(Password::verify('s3cret-pass', Password::hash('s3cret-pass')));
});

test('password: rejects a wrong password', function () {
    assert_false(Password::verify('wrong', Password::hash('right')));
});

test('password: verifies a legacy md5 hash (case insensitive)', function () {
    assert_true(Password::verify('legacy-pass', md5('legacy-pass')));
    assert_true(Password::verify('legacy-pass', strtoupper(md5('legacy-pass'))));
});

test('password: needs_rehash is true for md5 and false for a fresh hash', function () {
    assert_true(Password::needs_rehash(md5('anything')));
    assert_false(Password::needs_rehash(Password::hash('anything')));
});

test('password: an empty stored hash never verifies', function () {
    assert_false(Password::verify('anything', ''));
});

test('password: random() is 64 hex chars and unique', function () {
    $a = Password::random();
    $b = Password::random();
    assert_same(64, strlen($a));
    assert_true(preg_match('/^[a-f0-9]{64}$/', $a) === 1);
    assert_true($a !== $b);
});
