<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Minimal dotenv loader.
 *
 * Deployment secrets live in the repository-root `.env` file (git-ignored) or
 * in real process environment variables. Real environment variables always
 * win, so a container/host can override a checked-out file.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value with spaces'
 *   KEY=value # trailing comment
 *   # comment line
 */

if (!function_exists('env_load')) {
    /**
     * Load KEY=value pairs from a file without overwriting existing vars.
     */
    function env_load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $value = trim(substr($line, $separator + 1));

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                if ($end !== false && $end > 0) {
                    $value = substr($value, 1, $end - 1);
                }
            } else {
                $comment = strpos($value, ' #');
                if ($comment !== false) {
                    $value = rtrim(substr($value, 0, $comment));
                }
            }

            // A real environment variable always wins over the file.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('env')) {
    /**
     * Read a configuration value, returning $default when it is not set.
     * An explicitly empty value is preserved (it is not the same as unset).
     */
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return $value === null ? $default : $value;
    }
}

if (!function_exists('env_bool')) {
    /**
     * Read a boolean configuration flag with a conservative default.
     */
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, null);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        return $default;
    }
}
