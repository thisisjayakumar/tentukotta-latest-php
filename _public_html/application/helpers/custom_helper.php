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