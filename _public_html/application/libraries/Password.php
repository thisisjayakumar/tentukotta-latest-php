<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Password hashing helper.
 *
 * SECURITY-AUDIT.md HIGH-01/HIGH-02: the application stored passwords as
 * unsalted single-round MD5. This class centralises hashing so that:
 *
 *   - new and changed passwords use Argon2id (with a sane fallback),
 *   - existing MD5 hashes still verify exactly once,
 *   - the legacy hash is transparently upgraded after a successful login.
 *
 * Usage:
 *   Password::hash($plain)                 // store this
 *   Password::verify($plain, $stored)      // boolean
 *   Password::needs_rehash($stored)        // upgrade after verify
 *   Password::random()                     // unguessable placeholder
 */
class Password
{
    /**
     * Preferred password hashing algorithm.
     *
     * Argon2id is available in PHP 7.2+ builds compiled with Argon2. Fall
     * back to the platform default so the app still boots on hosts without
     * libargon2 instead of fataling on an undefined constant.
     *
     * @return string|int
     */
    private static function algo()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Hash a plaintext password for storage.
     */
    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * A 32-character hex string is a legacy unsalted MD5 hash. Everything
     * else is handed to password_verify() (Argon2id/bcrypt).
     */
    public static function verify(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
            return hash_equals(strtolower($stored), md5($plain));
        }

        return password_verify($plain, $stored);
    }

    /**
     * True when the stored hash should be replaced after a successful login.
     */
    public static function needs_rehash(string $stored): bool
    {
        if ($stored === '' || $stored[0] !== '$') {
            // Empty or legacy MD5 hash.
            return true;
        }

        return password_needs_rehash($stored, self::algo());
    }

    /**
     * Cryptographically random value, for accounts that must have an
     * unguessable password placeholder (social / Firebase signups).
     */
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
