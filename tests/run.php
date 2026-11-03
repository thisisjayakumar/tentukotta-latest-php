<?php
/**
 * Dependency-free test runner.
 *
 * Usage:
 *   php tests/run.php              run every tests/unit/*_test.php
 *   php tests/run.php password     run only tests whose name contains "password"
 *
 * Exit code 0 means every test passed.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

final class AssertionFailed extends RuntimeException
{
}

$GLOBALS['__test_state'] = [
    'passed'     => 0,
    'failed'     => 0,
    'assertions' => 0,
    'failures'   => [],
];

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function test(string $name, callable $fn): void
{
    $filter = $GLOBALS['__test_filter'] ?? null;
    if ($filter !== null && stripos($name, $filter) === false) {
        return;
    }

    try {
        $fn();
        $GLOBALS['__test_state']['passed']++;
        echo 'PASS  ' . $name . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['__test_state']['failed']++;
        $GLOBALS['__test_state']['failures'][] = $name;
        echo 'FAIL  ' . $name . PHP_EOL;
        echo '      ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        echo '      at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    $GLOBALS['__test_state']['assertions']++;
    if ($expected !== $actual) {
        $detail = $message !== '' ? $message . ' — ' : '';
        throw new AssertionFailed($detail . 'expected ' . export_value($expected)
            . ', got ' . export_value($actual));
    }
}

function assert_true($value, string $message = ''): void
{
    assert_same(true, $value, $message);
}

function assert_false($value, string $message = ''): void
{
    assert_same(false, $value, $message);
}

function assert_throws(callable $fn, string $class): void
{
    $GLOBALS['__test_state']['assertions']++;
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new AssertionFailed('expected ' . $class . ', got ' . get_class($e)
            . ': ' . $e->getMessage());
    }
    throw new AssertionFailed('expected ' . $class . ', nothing thrown');
}

function export_value($value): string
{
    if (is_string($value)) {
        return "'" . addcslashes($value, "'\\") . "'";
    }
    if (is_object($value)) {
        return get_class($value) . ' object';
    }
    return var_export($value, true);
}

$GLOBALS['__test_filter'] = $argv[1] ?? null;

$files = glob(__DIR__ . '/unit/*_test.php') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, 'No test files found in tests/unit/' . PHP_EOL);
    exit(1);
}

foreach ($files as $file) {
    require $file;
}

$state = $GLOBALS['__test_state'];
$total = $state['passed'] + $state['failed'];

echo PHP_EOL . $state['passed'] . ' passed, ' . $state['failed'] . ' failed'
    . ' (' . $state['assertions'] . ' assertions, ' . $total . ' tests)' . PHP_EOL;

if ($state['failed'] > 0) {
    echo 'Failed: ' . implode(', ', $state['failures']) . PHP_EOL;
    exit(1);
}

exit(0);
