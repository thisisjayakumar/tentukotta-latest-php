<?php
/**
 * Bootstrap for the dependency-free unit test suite.
 *
 * Defines the CodeIgniter constants and the handful of framework helpers the
 * libraries under test call, without booting CodeIgniter or touching a
 * database. Nothing here is used by the application itself.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__) . '/_public_html/system/');
}
if (!defined('APPPATH')) {
    define('APPPATH', dirname(__DIR__) . '/_public_html/application/');
}
if (!defined('FCPATH')) {
    define('FCPATH', dirname(__DIR__) . '/_public_html/');
}
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

/** Absolute path to a file inside application/. */
function app_file(string $relative): string
{
    return APPPATH . ltrim($relative, '/');
}

if (!function_exists('get_instance')) {
    function get_instance()
    {
        throw new RuntimeException('get_instance() is not available in unit tests');
    }
}

if (!function_exists('base_url')) {
    function base_url($uri = '', $protocol = null): string
    {
        return 'http://localhost:8080/' . ltrim((string) $uri, '/');
    }
}

if (!function_exists('redirect')) {
    /**
     * Record the redirect target instead of emitting a header and exiting.
     */
    function redirect($uri = '', $method = 'auto', $code = null): void
    {
        $GLOBALS['__redirect_target'] = $uri;
    }
}

if (!function_exists('html_escape')) {
    function html_escape($var, $double_encode = true): string
    {
        return htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8', (bool) $double_encode);
    }
}

if (!function_exists('config_item')) {
    function config_item($item)
    {
        return null;
    }
}
