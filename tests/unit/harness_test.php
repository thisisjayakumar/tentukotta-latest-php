<?php
/**
 * Self-test for the test harness itself.
 *
 * Proves that a failing assertion is detected and reported, so a green run
 * later actually means something.
 */

test('harness: assert_same accepts equal values', function () {
    assert_same(1, 1);
    assert_same('abc', 'abc');
});

test('harness: assert_true and assert_false accept the right values', function () {
    assert_true(true);
    assert_false(false);
});

test('harness: a failing assertion throws AssertionFailed', function () {
    assert_throws(function () {
        assert_same(1, 2);
    }, AssertionFailed::class);
});

test('harness: assert_throws reports a missing exception', function () {
    assert_throws(function () {
        try {
            assert_throws(function () {
                // no exception
            }, AssertionFailed::class);
        } catch (AssertionFailed $e) {
            assert_true(str_contains($e->getMessage(), 'nothing thrown'));
            throw $e;
        }
    }, AssertionFailed::class);
});
