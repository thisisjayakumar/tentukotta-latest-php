<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Core_Controller extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // timezone
        date_default_timezone_set(ovoo_config('timezone'));
    }
}

class Admin_Core_Controller extends Core_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->_require_admin();
    }

    /**
     * Enforce admin authentication at the controller layer.
     *
     * All Admin_* controllers inherit this check, closing the class of
     * unauthenticated admin writes (SECURITY-AUDIT.md CRIT-01 / HIGH-04).
     *
     * @return void
     */
    protected function _require_admin(): void
    {
        if ($this->session->userdata('admin_is_login') != 1) {
            if ($this->input->is_ajax_request()) {
                $this->output
                    ->set_status_header(403)
                    ->set_content_type('application/json')
                    ->set_output(json_encode([
                        'status'  => 'error',
                        'message' => 'Forbidden',
                    ]))
                    ->_display();
                exit;
            }
            show_error('Forbidden', 403);
        }
    }
}

class Home_Core_Controller extends Core_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->active_theme  =   active_theme();
    }
}