<?php
/**
 * Tests for the dotenv loader in application/config/env.php.
 */

require_once app_file('config/env.php');

function write_env_file(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'envtest-');
    file_put_contents($path, $contents);
    return $path;
}

test('env: parses plain and quoted values', function () {
    $file = write_env_file("FOO=bar\nQUOTED=\"hello world\"\nSINGLE='single value'\n");
    env_load($file);
    assert_same('bar', env('FOO'));
    assert_same('hello world', env('QUOTED'));
    assert_same('single value', env('SINGLE'));
    unlink($file);
});

test('env: ignores comments, blank lines and malformed lines', function () {
    $file = write_env_file("# a comment\n\nNOT_A_PAIR\n=missingkey\nGOOD=yes\n");
    env_load($file);
    assert_same('yes', env('GOOD'));
    assert_same('fallback', env('NOT_A_PAIR', 'fallback'));
    unlink($file);
});

test('env: strips an unquoted trailing comment', function () {
    $file = write_env_file("WITH_COMMENT=value # trailing note\n");
    env_load($file);
    assert_same('value', env('WITH_COMMENT'));
    unlink($file);
});

test('env: does not overwrite an already-set process variable', function () {
    putenv('ENV_TEST_PRESET=keep');
    $file = write_env_file("ENV_TEST_PRESET=replace\n");
    env_load($file);
    assert_same('keep', env('ENV_TEST_PRESET'));
    putenv('ENV_TEST_PRESET');
    unlink($file);
});

test('env: returns the default when a key is missing', function () {
    assert_same('fallback', env('ENV_TEST_DEFINITELY_MISSING', 'fallback'));
    assert_same(null, env('ENV_TEST_DEFINITELY_MISSING'));
});

test('env: preserves an explicitly empty value', function () {
    putenv('ENV_TEST_EMPTY=');
    assert_same('', env('ENV_TEST_EMPTY', 'fallback'));
    putenv('ENV_TEST_EMPTY');
});

test('env: missing file is a no-op', function () {
    env_load(sys_get_temp_dir() . '/envtest-does-not-exist-' . uniqid());
    assert_true(true);
});

test('env_bool: recognises common truthy and falsy spellings', function () {
    putenv('ENV_TEST_BOOL_TRUE=true');
    putenv('ENV_TEST_BOOL_ONE=1');
    putenv('ENV_TEST_BOOL_OFF=off');
    putenv('ENV_TEST_BOOL_EMPTY=');
    assert_true(env_bool('ENV_TEST_BOOL_TRUE'));
    assert_true(env_bool('ENV_TEST_BOOL_ONE'));
    assert_false(env_bool('ENV_TEST_BOOL_OFF', true));
    assert_false(env_bool('ENV_TEST_BOOL_EMPTY', true));
    assert_true(env_bool('ENV_TEST_BOOL_MISSING', true));
    putenv('ENV_TEST_BOOL_TRUE');
    putenv('ENV_TEST_BOOL_ONE');
    putenv('ENV_TEST_BOOL_OFF');
    putenv('ENV_TEST_BOOL_EMPTY');
});
