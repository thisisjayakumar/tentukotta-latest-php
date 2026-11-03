<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

// translation
if ( ! function_exists('trans')):
	function trans($phrase){
        $translate_phrase = $phrase;
	    $ci =& get_instance();
		$ci->load->helper('language');
		$active_language = $ci->language_model->get_active_language();
		$ci->lang->load('site_lang',$active_language);
		$ci->config->set_item('language', $active_language);
        if($ci->lang->line($phrase) === FALSE):
            //$ci->language_model->create_phrase($active_language,$phrase);
            $translate_phrase = ucfirst(str_replace("_", " ", $phrase));         
        else:
            $translate_phrase = $ci->lang->line($phrase,FALSE);
        endif;
	    return $translate_phrase;
    }
endif;

if (! function_exists('is_demo')):
    function is_demo()
    {
        $ci =& get_instance();
        return ($ci->config->item('is_demo') === true ) ? true : false;
    }
endif;

if (! function_exists('demo_check')):
    function demo_check()
    {
        $ci =& get_instance();
        if(is_demo()):
            $ci->session->set_flashdata('error', 'Action not allow on demo.');
            redirect($ci->agent->referrer());
        endif;
    }
endif;

// configuration helper
if (! function_exists('ovoo_config')):
	function ovoo_config($title)
    {
    	$ci =& get_instance();
        return $ci->common_model->get_config($title);
    }
endif;

// theme helper
if (! function_exists('active_theme')):
	function active_theme()
    {
    	$ci =& get_instance();
        return $ci->common_model->get_active_theme();
    }
endif;

//generate slug
if (!function_exists('str_slug')) {
    function str_slug($str)
    {
        return url_title($str, "-", true);
    }
}

/**
 * Redirect to a same-host path only; absolute or protocol-relative URLs
 * (the classic ?url=https://evil.test open redirect) fall back to base_url().
 */
if ( ! function_exists('safe_redirect')):
	function safe_redirect(?string $url, string $method = 'auto'): void
	{
		if ($url === null || $url === ''
			|| preg_match('#^(https?:)?//#i', $url)
			|| preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)
			|| str_contains($url, '\\')
			|| preg_match('/[\x00-\x1F\x7F]/', $url)
		) {
			redirect(base_url(), $method);
			return;
		}
		redirect($url, $method);
	}
endif;

// Escape a value for HTML output (text and quoted attributes).
if ( ! function_exists('e')):
	function e($value)
	{
		return html_escape($value ?? '');
	}
endif;

/**
 * True when an IP address is loopback, private, link-local, CGNAT or
 * otherwise reserved. Unparseable input is treated as unsafe.
 */
if ( ! function_exists('is_private_ip')):
	function is_private_ip(string $ip): bool
	{
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			return true;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return true;
		}

		// Carrier-grade NAT (100.64.0.0/10) is not covered by the filter flags.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$long = ip2long($ip);
			$cgnat = ip2long('100.64.0.0');
			if ($long !== false && $cgnat !== false && ($long & 0xFFC00000) === ($cgnat & 0xFFC00000)) {
				return true;
			}
		}

		return false;
	}
endif;

/**
 * True when a URL is safe to fetch server-side.
 *
 * Only http/https, no embedded credentials, no control characters, no
 * numeric/hex host tricks, and every resolved address must be public.
 * A resolver may be injected for tests; production uses DNS.
 */
if ( ! function_exists('is_safe_remote_url')):
	function is_safe_remote_url(string $url, ?callable $resolver = null): bool
	{
		if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
			return false;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}

		if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
			return false;
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}

		$host = strtolower(trim($parts['host'], '[]'));
		if ($host === '') {
			return false;
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return !is_private_ip($host);
		}

		// Reject decimal/octal/hex IPv4 encodings that curl may still resolve.
		if (preg_match('/^(0x[0-9a-f]+|[0-9]+)$/i', $host) === 1) {
			return false;
		}

		if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
			return false;
		}

		$addresses = $resolver !== null ? $resolver($host) : @gethostbynamel($host);
		if (!is_array($addresses) || $addresses === array()) {
			return false;
		}

		foreach ($addresses as $address) {
			if (!is_string($address) || is_private_ip($address)) {
				return false;
			}
		}

		return true;
	}
endif;

/**
 * Validate and store an uploaded image safely.
 *
 * - rejects non-uploads, oversize files and non-image content (getimagesize)
 * - only allows jpg/jpeg/png/webp/gif destination extensions
 * - re-encodes through GD when available, which strips embedded payloads
 *
 * @param array  $file        One entry from $_FILES
 * @param string $destination Absolute path, or path relative to FCPATH
 * @param int    $maxBytes    Default 5 MB
 */
if ( ! function_exists('safe_image_upload')):
	function safe_image_upload(array $file, string $destination, int $maxBytes = 5242880): bool
	{
		if (!isset($file['tmp_name'], $file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
			return false;
		}
		if (!is_uploaded_file($file['tmp_name'])) {
			return false;
		}
		if (filesize($file['tmp_name']) > $maxBytes) {
			return false;
		}

		$info = @getimagesize($file['tmp_name']);
		if ($info === false || empty($info['mime'])) {
			return false;
		}

		$allowed = array(
			'image/jpeg' => array('jpg', 'jpeg'),
			'image/png'  => array('png'),
			'image/webp' => array('webp'),
			'image/gif'  => array('gif'),
		);
		if (!isset($allowed[$info['mime']])) {
			return false;
		}

		$base = defined('FCPATH') ? FCPATH : '';
		$dest = (str_starts_with($destination, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $destination))
			? $destination
			: $base . ltrim($destination, '/');
		$ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
			return false;
		}

		$dir = dirname($dest);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			return false;
		}

		$gd = function_exists('imagecreatefromstring');
		if ($gd) {
			$image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
			if ($image === false) {
				return false;
			}
			$ok = false;
			if ($ext === 'png') {
				$ok = function_exists('imagepng') && imagepng($image, $dest, 6);
			} elseif ($ext === 'webp') {
				$ok = function_exists('imagewebp') && imagewebp($image, $dest, 90);
			} elseif ($ext === 'gif') {
				$ok = function_exists('imagegif') && imagegif($image, $dest);
			} else {
				$ok = function_exists('imagejpeg') && imagejpeg($image, $dest, 90);
			}
			imagedestroy($image);
			if ($ok) {
				return true;
			}
			// Fall through to the validated copy if the GD encoder is missing.
		}

		return move_uploaded_file($file['tmp_name'], $dest);
	}
endif;

/**
 * Store a WebVTT subtitle after validating type and size.
 * The uploads directory denies script execution as a second layer.
 */
if ( ! function_exists('safe_subtitle_upload')):
	function safe_subtitle_upload(array $file, string $destination, int $maxBytes = 2097152): bool
	{
		if (!isset($file['tmp_name'], $file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
			return false;
		}
		if (!is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > $maxBytes) {
			return false;
		}
		if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'vtt') {
			return false;
		}
		$head = (string) file_get_contents($file['tmp_name'], false, null, 0, 4096);
		if (stripos($head, '<?php') !== false || stripos($head, '<script') !== false) {
			return false;
		}

		$base = defined('FCPATH') ? FCPATH : '';
		$dest = (str_starts_with($destination, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $destination))
			? $destination
			: $base . ltrim($destination, '/');
		$dir = dirname($dest);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			return false;
		}
		return move_uploaded_file($file['tmp_name'], $dest);
	}
endif;

// CSRF hidden input for hand-written <form> tags (form_open() adds its own).
if ( ! function_exists('csrf_input')):
	function csrf_input()
	{
		$CI =& get_instance();
		if (config_item('csrf_protection') !== TRUE) {
			return '';
		}
		return '<input type="hidden" name="'.html_escape($CI->security->get_csrf_token_name())
			.'" value="'.html_escape($CI->security->get_csrf_hash()).'" />';
	}
endif;