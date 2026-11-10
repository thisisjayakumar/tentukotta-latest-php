<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Timing-safe comparison for shared API secrets.
 *
 * A plain `==` leaks information through response timing and can also produce
 * surprising true results through PHP's type juggling (for example "0e123"
 * compared with "0e456"). Both are avoided here.
 */
class Api_key
{
    /**
     * True only when both values are non-empty strings and identical.
     *
     * @param mixed $expected Stored secret.
     * @param mixed $supplied Client-supplied secret.
     */
    public static function verify($expected, $supplied): bool
    {
        if (!is_string($expected) || !is_string($supplied)) {
            return false;
        }

        if ($expected === '' || $supplied === '') {
            return false;
        }

        return hash_equals($expected, $supplied);
    }
}
